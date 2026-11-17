// Package mail looks after each account's email: its mail domains, their
// mailboxes, forwarders, catch-alls and autoresponders, and their DKIM keys.
//
// Exim receives and sends mail; Dovecot keeps mailboxes (IMAP, POP3, LMTP)
// and checks every login, Exim's included. Both run from configurations the
// installer writes once. The agent writes only their data, one file a
// domain, which both read on every lookup, so no change needs a reload:
//
//	/etc/exim/lpanel/owners/<domain>      the account the domain belongs to
//	/etc/exim/lpanel/local/<domain>       there when the domain's mail is delivered here
//	/etc/exim/lpanel/mailboxes/<domain>   its mailboxes' local parts
//	/etc/exim/lpanel/forwards/<domain>    its forwarders, and "*" for the catch-all
//	/etc/exim/lpanel/dkim/<domain>        its DKIM private key
//	/etc/dovecot/lpanel/passwd.d/<domain> its mailboxes' logins, homes and quotas
//	/etc/dovecot/lpanel/sieve/<domain>/   autoresponders, as Sieve scripts
//
// Exim finds a domain's files with dsearch, which only ever answers with a
// name that is in the folder; so a name from a message never becomes part
// of a path by itself. Mail is kept in the account's own home
// (~/mail/<domain>/<local>/Maildir), written by Dovecot as the account's
// user, so it counts against the account's disk space.
//
// The panel sends an account's whole mail state at once (Apply). Apply never
// deletes mail: a mailbox's mail goes only when the panel asks (Purge), so a
// lost row in the panel cannot cost anyone their mail.
package mail

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/rsa"
	"crypto/x509"
	"encoding/base64"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"log/slog"
	"net"
	"os"
	"os/user"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"lpa/internal/protocol"
	"lpa/internal/sysexec"
)

// Error codes.
const (
	CodeOff      = "mail_off"       // the agent is configured without mail
	CodeNotOwner = "mail_not_owner" // the domain's mail belongs to another account
	CodeInUse    = "mail_in_use"    // a mailbox or domain to purge is still set up
	CodeSieve    = "mail_sieve"     // Pigeonhole refused an autoresponder
)

// DKIMSelector is the selector every domain's key is published under
// (default._domainkey.example.com).
const DKIMSelector = "default"

// ApplyArgs is an account's whole mail state.
type ApplyArgs struct {
	Username string `json:"username"`
	// Suspended accounts keep receiving mail; their mailboxes cannot log
	// in, and so cannot send.
	Suspended bool     `json:"suspended"`
	Domains   []Domain `json:"domains"`
	// Limit caps how many recipients the account's mail may reach in an hour,
	// from its mailboxes and its sites alike (0: no cap).
	Limit int `json:"limit"`
	// Spam is how the account's mailboxes file what the spam filter scores.
	Spam Spam `json:"spam"`
}

// Spam is an account's spam settings. Scores are tenths, as Exim gives them
// (60 is a score of 6.0).
type Spam struct {
	// Junk files mail that scores this much or more in the Junk folder;
	// Delete throws it away (0: neither).
	Junk   int `json:"junk"`
	Delete int `json:"delete"`
	// Allow lists senders never filed as spam, Block senders whose mail is
	// thrown away on arrival: addresses, or "@domain" for a whole domain.
	Allow []string `json:"allow"`
	Block []string `json:"block"`
}

// any reports whether the settings ask a mailbox's Sieve script for anything.
func (s Spam) any() bool {
	return s.Junk > 0 || s.Delete > 0 || len(s.Block) > 0
}

// Filter is one of a mailbox's own rules, run in order on arrival.
type Filter struct {
	Field  string `json:"field"`  // from, to (To and Cc), subject
	Match  string `json:"match"`  // contains, is, begins, ends
	Value  string `json:"value"`  // what it looks for, ignoring case
	Action string `json:"action"` // folder, forward (a copy), discard
	Target string `json:"target"` // the folder, or the address
	Stop   bool   `json:"stop"`   // later filters do not run
}

// Domain is one mail domain of the account.
type Domain struct {
	Name string `json:"name"`
	// Cert is the certificate of the domain's site, by its id in the
	// certificate store; when it covers mail.<domain>, mail is served under
	// that name with it too.
	Cert string `json:"cert,omitempty"`
	// Local is true when the domain's mail is delivered on this server;
	// false when its MX is elsewhere (mail to it is sent there, though its
	// mailboxes may still log in and send).
	Local bool `json:"local"`
	// Catchall is what becomes of mail to an address the domain does not
	// have: Refuse (or empty), Discard, or an address to forward it to.
	Catchall   string      `json:"catchall"`
	Mailboxes  []Mailbox   `json:"mailboxes"`
	Forwarders []Forwarder `json:"forwarders"`
}

