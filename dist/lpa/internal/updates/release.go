package updates

import (
	"archive/tar"
	"compress/gzip"
	"context"
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"path"
	"path/filepath"
	"strings"
	"time"

	"lpa/internal/protocol"
)

// A release of LPanel is announced by a manifest (JSON, at the channel's
// address) and a signature of it (the same address with ".sig"): Ed25519,
// base64, over "lpanel-release-v1\n" followed by the manifest's bytes, made
// with a key whose public half is in KeyFile. The manifest names the bundle
// (dist/installer/bundle.sh's .tar.gz) and its SHA-256, so the signature
// covers the bundle too. Nothing in a manifest is trusted before its
// signature is checked; nothing in a bundle is run before its checksum is.
const signaturePrefix = "lpanel-release-v1\n"

// upgradeUnit is the systemd unit the upgrade runs in: a unit of its own, so
// that the installer can restart the agent without stopping itself.
const upgradeUnit = "lpanel-upgrade"

// Manifest is a release as its channel announces it.
type Manifest struct {
	Product  string `json:"product"`
	Version  string `json:"version"`
	File     string `json:"file"`
	SHA256   string `json:"sha256"`
	Size     int64  `json:"size"`
	Released string `json:"released"`
	Notes    string `json:"notes"`
}

// Release is what the panel is told about LPanel's own updates.
type Release struct {
	// Channel is false when this server has no update channel set.
	Channel   bool   `json:"channel"`
	Installed string `json:"installed"`
	Latest    string `json:"latest"`
	Released  string `json:"released"`
	Notes     string `json:"notes"`
	// Newer is true when Latest is a later release than Installed.
	Newer bool `json:"newer"`
	// Error says why the channel could not be read (or its signature was wrong).
	Error string `json:"error"`
}

// Release reads the channel's manifest.
func (m *Manager) Release(ctx context.Context) Release {
	r := Release{Channel: m.Channel != "", Installed: m.Installed()}
	if !r.Channel {
		return r
	}
	man, err := m.manifest(ctx)
	if err != nil {
		r.Error = err.Error()
		return r
	}
	r.Latest, r.Released, r.Notes = man.Version, man.Released, man.Notes
	r.Newer = ValidVersion(r.Installed) && CompareVersions(man.Version, r.Installed) > 0
	return r
}

// Installed is the release the panel is now (its VERSION file).
func (m *Manager) Installed() string {
	data, err := os.ReadFile(filepath.Join(m.PanelDir, "VERSION"))
	if err != nil {
		return ""
	}
	v := strings.TrimSpace(string(data))
	if !ValidVersion(v) {
		return ""
	}
	return v
}

// UpgradeArgs names the release to install: the one the channel offers, said
// again so that a channel that moved on meanwhile is not installed unseen.
type UpgradeArgs struct {
	Version string `json:"version"`
}

// Upgrade downloads the release, checks it, unpacks it and starts its
// upgrade.sh in a unit of its own: that backs up the panel, its database and
// the agent, runs install.sh, and puts the backup back if install.sh fails.
// It answers once the upgrade has started; UpgradeStatus follows it.
func (m *Manager) Upgrade(ctx context.Context, a UpgradeArgs) (map[string]string, error) {
	if !ValidVersion(a.Version) {
		return nil, protocol.Fail(protocol.CodeBadArgs, "\""+a.Version+"\" is not a release")
	}
	if m.Channel == "" {
		return nil, protocol.Fail(CodeNoChannel, "this server has no update channel (lpa.json updates.url)")
	}
	if m.upgradeRunning(ctx) {
		return nil, protocol.Fail(CodeBusy, "an upgrade is running already")
	}
	man, err := m.manifest(ctx)
	if err != nil {
		return nil, err
	}
	if man.Version != a.Version {
		return nil, protocol.Fail(CodeRelease, "the channel now offers "+man.Version+", not "+a.Version)
	}
	from := m.Installed()
	if from != "" && CompareVersions(man.Version, from) <= 0 {
		return nil, protocol.Fail(CodeNotNewer, man.Version+" is not newer than "+from+", which is installed")
	}
	if err := os.MkdirAll(m.State, 0o700); err != nil {
		return nil, err
	}
	archive, err := m.download(ctx, man)
	if err != nil {
		return nil, err
	}
	dir, err := m.unpack(archive, man.Version)
	if err != nil {
		return nil, err
	}
	m.tidy(man.Version)
	st := Status{State: "starting", Target: man.Version, From: from, Started: m.now().UTC().Format(time.RFC3339)}
	if err := m.writeStatus(st); err != nil {
		return nil, err
	}
	code, out, err := m.Run.Run(ctx, "systemd-run", "--unit="+upgradeUnit, "--collect",
		"--description=LPanel: upgrade to "+man.Version, "/usr/bin/bash", filepath.Join(dir, "upgrade.sh"))
	if err == nil && code != 0 {
		err = protocol.Fail(CodeRelease, "the upgrade could not be started: "+tail(out))
	}
	if err != nil {
		st.State, st.Error, st.Finished = "failed", err.Error(), m.now().UTC().Format(time.RFC3339)
		m.writeStatus(st)
		return nil, err
	}
	if m.Log != nil {
		m.Log.Info("upgrade started", "from", from, "to", man.Version)
	}
	return map[string]string{"target": man.Version, "from": from, "unit": upgradeUnit}, nil
}

