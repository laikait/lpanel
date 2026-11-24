package updates

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"lpa/internal/protocol"
)

type entry struct {
	name string
	kind byte
	body string
	link string
}

func bundle(t *testing.T, entries []entry) []byte {
	t.Helper()
	var buf bytes.Buffer
	zw := gzip.NewWriter(&buf)
	tw := tar.NewWriter(zw)
	for _, e := range entries {
		h := &tar.Header{Name: e.name, Typeflag: e.kind, Mode: 0o644, Size: int64(len(e.body)), Linkname: e.link}
		if e.kind == tar.TypeDir {
			h.Mode, h.Size = 0o755, 0
		}
		if e.kind != tar.TypeReg {
			h.Size = 0
		}
		if err := tw.WriteHeader(h); err != nil {
			t.Fatal(err)
		}
		if e.kind == tar.TypeReg {
			tw.Write([]byte(e.body))
		}
	}
	tw.Close()
	zw.Close()
	return buf.Bytes()
}

func goodEntries(version string) []entry {
	top := "lpanel-" + version + "/"
	return []entry{
		{name: top, kind: tar.TypeDir},
		{name: top + "VERSION", kind: tar.TypeReg, body: version + "\n"},
		{name: top + "install.sh", kind: tar.TypeReg, body: "#!/bin/bash\n"},
		{name: top + "upgrade.sh", kind: tar.TypeReg, body: "#!/bin/bash\n"},
		{name: top + "bin/lpa", kind: tar.TypeReg, body: "ELF"},
		{name: top + "panel/laika", kind: tar.TypeReg, body: "<?php"},
	}
}

// channel is a release channel: a manifest, its signature and the bundle.
type channel struct {
	key      ed25519.PrivateKey
	manifest []byte
	sig      string
	bundle   []byte
	srv      *httptest.Server
}

func newChannel(t *testing.T, version string, entries []entry) *channel {
	t.Helper()
	_, key, _ := ed25519.GenerateKey(rand.Reader)
	c := &channel{key: key, bundle: bundle(t, entries)}
	c.publish(version, nil)
	c.srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/stable/latest.json":
			w.Write(c.manifest)
		case "/stable/latest.json.sig":
			w.Write([]byte(c.sig))
		case "/stable/lpanel.tar.gz":
			w.Write(c.bundle)
		default:
			http.NotFound(w, r)
		}
	}))
	t.Cleanup(c.srv.Close)
	return c
}

func (c *channel) publish(version string, change func(map[string]any)) {
	sum := sha256.Sum256(c.bundle)
	m := map[string]any{"product": "lpanel", "version": version, "file": "lpanel.tar.gz", "sha256": hex.EncodeToString(sum[:]), "size": len(c.bundle), "released": "2026-11-24", "notes": "Fixes."}
	if change != nil {
		change(m)
	}
	c.manifest, _ = json.Marshal(m)
	c.sig = base64.StdEncoding.EncodeToString(ed25519.Sign(c.key, append([]byte(signaturePrefix), c.manifest...)))
}

func releaseManager(t *testing.T, c *channel, installed string) (*Manager, *fakeRun) {
	t.Helper()
	run := &fakeRun{answers: map[string]answer{"systemctl is-active": {3, ""}, "systemd-run": {0, ""}}}
	m := newManager(t, run)
	m.Channel = c.srv.URL + "/stable/latest.json"
	m.KeyFile = filepath.Join(t.TempDir(), "release.pub")
	pub := c.key.Public().(ed25519.PublicKey)
	os.WriteFile(m.KeyFile, []byte("# LPanel release keys\n"+base64.StdEncoding.EncodeToString(pub)+"\n"), 0o644)
	os.MkdirAll(m.PanelDir, 0o755)
	if installed != "" {
		os.WriteFile(filepath.Join(m.PanelDir, "VERSION"), []byte(installed+"\n"), 0o644)
	}
	return m, run
}

func wantCode(t *testing.T, err error, code, contains string) {
	t.Helper()
	var pe *protocol.Error
	if !errors.As(err, &pe) || pe.Code != code || !strings.Contains(pe.Message, contains) {
		t.Fatalf("err = %v, want %s containing %q", err, code, contains)
	}
}

func TestReleaseSaysWhetherANewerOneIsOut(t *testing.T) {
	c := newChannel(t, "0.2.0", goodEntries("0.2.0"))
	m, _ := releaseManager(t, c, "0.1.0")
	r := m.Release(context.Background())
	if !r.Channel || r.Installed != "0.1.0" || r.Latest != "0.2.0" || !r.Newer || r.Notes != "Fixes." || r.Error != "" {
		t.Fatalf("%+v", r)
	}
	m2, _ := releaseManager(t, c, "0.2.0")
	if r := m2.Release(context.Background()); r.Newer {
		t.Fatalf("%+v", r)
	}
	m3 := newManager(t, &fakeRun{})
	if r := m3.Release(context.Background()); r.Channel {
		t.Fatalf("%+v", r)
	}
}