// Mailbox is a mailbox someone logs in to.
type Mailbox struct {
	Local string `json:"local"`
	// Hash is the password's bcrypt hash; the password itself never
	// reaches the agent.
	Hash string `json:"hash"`
	// QuotaMB caps the mailbox; 0 is no cap of its own (the account's disk
	// space still applies).
	QuotaMB       int            `json:"quota_mb"`
	Autoresponder *Autoresponder `json:"autoresponder,omitempty"`
	Filters       []Filter       `json:"filters,omitempty"`
}

// Forwarder sends mail for one address on to others.
type Forwarder struct {
	Local string   `json:"local"`
	To    []string `json:"to"`
}

// Autoresponder answers mail to a mailbox, once per sender in Days days.
type Autoresponder struct {
	Subject  string `json:"subject"`
	Body     string `json:"body"`
	FromName string `json:"from_name"`
	Days     int    `json:"days"`
	// Start and End (YYYY-MM-DD, either may be empty) bound when it answers.
	Start string `json:"start"`
	End   string `json:"end"`
}

// ApplyResult says what happened.
type ApplyResult struct {
	Domains int `json:"domains"`
	Changed int `json:"changed"` // files written or removed
	// DKIM is each domain's public key as its TXT record at
	// default._domainkey.<domain> reads.
	DKIM map[string]string `json:"dkim"`
}

// PurgeArgs names mail to delete: one mailbox's, or (Local empty) all of a
// domain's.
type PurgeArgs struct {
	Username string `json:"username"`
	Domain   string `json:"domain"`
	Local    string `json:"local"`
}

// UsageArgs names an account.
type UsageArgs struct {
	Username string `json:"username"`
}

// Usage is the space each of an account's mailboxes takes, by address.
type Usage struct {
	Mailboxes map[string]int64 `json:"mailboxes"`
}

// Manager carries out the mail operations.
type Manager struct {
	// Enabled turns the mail operations on (lpa.json "mail": "on").
	Enabled bool
	// EximDir and DovecotDir hold the data the agent writes; TLSDir the copy
	// of the hostname certificate both servers use.
	EximDir, DovecotDir, TLSDir string
	// RspamdDir is Rspamd's local.d; ClamDB ClamAV's signatures; LogDir
	// Exim's logs (delivery tracking reads main.log and its rotations).
	RspamdDir, ClamDB, LogDir string
	// HostCert and HostKey are the hostname certificate (the panel's).
	HostCert, HostKey string
	Run               sysexec.Runner
	Owner             func(name string) (uid, gid int, home string, err error)
	Log               *slog.Logger
	SELinux           bool
	// Group finds a group's gid ("exim", "dovecot"); Chown sets a file's
	// owner, ChownIn one inside a root. Tests replace them.
	Group   func(name string) (int, error)
	Chown   func(path string, uid, gid int) error
	ChownIn func(root *os.Root, name string, uid, gid int) error
	// KeyBits is the size of new DKIM keys (2048).
	KeyBits int
	// Certs finds one of an account's stored certificates (certs.Store.Files),
	// for a mail domain's own certificate; nil serves the hostname's alone.
	Certs func(user, id string) (crt, key string, err error)
	// Now is the time (tests set it).
	Now func() time.Time
	// Resolver, when set, is the DNS server ("127.0.0.1:53") the checks ask
	// instead of the system's; Lookups and LocalIPs replace the lookups and
	// the server's addresses in tests.
	Resolver string
	Lookups  *lookups
	LocalIPs func() []string

	mu sync.Mutex
}

// Defaults fills in what main and tests leave empty.
func (m *Manager) Defaults() {
	set := func(p *string, v string) {
		if *p == "" {
			*p = v
		}
	}
	set(&m.EximDir, "/etc/exim/lpanel")
	set(&m.DovecotDir, "/etc/dovecot/lpanel")
	set(&m.TLSDir, "/etc/pki/lpanel-mail")
	set(&m.RspamdDir, "/etc/rspamd/local.d")
	set(&m.ClamDB, "/var/lib/clamav")
	set(&m.LogDir, "/var/log/exim")
	set(&m.HostCert, "/etc/lpanel/tls/panel.crt")
	set(&m.HostKey, "/etc/lpanel/tls/panel.key")
	if m.Log == nil {
		m.Log = slog.New(slog.NewTextHandler(io.Discard, nil))
	}
	if m.Group == nil {
		m.Group = func(name string) (int, error) {
			g, err := user.LookupGroup(name)
			if err != nil {
				return 0, err
			}
			return strconv.Atoi(g.Gid)
		}
	}
	if m.Chown == nil {
		m.Chown = os.Lchown
	}
	if m.ChownIn == nil {
		m.ChownIn = func(root *os.Root, name string, uid, gid int) error { return root.Lchown(name, uid, gid) }
	}
	if m.KeyBits == 0 {
		m.KeyBits = 2048
	}
}