// Status is where the upgrade is: upgrade.sh writes it as it goes.
type Status struct {
	// State is starting, running, done, rolled_back (install.sh failed and the
	// backup was put back) or failed (and the backup could not be put back, or
	// the upgrade stopped without saying how it went).
	State    string `json:"state"`
	Target   string `json:"target"`
	From     string `json:"from"`
	Started  string `json:"started"`
	Finished string `json:"finished"`
	Error    string `json:"error"`
}

// UpgradeStatus is the last upgrade's status, with the end of its log and the
// release installed now.
type UpgradeStatus struct {
	Status
	Running   bool     `json:"running"`
	Installed string   `json:"installed"`
	Log       []string `json:"log"`
}

// UpgradeStatus reads how the last upgrade went.
func (m *Manager) UpgradeStatus(ctx context.Context) UpgradeStatus {
	res := UpgradeStatus{Installed: m.Installed(), Log: []string{}}
	if data, err := os.ReadFile(filepath.Join(m.State, "status.json")); err == nil {
		json.Unmarshal(data, &res.Status)
	}
	res.Running = m.upgradeRunning(ctx)
	if !res.Running && (res.State == "starting" || res.State == "running") {
		res.State, res.Error = "failed", "the upgrade stopped without saying how it went; see "+filepath.Join(m.State, "upgrade.log")
	}
	if data, err := os.ReadFile(filepath.Join(m.State, "upgrade.log")); err == nil {
		lines := strings.Split(strings.TrimRight(string(data), "\n"), "\n")
		if len(lines) > 40 {
			lines = lines[len(lines)-40:]
		}
		for _, l := range lines {
			if len(l) > 300 {
				l = l[:300]
			}
			res.Log = append(res.Log, l)
		}
	}
	return res
}

func (m *Manager) upgradeRunning(ctx context.Context) bool {
	code, _, err := m.Run.Run(ctx, "systemctl", "is-active", "--quiet", upgradeUnit+".service")
	return err == nil && code == 0
}

func (m *Manager) writeStatus(st Status) error {
	data, _ := json.Marshal(st)
	tmp := filepath.Join(m.State, ".status.json.tmp")
	if err := os.WriteFile(tmp, append(data, '\n'), 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, filepath.Join(m.State, "status.json"))
}

// manifest fetches the channel's manifest and checks its signature.
func (m *Manager) manifest(ctx context.Context) (Manifest, error) {
	base, err := m.allowed(m.Channel)
	if err != nil {
		return Manifest{}, err
	}
	body, err := m.fetch(ctx, base.String(), 64<<10)
	if err != nil {
		return Manifest{}, err
	}
	sig, err := m.fetch(ctx, base.String()+".sig", 1<<10)
	if err != nil {
		return Manifest{}, err
	}
	if err := m.verify(body, sig); err != nil {
		return Manifest{}, err
	}
	var man Manifest
	if err := json.Unmarshal(body, &man); err != nil {
		return Manifest{}, protocol.Fail(CodeRelease, "the manifest is not JSON: "+err.Error())
	}
	switch {
	case man.Product != "lpanel":
		return Manifest{}, protocol.Fail(CodeRelease, "the manifest is not for LPanel")
	case !ValidVersion(man.Version):
		return Manifest{}, protocol.Fail(CodeRelease, "the manifest names no release")
	case len(man.SHA256) != 64 || strings.Trim(strings.ToLower(man.SHA256), "0123456789abcdef") != "":
		return Manifest{}, protocol.Fail(CodeRelease, "the manifest has no SHA-256 for its bundle")
	case man.Size <= 0 || man.Size > m.MaxBundle:
		return Manifest{}, protocol.Fail(CodeRelease, "the manifest's bundle size is missing or too large")
	case man.File == "":
		return Manifest{}, protocol.Fail(CodeRelease, "the manifest names no bundle")
	}
	man.SHA256 = strings.ToLower(man.SHA256)
	if len(man.Notes) > 4000 {
		man.Notes = man.Notes[:4000]
	}
	return man, nil
}