func TestUpgradeUnpacksTheCheckedReleaseAndStartsItsUpgradeScript(t *testing.T) {
	c := newChannel(t, "0.2.0", goodEntries("0.2.0"))
	m, run := releaseManager(t, c, "0.1.0")
	res, err := m.Upgrade(context.Background(), UpgradeArgs{Version: "0.2.0"})
	if err != nil {
		t.Fatal(err)
	}
	if res["target"] != "0.2.0" || res["from"] != "0.1.0" {
		t.Fatalf("%v", res)
	}
	dir := filepath.Join(m.State, "lpanel-0.2.0")
	if data, err := os.ReadFile(filepath.Join(dir, "panel", "laika")); err != nil || string(data) != "<?php" {
		t.Fatalf("unpacked: %q %v", data, err)
	}
	got := run.ran("systemd-run")
	if len(got) != 1 || !strings.HasPrefix(got[0], "systemd-run --unit=lpanel-upgrade --collect") || !strings.HasSuffix(got[0], "/usr/bin/bash "+filepath.Join(dir, "upgrade.sh")) {
		t.Fatalf("%v", got)
	}
	st := m.UpgradeStatus(context.Background())
	// The fake systemctl says no unit runs, so a "starting" upgrade is one that stopped.
	if st.Target != "0.2.0" || st.State != "failed" || st.Installed != "0.1.0" {
		t.Fatalf("%+v", st)
	}
	run.answers["systemctl is-active"] = answer{0, ""}
	if st := m.UpgradeStatus(context.Background()); st.State != "starting" || !st.Running {
		t.Fatalf("%+v", st)
	}
	// One at a time.
	_, err = m.Upgrade(context.Background(), UpgradeArgs{Version: "0.2.0"})
	wantCode(t, err, CodeBusy, "running")
}

func TestUpgradeStatusReadsWhatTheScriptWrote(t *testing.T) {
	m, _ := releaseManager(t, newChannel(t, "0.2.0", goodEntries("0.2.0")), "0.1.0")
	os.MkdirAll(m.State, 0o700)
	os.WriteFile(filepath.Join(m.State, "status.json"), []byte(`{"state":"rolled_back","target":"0.2.0","from":"0.1.0","error":"install.sh exited with 1"}`), 0o600)
	var log strings.Builder
	for i := 0; i < 60; i++ {
		log.WriteString("line\n")
	}
	log.WriteString("==> the end\n")
	os.WriteFile(filepath.Join(m.State, "upgrade.log"), []byte(log.String()), 0o600)
	st := m.UpgradeStatus(context.Background())
	if st.State != "rolled_back" || st.Error != "install.sh exited with 1" || len(st.Log) != 40 || st.Log[39] != "==> the end" {
		t.Fatalf("%+v", st)
	}
}