func (m *Manager) eximPath(kind, domain string) string {
	return filepath.Join(m.EximDir, kind, domain)
}

func (m *Manager) passwdPath(domain string) string {
	return filepath.Join(m.DovecotDir, "passwd.d", domain)
}

func (m *Manager) sieveDir(domain string) string {
	return filepath.Join(m.DovecotDir, "sieve", domain)
}

// groups are the gids Exim's and Dovecot's files are readable by.
func (m *Manager) groups() (exim, dovecot int, err error) {
	if exim, err = m.Group("exim"); err != nil {
		return 0, 0, protocol.Fail(CodeOff, "Exim is not installed (no exim group)")
	}
	if dovecot, err = m.Group("dovecot"); err != nil {
		return 0, 0, protocol.Fail(CodeOff, "Dovecot is not installed (no dovecot group)")
	}
	return exim, dovecot, nil
}

// prepare makes the data folders, readable by each server's group only.
func (m *Manager) prepare(exim, dovecot int) error {
	for _, d := range []struct {
		dir string
		gid int
	}{
		{m.EximDir, exim}, {filepath.Join(m.EximDir, "owners"), exim}, {filepath.Join(m.EximDir, "local"), exim},
		{filepath.Join(m.EximDir, "mailboxes"), exim}, {filepath.Join(m.EximDir, "forwards"), exim}, {filepath.Join(m.EximDir, "dkim"), exim},
		{filepath.Join(m.EximDir, "accounts"), exim},
		{m.DovecotDir, dovecot}, {filepath.Join(m.DovecotDir, "passwd.d"), dovecot},
	} {
		if err := m.dir(d.dir, 0o750, d.gid); err != nil {
			return err
		}
	}
	// Each domain's Sieve folder is readable by its account's group (the
	// delivery runs as the account's user), so the folder above is open.
	return m.dir(filepath.Join(m.DovecotDir, "sieve"), 0o755, 0)
}

func (m *Manager) dir(dir string, perm os.FileMode, gid int) error {
	if err := os.MkdirAll(dir, perm); err != nil {
		return err
	}
	info, err := os.Lstat(dir)
	if err != nil {
		return err
	}
	if !info.IsDir() {
		return fmt.Errorf("%s is not a folder", dir)
	}
	if err := os.Chmod(dir, perm); err != nil {
		return err
	}
	return m.Chown(dir, 0, gid)
}

// writeFile replaces path with data, owned by root and gid, unless it holds
// data already. The new file gets its owner before it takes the old one's
// place, so a server never finds it unreadable.
func (m *Manager) writeFile(path string, data []byte, perm os.FileMode, gid int) (bool, error) {
	if old, err := os.ReadFile(path); err == nil && bytes.Equal(old, data) {
		return false, nil
	}
	tmp, err := os.CreateTemp(filepath.Dir(path), "."+filepath.Base(path)+".tmp-*")
	if err != nil {
		return false, err
	}
	name := tmp.Name()
	defer os.Remove(name)
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		return false, err
	}
	if err := tmp.Sync(); err != nil {
		tmp.Close()
		return false, err
	}
	if err := tmp.Close(); err != nil {
		return false, err
	}
	if err := os.Chmod(name, perm); err != nil {
		return false, err
	}
	if err := m.Chown(name, 0, gid); err != nil {
		return false, err
	}
	return true, os.Rename(name, path)
}

// remove deletes path; it reports a change for count().
func remove(path string) (bool, error) {
	return true, os.RemoveAll(path)
}

func exists(path string) bool {
	_, err := os.Lstat(path)
	return err == nil
}

// ownerOf reads which account a domain's mail belongs to ("" for none).
func (m *Manager) ownerOf(domain string) string {
	data, err := os.ReadFile(m.eximPath("owners", domain))
	if err != nil {
		return ""
	}
	for _, line := range strings.Split(string(data), "\n") {
		if v, ok := strings.CutPrefix(line, "owner: "); ok {
			return strings.TrimSpace(v)
		}
	}
	return ""
}