// verify checks the manifest's signature against every key in KeyFile.
func (m *Manager) verify(body, sig []byte) error {
	raw, err := base64.StdEncoding.DecodeString(strings.TrimSpace(string(sig)))
	if err != nil || len(raw) != ed25519.SignatureSize {
		return protocol.Fail(CodeRelease, "the manifest's signature is not an Ed25519 signature")
	}
	keys, err := os.ReadFile(m.KeyFile)
	if err != nil {
		return protocol.Fail(CodeRelease, "no release key to check the manifest with ("+m.KeyFile+")")
	}
	msg := append([]byte(signaturePrefix), body...)
	for _, line := range strings.Split(string(keys), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		pub, err := base64.StdEncoding.DecodeString(line)
		if err != nil || len(pub) != ed25519.PublicKeySize {
			continue
		}
		if ed25519.Verify(ed25519.PublicKey(pub), msg, raw) {
			return nil
		}
	}
	return protocol.Fail(CodeRelease, "the manifest's signature does not match any release key")
}

// allowed parses an address the agent may fetch from: HTTPS, or plain HTTP
// to this machine (a test channel). A signature makes the content
// trustworthy, not the address; this only keeps the agent off plain HTTP
// on the network.
func (m *Manager) allowed(raw string) (*url.URL, error) {
	u, err := url.Parse(raw)
	if err != nil || u.Host == "" || u.User != nil {
		return nil, protocol.Fail(CodeRelease, "\""+raw+"\" is not an address to fetch releases from")
	}
	switch u.Scheme {
	case "https":
		return u, nil
	case "http":
		host := u.Hostname()
		if ip := net.ParseIP(host); host == "localhost" || (ip != nil && ip.IsLoopback()) {
			return u, nil
		}
	}
	return nil, protocol.Fail(CodeRelease, "releases are fetched over HTTPS only, not from "+raw)
}

func (m *Manager) fetch(ctx context.Context, address string, limit int64) ([]byte, error) {
	resp, err := m.get(ctx, address)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	data, err := io.ReadAll(io.LimitReader(resp.Body, limit+1))
	if err != nil {
		return nil, protocol.Fail(CodeRelease, "reading "+address+": "+err.Error())
	}
	if int64(len(data)) > limit {
		return nil, protocol.Fail(CodeRelease, address+" is larger than expected")
	}
	return data, nil
}

func (m *Manager) get(ctx context.Context, address string) (*http.Response, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, address, nil)
	if err != nil {
		return nil, protocol.Fail(CodeRelease, err.Error())
	}
	req.Header.Set("User-Agent", "lpa-updates")
	resp, err := m.HTTP.Do(req)
	if err != nil {
		return nil, protocol.Fail(CodeRelease, "the update channel did not answer: "+err.Error())
	}
	if resp.StatusCode != http.StatusOK {
		resp.Body.Close()
		return nil, protocol.Fail(CodeRelease, fmt.Sprintf("%s answered %d", address, resp.StatusCode))
	}
	return resp, nil
}

// download fetches the manifest's bundle next to the manifest and checks its
// size and checksum; a file that does not match is removed.
func (m *Manager) download(ctx context.Context, man Manifest) (string, error) {
	base, _ := m.allowed(m.Channel)
	ref, err := url.Parse(man.File)
	if err != nil {
		return "", protocol.Fail(CodeRelease, "the manifest's bundle address is not an address")
	}
	src, err := m.allowed(base.ResolveReference(ref).String())
	if err != nil {
		return "", err
	}
	resp, err := m.get(ctx, src.String())
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	dst := filepath.Join(m.State, "lpanel-"+man.Version+".tar.gz")
	part := dst + ".part"
	f, err := os.OpenFile(part, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0o600)
	if err != nil {
		return "", err
	}
	h := sha256.New()
	n, err := io.Copy(io.MultiWriter(f, h), io.LimitReader(resp.Body, man.Size+1))
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	switch {
	case err != nil:
		err = protocol.Fail(CodeRelease, "downloading the release: "+err.Error())
	case n != man.Size:
		err = protocol.Fail(CodeRelease, fmt.Sprintf("the release is %d bytes, the manifest says %d", n, man.Size))
	case hex.EncodeToString(h.Sum(nil)) != man.SHA256:
		err = protocol.Fail(CodeRelease, "the release's SHA-256 does not match the manifest's")
	}
	if err != nil {
		os.Remove(part)
		return "", err
	}
	return dst, os.Rename(part, dst)
}