func TestUpgradeRefusesWhatIsNotTheSignedRelease(t *testing.T) {
	ctx := context.Background()

	t.Run("signed with another key", func(t *testing.T) {
		c := newChannel(t, "0.2.0", goodEntries("0.2.0"))
		m, run := releaseManager(t, c, "0.1.0")
		_, other, _ := ed25519.GenerateKey(rand.Reader)
		c.key = other
		c.publish("0.2.0", nil)
		_, err := m.Upgrade(ctx, UpgradeArgs{Version: "0.2.0"})
		wantCode(t, err, CodeRelease, "does not match any release key")
		if r := m.Release(ctx); r.Error == "" || r.Latest != "" {
			t.Fatalf("%+v", r)
		}
		if len(run.ran("systemd-run")) != 0 {
			t.Fatal("started")
		}
	})
	t.Run("manifest changed after signing", func(t *testing.T) {
		c := newChannel(t, "0.2.0", goodEntries("0.2.0"))
		m, _ := releaseManager(t, c, "0.1.0")
		c.manifest = bytes.Replace(c.manifest, []byte("Fixes."), []byte("Fixed"), 1)
		_, err := m.Upgrade(ctx, UpgradeArgs{Version: "0.2.0"})
		wantCode(t, err, CodeRelease, "does not match")
	})
	t.Run("bundle changed after signing", func(t *testing.T) {
		c := newChannel(t, "0.2.0", goodEntries("0.2.0"))
		m, run := releaseManager(t, c, "0.1.0")
		c.bundle = bundle(t, append(goodEntries("0.2.0"), entry{name: "lpanel-0.2.0/evil", kind: tar.TypeReg, body: "x"}))
		// Same size claimed, different bytes: the checksum catches it.
		c.publish("0.2.0", func(m map[string]any) { m["sha256"] = strings.Repeat("ab", 32) })
		_, err := m.Upgrade(ctx, UpgradeArgs{Version: "0.2.0"})
		wantCode(t, err, CodeRelease, "SHA-256 does not match")
		if _, err := os.Stat(filepath.Join(m.State, "lpanel-0.2.0.tar.gz")); err == nil {
			t.Fatal("kept")
		}
		if len(run.ran("systemd-run")) != 0 {
			t.Fatal("started")
		}
	})
	t.Run("another version than asked", func(t *testing.T) {
		c := newChannel(t, "0.3.0", goodEntries("0.3.0"))
		m, _ := releaseManager(t, c, "0.1.0")
		_, err := m.Upgrade(ctx, UpgradeArgs{Version: "0.2.0"})
		wantCode(t, err, CodeRelease, "now offers 0.3.0")
	})
	t.Run("not newer", func(t *testing.T) {
		c := newChannel(t, "0.2.0", goodEntries("0.2.0"))
		m, _ := releaseManager(t, c, "0.2.0")
		_, err := m.Upgrade(ctx, UpgradeArgs{Version: "0.2.0"})
		wantCode(t, err, CodeNotNewer, "not newer")
	})
	t.Run("bundle of another release", func(t *testing.T) {
		c := newChannel(t, "0.2.0", goodEntries("0.2.0"))
		m, _ := releaseManager(t, c, "0.1.0")
		es := goodEntries("0.2.0")
		es[1].body = "0.1.9\n"
		c.bundle = bundle(t, es)
		c.publish("0.2.0", nil)
		_, err := m.Upgrade(ctx, UpgradeArgs{Version: "0.2.0"})
		wantCode(t, err, CodeRelease, "not release 0.2.0")
	})
	t.Run("plain http elsewhere", func(t *testing.T) {
		c := newChannel(t, "0.2.0", goodEntries("0.2.0"))
		m, _ := releaseManager(t, c, "0.1.0")
		m.Channel = "http://updates.example.com/stable/latest.json"
		_, err := m.Upgrade(ctx, UpgradeArgs{Version: "0.2.0"})
		wantCode(t, err, CodeRelease, "HTTPS only")
	})
	t.Run("no channel", func(t *testing.T) {
		m := newManager(t, &fakeRun{})
		_, err := m.Upgrade(ctx, UpgradeArgs{Version: "0.2.0"})
		wantCode(t, err, CodeNoChannel, "no update channel")
	})
	t.Run("bad version", func(t *testing.T) {
		m := newManager(t, &fakeRun{})
		_, err := m.Upgrade(ctx, UpgradeArgs{Version: "../0.2.0"})
		wantCode(t, err, protocol.CodeBadArgs, "not a release")
	})
}

func TestUnpackRefusesWhatCouldLandOutsideItsFolder(t *testing.T) {
	cases := map[string]entry{
		"link":        {name: "lpanel-0.2.0/panel/evil", kind: tar.TypeSymlink, link: "/etc"},
		"hard link":   {name: "lpanel-0.2.0/panel/evil", kind: tar.TypeLink, link: "lpanel-0.2.0/VERSION"},
		"dot dot":     {name: "lpanel-0.2.0/../../etc/cron.d/x", kind: tar.TypeReg, body: "x"},
		"absolute":    {name: "/etc/cron.d/x", kind: tar.TypeReg, body: "x"},
		"other top":   {name: "lpanel-0.3.0/x", kind: tar.TypeReg, body: "x"},
		"device":      {name: "lpanel-0.2.0/dev", kind: tar.TypeChar},
		"named twice": {name: "lpanel-0.2.0/install.sh", kind: tar.TypeReg, body: "again"},
	}
	for name, bad := range cases {
		t.Run(name, func(t *testing.T) {
			c := newChannel(t, "0.2.0", append(goodEntries("0.2.0"), bad))
			m, run := releaseManager(t, c, "0.1.0")
			_, err := m.Upgrade(context.Background(), UpgradeArgs{Version: "0.2.0"})
			wantCode(t, err, CodeRelease, "")
			if _, err := os.Stat(filepath.Join(m.State, "lpanel-0.2.0")); err == nil {
				t.Fatal("unpacked")
			}
			if len(run.ran("systemd-run")) != 0 {
				t.Fatal("started")
			}
		})
	}
	t.Run("missing installer", func(t *testing.T) {
		es := goodEntries("0.2.0")
		c := newChannel(t, "0.2.0", append(es[:2:2], es[3:]...))
		m, _ := releaseManager(t, c, "0.1.0")
		_, err := m.Upgrade(context.Background(), UpgradeArgs{Version: "0.2.0"})
		wantCode(t, err, CodeRelease, "no install.sh")
	})
}