// domainsOf lists the mail domains an account has on the server now.
func (m *Manager) domainsOf(username string) ([]string, error) {
	entries, err := os.ReadDir(filepath.Join(m.EximDir, "owners"))
	if errors.Is(err, fs.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var out []string
	for _, e := range entries {
		if e.Type().IsRegular() && !strings.HasPrefix(e.Name(), ".") && m.ownerOf(e.Name()) == username {
			out = append(out, e.Name())
		}
	}
	sort.Strings(out)
	return out, nil
}

// Apply makes the account's mail on the server match the state: every
// domain's files, its DKIM key (made when missing), and the autoresponders.
// Domains the account no longer has lose their files; their mail stays.
func (m *Manager) Apply(ctx context.Context, a ApplyArgs) (ApplyResult, error) {
	if !m.Enabled {
		return ApplyResult{}, protocol.Fail(CodeOff, "the agent is configured without mail")
	}
	if err := a.check(); err != nil {
		return ApplyResult{}, err
	}
	uid, gid, home, err := m.Owner(a.Username)
	if err != nil {
		return ApplyResult{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	eximGID, dovecotGID, err := m.groups()
	if err != nil {
		return ApplyResult{}, err
	}
	if err := m.prepare(eximGID, dovecotGID); err != nil {
		return ApplyResult{}, err
	}
	for _, d := range a.Domains {
		if owner := m.ownerOf(d.Name); owner != "" && owner != a.Username {
			return ApplyResult{}, protocol.Fail(CodeNotOwner, "the mail of "+d.Name+" belongs to another account")
		}
	}

	res := ApplyResult{Domains: len(a.Domains), DKIM: map[string]string{}}
	count := func(changed bool, err error) error {
		if changed {
			res.Changed++
		}
		return err
	}
	wanted := map[string]bool{}
	boxes := 0
	for _, d := range a.Domains {
		wanted[d.Name] = true
		boxes += len(d.Mailboxes)
		// Dovecot learns of a mailbox before Exim accepts mail for it.
		if err := count(m.writeFile(m.passwdPath(d.Name), passwdFile(d, a.Username, uid, gid, home, a.Suspended), 0o640, dovecotGID)); err != nil {
			return res, err
		}
		if err := count(m.applySSO(d, a.Suspended, dovecotGID)); err != nil {
			return res, err
		}
		if err := m.applySieve(ctx, d, a.Spam, gid, &res); err != nil {
			return res, err
		}
		if err := count(m.writeFile(m.eximPath("owners", d.Name), ownerFile(d, a.Username, a.Suspended), 0o640, eximGID)); err != nil {
			return res, err
		}
		if err := count(m.writeFile(m.eximPath("mailboxes", d.Name), mailboxesFile(d, a.Username), 0o640, eximGID)); err != nil {
			return res, err
		}
		if err := count(m.writeFile(m.eximPath("forwards", d.Name), forwardsFile(d, a.Username), 0o640, eximGID)); err != nil {
			return res, err
		}
		local := m.eximPath("local", d.Name)
		if d.Local {
			err = count(m.writeFile(local, ownerFile(d, a.Username, false), 0o640, eximGID))
		} else if exists(local) {
			err = count(remove(local))
		}
		if err != nil {
			return res, err
		}
		pub, made, err := m.dkimKey(d.Name, eximGID)
		if err := count(made, err); err != nil {
			return res, err
		}
		res.DKIM[d.Name] = pub
	}

	// The account's own settings: Exim reads its sending limit from here.
	if err := count(m.writeFile(m.eximPath("accounts", a.Username), accountFile(a.Username, a.Limit), 0o640, eximGID)); err != nil {
		return res, err
	}

	had, err := m.domainsOf(a.Username)
	if err != nil {
		return res, err
	}
	for _, name := range had {
		if !wanted[name] {
			n, err := m.removeDomain(name)
			res.Changed += n
			if err != nil {
				return res, err
			}
			m.Log.Info("mail domain removed", "domain", name, "username", a.Username)
		}
	}
	copies, err := m.applySNI(a, had, eximGID)
	if err := count(copies, err); err != nil {
		return res, err
	}
	if err := m.dovecotSNI(ctx, dovecotGID, copies); err != nil {
		return res, err
	}
	if boxes > 0 {
		if err := m.mailDir(ctx, home, uid, gid); err != nil {
			return res, err
		}
	}
	if res.Changed > 0 {
		m.Log.Info("mail applied", "username", a.Username, "domains", len(a.Domains), "changed", res.Changed)
	}
	return res, nil
}

// applySieve writes and compiles the Sieve scripts of a domain's mailboxes
// (blocked senders, spam, filters, autoresponder), and removes those no
// mailbox needs any more.
func (m *Manager) applySieve(ctx context.Context, d Domain, spam Spam, gid int, res *ApplyResult) error {
	dir := m.sieveDir(d.Name)
	want := map[string]bool{}
	scripts := map[string][]byte{}
	for _, b := range d.Mailboxes {
		if s := sieveScript(d, b, spam); s != nil {
			scripts[b.Local] = s
			want[b.Local+".sieve"] = true
			want[b.Local+".svbin"] = true
		}
	}
	if len(want) == 0 {
		if exists(dir) {
			res.Changed++
			return os.RemoveAll(dir)
		}
		return nil
	}
	if err := m.dir(dir, 0o750, gid); err != nil {
		return err
	}
	for _, b := range d.Mailboxes {
		text, ok := scripts[b.Local]
		if !ok {
			continue
		}
		script := filepath.Join(dir, b.Local+".sieve")
		binary := filepath.Join(dir, b.Local+".svbin")
		changed, err := m.writeFile(script, text, 0o640, gid)
		if err != nil {
			return err
		}
		if !changed && exists(binary) {
			continue
		}
		res.Changed++
		code, out, err := m.Run.Run(ctx, "sievec", script, binary)
		if err != nil || code != 0 {
			os.Remove(script) // compiled again on the next try
			return protocol.Fail(CodeSieve, "the filters of "+b.Local+"@"+d.Name+" could not be compiled: "+strings.TrimSpace(out))
		}
		if exists(binary) {
			if err := os.Chmod(binary, 0o640); err != nil {
				return err
			}
			if err := m.Chown(binary, 0, gid); err != nil {
				return err
			}
		}
	}
	entries, err := os.ReadDir(dir)
	if err != nil {
		return err
	}
	for _, e := range entries {
		if !want[e.Name()] {
			res.Changed++
			if err := os.RemoveAll(filepath.Join(dir, e.Name())); err != nil {
				return err
			}
		}
	}
	return nil
}

// removeDomain deletes a domain's files: Exim's first, so mail to it is
// refused before its mailboxes stop being known.
func (m *Manager) removeDomain(name string) (int, error) {
	n := 0
	for _, p := range []string{
		m.eximPath("local", name), m.eximPath("mailboxes", name), m.eximPath("forwards", name),
		m.eximPath("dkim", name), m.passwdPath(name), m.sieveDir(name), m.ssoPath(name), m.ssoKeepPath(name),
		filepath.Join(m.sniDir(), mailName(name)), m.eximPath("owners", name),
	} {
		if !exists(p) {
			continue
		}
		if err := os.RemoveAll(p); err != nil {
			return n, err
		}
		n++
	}
	return n, nil
}

// dkimKey returns the domain's DKIM public key as its TXT record, making a
// new key pair first when the domain has none.
func (m *Manager) dkimKey(domain string, gid int) (string, bool, error) {
	path := m.eximPath("dkim", domain)
	var key *rsa.PrivateKey
	made := false
	if data, err := os.ReadFile(path); err == nil {
		block, _ := pem.Decode(data)
		if block == nil {
			return "", false, fmt.Errorf("%s is not a PEM key", path)
		}
		parsed, err := x509.ParsePKCS8PrivateKey(block.Bytes)
		if err != nil {
			return "", false, fmt.Errorf("%s: %w", path, err)
		}
		k, ok := parsed.(*rsa.PrivateKey)
		if !ok {
			return "", false, fmt.Errorf("%s is not an RSA key", path)
		}
		key = k
	} else if errors.Is(err, fs.ErrNotExist) {
		k, err := rsa.GenerateKey(rand.Reader, m.KeyBits)
		if err != nil {
			return "", false, err
		}
		der, err := x509.MarshalPKCS8PrivateKey(k)
		if err != nil {
			return "", false, err
		}
		if _, err := m.writeFile(path, pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: der}), 0o640, gid); err != nil {
			return "", false, err
		}
		key, made = k, true
	} else {
		return "", false, err
	}
	pub, err := x509.MarshalPKIXPublicKey(&key.PublicKey)
	if err != nil {
		return "", made, err
	}
	return "v=DKIM1; k=rsa; p=" + base64.StdEncoding.EncodeToString(pub), made, nil
}

// mailDir makes ~/mail for the account when it is missing, owned by the
// account: Dovecot makes each mailbox's folders in it as the account's user.
// The home belongs to the account, so the folder is made through a root at
// the home, which no link inside can lead out of.
func (m *Manager) mailDir(ctx context.Context, home string, uid, gid int) error {
	root, err := os.OpenRoot(home)
	if err != nil {
		return err
	}
	defer root.Close()
	if _, err := root.Lstat("mail"); err == nil || !errors.Is(err, fs.ErrNotExist) {
		return nil // there already: the account's own to keep
	}
	if err := root.Mkdir("mail", 0o750); err != nil {
		return err
	}
	if err := m.ChownIn(root, "mail", uid, gid); err != nil {
		return err
	}
	if m.SELinux {
		if err := sysexec.Must(ctx, m.Run, "restorecon", "-R", filepath.Join(home, "mail")); err != nil {
			m.Log.Warn("restorecon failed", "path", filepath.Join(home, "mail"), "err", err)
		}
	}
	return nil
}

// Purge deletes a mailbox's mail, or all of a domain's, once it is no
// longer set up. The home belongs to the account, so the deletion goes
// through a root at the home.
func (m *Manager) Purge(_ context.Context, a PurgeArgs) (map[string]bool, error) {
	if !m.Enabled {
		return nil, protocol.Fail(CodeOff, "the agent is configured without mail")
	}
	if err := checkAccount(a.Username); err != nil {
		return nil, err
	}
	if err := checkDomain(a.Domain); err != nil {
		return nil, err
	}
	if a.Local != "" {
		if err := checkLocal(a.Local); err != nil {
			return nil, err
		}
	}
	_, _, home, err := m.Owner(a.Username)
	if err != nil {
		return nil, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.ownerOf(a.Domain) == a.Username {
		if a.Local == "" {
			return nil, protocol.Fail(CodeInUse, a.Domain+" still has mail set up")
		}
		if data, err := os.ReadFile(m.passwdPath(a.Domain)); err == nil && bytes.Contains(append([]byte("\n"), data...), []byte("\n"+a.Local+":")) {
			return nil, protocol.Fail(CodeInUse, a.Local+"@"+a.Domain+" is still a mailbox")
		}
	}
	root, err := os.OpenRoot(home)
	if err != nil {
		return nil, err
	}
	defer root.Close()
	rel := filepath.Join("mail", a.Domain)
	if a.Local != "" {
		rel = filepath.Join(rel, a.Local)
	}
	_, statErr := root.Lstat(rel)
	if err := root.RemoveAll(rel); err != nil {
		return nil, err
	}
	m.Log.Info("mail purged", "username", a.Username, "domain", a.Domain, "local", a.Local)
	return map[string]bool{"removed": statErr == nil}, nil
}

// Usage measures each mailbox's folder in the account's home. Links are
// not followed.
func (m *Manager) Usage(_ context.Context, a UsageArgs) (Usage, error) {
	if err := checkAccount(a.Username); err != nil {
		return Usage{}, err
	}
	_, _, home, err := m.Owner(a.Username)
	if err != nil {
		return Usage{}, err
	}
	out := Usage{Mailboxes: map[string]int64{}}
	root, err := os.OpenRoot(home)
	if err != nil {
		return out, err
	}
	defer root.Close()
	err = fs.WalkDir(root.FS(), "mail", func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			if p == "mail" {
				return fs.SkipAll
			}
			return nil
		}
		parts := strings.SplitN(p, "/", 4)
		if len(parts) < 4 || !d.Type().IsRegular() {
			return nil
		}
		info, err := d.Info()
		if err != nil {
			return nil
		}
		out.Mailboxes[parts[2]+"@"+parts[1]] += info.Size()
		return nil
	})
	return out, err
}