// Limits on what a bundle may unpack to.
const (
	maxEntries  = 200000
	maxUnpacked = 4 << 30
)

// unpack extracts a bundle into State/lpanel-<version>: only folders and
// plain files, all inside the one folder the bundle is made of; links, devices,
// absolute names and ".." are refused. The bundle must say it is the release
// the manifest says, and hold the installer and upgrade.sh.
func (m *Manager) unpack(archive, version string) (string, error) {
	top := "lpanel-" + version
	dir := filepath.Join(m.State, top)
	stage := dir + ".new"
	os.RemoveAll(stage)
	if err := os.Mkdir(stage, 0o700); err != nil {
		return "", err
	}
	fail := func(err error) (string, error) {
		os.RemoveAll(stage)
		return "", err
	}
	f, err := os.Open(archive)
	if err != nil {
		return fail(err)
	}
	defer f.Close()
	zr, err := gzip.NewReader(f)
	if err != nil {
		return fail(protocol.Fail(CodeRelease, "the release is not a .tar.gz"))
	}
	tr := tar.NewReader(zr)
	var entries int
	var total int64
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return fail(protocol.Fail(CodeRelease, "the release cannot be read: "+err.Error()))
		}
		if entries++; entries > maxEntries {
			return fail(protocol.Fail(CodeRelease, "the release holds too many files"))
		}
		name := path.Clean(h.Name)
		if name == top {
			continue
		}
		rel, ok := strings.CutPrefix(name, top+"/")
		if !ok || strings.HasPrefix(h.Name, "/") || rel == "" || strings.Contains("/"+rel+"/", "/../") {
			return fail(protocol.Fail(CodeRelease, "the release has a file outside its folder: "+h.Name))
		}
		target := filepath.Join(stage, filepath.FromSlash(rel))
		switch h.Typeflag {
		case tar.TypeDir:
			if err := os.MkdirAll(target, 0o755); err != nil {
				return fail(err)
			}
			os.Chmod(target, 0o755) // the agent's umask is 0077
		case tar.TypeReg:
			if total += h.Size; total > maxUnpacked {
				return fail(protocol.Fail(CodeRelease, "the release unpacks to too much"))
			}
			if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
				return fail(err)
			}
			mode := os.FileMode(0o644)
			if h.Mode&0o111 != 0 {
				mode = 0o755
			}
			out, err := os.OpenFile(target, os.O_CREATE|os.O_EXCL|os.O_WRONLY, mode)
			if err != nil {
				return fail(protocol.Fail(CodeRelease, "the release names a file twice, or cannot be written: "+h.Name))
			}
			_, err = io.Copy(out, io.LimitReader(tr, h.Size))
			if cerr := out.Close(); err == nil {
				err = cerr
			}
			if err == nil {
				err = os.Chmod(target, mode)
			}
			if err != nil {
				return fail(err)
			}
		default:
			return fail(protocol.Fail(CodeRelease, "the release holds something that is not a file or a folder: "+h.Name))
		}
	}
	got, err := os.ReadFile(filepath.Join(stage, "VERSION"))
	if err != nil || strings.TrimSpace(string(got)) != version {
		return fail(protocol.Fail(CodeRelease, "the bundle is not release "+version))
	}
	for _, need := range []string{"install.sh", "upgrade.sh", "bin/lpa", "panel/laika"} {
		if st, err := os.Stat(filepath.Join(stage, need)); err != nil || !st.Mode().IsRegular() {
			return fail(protocol.Fail(CodeRelease, "the bundle has no "+need))
		}
	}
	os.Chmod(stage, 0o755)
	os.RemoveAll(dir)
	if err := os.Rename(stage, dir); err != nil {
		return fail(err)
	}
	return dir, nil
}

// tidy removes other releases' downloads and folders.
func (m *Manager) tidy(keep string) {
	entries, _ := os.ReadDir(m.State)
	for _, e := range entries {
		n := e.Name()
		if strings.HasPrefix(n, "lpanel-") && n != "lpanel-"+keep && n != "lpanel-"+keep+".tar.gz" {
			os.RemoveAll(filepath.Join(m.State, n))
		}
	}
}