// RemoveFor deletes an account's mail files; account.terminate runs it
// before the account's user goes (its mail goes with its home).
func (m *Manager) RemoveFor(ctx context.Context, username string) error {
	if !m.Enabled || checkAccount(username) != nil {
		return nil
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	domains, err := m.domainsOf(username)
	if err != nil {
		return err
	}
	for _, d := range domains {
		if _, err := m.removeDomain(d); err != nil {
			return err
		}
		m.Log.Info("mail domain removed", "domain", d, "username", username)
	}
	// Dovecot must not be left naming certificates that are gone: it would
	// not start again.
	if len(domains) > 0 {
		if _, dovecot, err := m.groups(); err == nil {
			if err := m.dovecotSNI(ctx, dovecot, false); err != nil {
				return err
			}
		}
	}
	return os.RemoveAll(m.eximPath("accounts", username))
}

// Info describes the mail servers.
type Info struct {
	Enabled bool        `json:"enabled"`
	Exim    ServiceInfo `json:"exim"`
	Dovecot ServiceInfo `json:"dovecot"`
	// Queue is how many messages wait in Exim's queue.
	Queue     int           `json:"queue"`
	Domains   int           `json:"domains"`
	Filtering FilteringArgs `json:"filtering"`
	Rspamd    ServiceInfo   `json:"rspamd"`
	ClamAV    ClamInfo      `json:"clamav"`
	Errors    []string      `json:"errors,omitempty"`
}

// ServiceInfo is one server's state.
type ServiceInfo struct {
	Running bool   `json:"running"`
	Version string `json:"version"`
}

// ClamInfo is ClamAV's state: Ready once it has signatures.
type ClamInfo struct {
	ServiceInfo
	Ready      bool   `json:"ready"`
	Signatures string `json:"signatures"`
}

// Info asks Exim and Dovecot about themselves.
func (m *Manager) Info(ctx context.Context) Info {
	info := Info{Enabled: m.Enabled}
	if !m.Enabled {
		return info
	}
	active := func(unit string) bool {
		code, _, err := m.Run.Run(ctx, "systemctl", "is-active", "--quiet", unit)
		return err == nil && code == 0
	}
	info.Exim.Running = active("exim")
	info.Dovecot.Running = active("dovecot")
	if code, out, err := m.Run.Run(ctx, "exim", "-bV"); err == nil && code == 0 {
		// "Exim version 4.100.1 #2 built …"
		first, _, _ := strings.Cut(out, "\n")
		if f := strings.Fields(first); len(f) >= 3 {
			info.Exim.Version = f[2]
		}
	}
	if code, out, err := m.Run.Run(ctx, "dovecot", "--version"); err == nil && code == 0 {
		if f := strings.Fields(out); len(f) > 0 {
			info.Dovecot.Version = f[0]
		}
	}
	if code, out, err := m.Run.Run(ctx, "exim", "-bpc"); err == nil && code == 0 {
		info.Queue, _ = strconv.Atoi(strings.TrimSpace(out))
	} else if err != nil {
		info.Errors = append(info.Errors, err.Error())
	}
	if entries, err := os.ReadDir(filepath.Join(m.EximDir, "owners")); err == nil {
		for _, e := range entries {
			if !strings.HasPrefix(e.Name(), ".") {
				info.Domains++
			}
		}
	}
	m.filteringInfo(ctx, &info)
	return info
}

// HostTLS gives Exim and Dovecot the hostname certificate: a copy readable
// by Exim's group (Exim reads it as its own user), reloading both when it
// changed. Without mail, or without a certificate yet, it does nothing.
func (m *Manager) HostTLS(ctx context.Context) error {
	if !m.Enabled {
		return nil
	}
	crt, err1 := os.ReadFile(m.HostCert)
	key, err2 := os.ReadFile(m.HostKey)
	if err1 != nil || err2 != nil {
		return nil
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	exim, err := m.Group("exim")
	if err != nil {
		return nil
	}
	if err := m.dir(m.TLSDir, 0o750, exim); err != nil {
		return err
	}
	c1, err := m.writeFile(filepath.Join(m.TLSDir, "host.key"), key, 0o640, exim)
	if err != nil {
		return err
	}
	c2, err := m.writeFile(filepath.Join(m.TLSDir, "host.crt"), crt, 0o640, exim)
	if err != nil {
		return err
	}
	if !c1 && !c2 {
		return nil
	}
	// Each server on its own: one that cannot reload must not keep the other
	// on the old certificate. (EL's exim unit has no reload of its own.)
	var errs []error
	for _, unit := range []string{"dovecot", "exim"} {
		if code, _, err := m.Run.Run(ctx, "systemctl", "is-active", "--quiet", unit); err != nil || code != 0 {
			continue
		}
		if err := sysexec.Must(ctx, m.Run, "systemctl", "reload-or-restart", unit); err != nil {
			errs = append(errs, err)
		}
	}
	m.Log.Info("mail servers have the hostname certificate")
	return errors.Join(errs...)
}

// SettingsArgs are the server's own mail settings.
type SettingsArgs struct {
	// Smarthost is a relay host all outgoing mail goes through ("host" or
	// "host:port", port 25 by default); empty sends mail straight to each
	// domain's MX.
	Smarthost string `json:"smarthost"`
	// User and Password log in to the relay host, only ever over TLS (Exim
	// refuses to send through it otherwise). An empty User logs in to none.
	// KeepPassword keeps the password the server has (the panel never
	// stores it).
	User         string `json:"user"`
	Password     string `json:"password"`
	KeepPassword bool   `json:"keep_password"`
}

// MaxRelayLogin is the longest relay host user name or password.
const MaxRelayLogin = 256

// Settings writes the server's mail settings: the relay host, and the login
// to it. The login is written before the host, and removed after it, so
// mail never goes to a relay host that wants a login without one.
func (m *Manager) Settings(_ context.Context, a SettingsArgs) (map[string]any, error) {
	if !m.Enabled {
		return nil, protocol.Fail(CodeOff, "the agent is configured without mail")
	}
	path := filepath.Join(m.EximDir, "smarthost")
	authPath := filepath.Join(m.EximDir, "smarthost_auth")
	keepPath := filepath.Join(m.EximDir, "smarthost_login")
	m.mu.Lock()
	defer m.mu.Unlock()
	if a.Smarthost == "" {
		if err := os.RemoveAll(path); err != nil {
			return nil, err
		}
		for _, p := range []string{authPath, keepPath} {
			if err := os.RemoveAll(p); err != nil {
				return nil, err
			}
		}
		return map[string]any{"smarthost": "", "login": false}, nil
	}
	host, port, err := splitHostPort(a.Smarthost)
	if err != nil {
		return nil, err
	}
	if a.User != "" {
		if a.KeepPassword {
			old, err := readRelayLogin(keepPath)
			if err != nil || old[1] == "" {
				return nil, badArgs("there is no relay host password to keep")
			}
			a.Password = old[1]
		}
		for _, v := range []string{a.User, a.Password} {
			if v == "" || len(v) > MaxRelayLogin || strings.ContainsFunc(v, func(r rune) bool { return r < 0x20 || r > 0x7e }) {
				return nil, badArgs("the relay host's user and password must be 1 to %d printable ASCII characters", MaxRelayLogin)
			}
		}
	}
	exim, _, err := m.groups()
	if err != nil {
		return nil, err
	}
	if err := m.dir(m.EximDir, 0o750, exim); err != nil {
		return nil, err
	}
	if a.User != "" {
		if _, err := m.writeFile(keepPath, relayKeepFile(a.User, a.Password), 0o600, 0); err != nil {
			return nil, err
		}
		if _, err := m.writeFile(authPath, relayLoginFile(a.User, a.Password), 0o640, exim); err != nil {
			return nil, err
		}
	}
	// Exim's host list: a colon in it is doubled.
	if _, err := m.writeFile(path, []byte(host+"::"+strconv.Itoa(port)), 0o640, exim); err != nil {
		return nil, err
	}
	if a.User == "" {
		for _, p := range []string{authPath, keepPath} {
			if err := os.RemoveAll(p); err != nil {
				return nil, err
			}
		}
	}
	m.Log.Info("mail relay host set", "host", host, "port", port, "login", a.User != "")
	return map[string]any{"smarthost": host + ":" + strconv.Itoa(port), "login": a.User != "", "user": a.User}, nil
}

// relayLoginFile is the relay host login exactly as Exim's PLAIN client
// authenticator sends it (it reads the file whole): "^user^password", each
// "^" in a value doubled (Exim reads a single one as the separator). Exim
// splits client_send into its list before expanding it, so nothing else in
// a value needs escaping.
func relayLoginFile(user, password string) []byte {
	esc := func(v string) string { return strings.ReplaceAll(v, "^", "^^") }
	return []byte("^" + esc(user) + "^" + esc(password))
}

// relayKeepFile holds the plain user and password (root's alone), so a
// later change of host can keep the password the panel never stores.
func relayKeepFile(user, password string) []byte {
	return []byte(strconv.Quote(user) + "\n" + strconv.Quote(password) + "\n")
}

// readRelayLogin reads back the relay host's user and password.
func readRelayLogin(path string) ([2]string, error) {
	var out [2]string
	data, err := os.ReadFile(path)
	if err != nil {
		return out, err
	}
	lines := strings.SplitN(string(data), "\n", 3)
	for i := 0; i < 2 && i < len(lines); i++ {
		if s, err := strconv.Unquote(lines[i]); err == nil {
			out[i] = s
		}
	}
	return out, nil
}

// splitHostPort accepts a host name or IPv4 address, with an optional port.
func splitHostPort(s string) (string, int, error) {
	host, portText, found := strings.Cut(strings.ToLower(strings.TrimSpace(s)), ":")
	port := 25
	if found {
		p, err := strconv.Atoi(portText)
		if err != nil || p < 1 || p > 65535 {
			return "", 0, badArgs("%q is not a port", portText)
		}
		port = p
	}
	if ip := net.ParseIP(host); ip != nil && ip.To4() != nil {
		return host, port, nil
	}
	if checkDomain(host) != nil {
		return "", 0, badArgs("%q is not a host name or IPv4 address", host)
	}
	return host, port, nil
}
