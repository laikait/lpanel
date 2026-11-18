// Package ftp runs accounts' FTP logins on Pure-FTPd.
//
// Every login is a virtual user in Pure-FTPd's own indexed file (PureDB),
// working as its account's Unix user, shut in a folder of the account's
// home. The panel sends an account's whole list at once (ftp.apply); the
// agent keeps one file per account (users.d/<account>, the logins, their
// bcrypt hashes and folders), joins them into Pure-FTPd's text file and has
// pure-pw index it. Pure-FTPd reads the index at each login, so nothing is
// reloaded and open sessions go on.
//
// The server's settings (TLS, the passive ports, how many clients) are the
// agent's too: it writes pure-ftpd.conf whole (ftp.settings), and gives
// Pure-FTPd the hostname certificate whenever that changes (HostTLS).
package ftp

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"log/slog"
	"net"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"syscall"

	"lpa/internal/protocol"
	"lpa/internal/sysexec"
)

// Error codes the panel can act on.
const (
	CodeOff       = "ftp_off"        // the server has no FTP (lpa.json "ftp" is "off")
	CodeTaken     = "ftp_taken"      // another account has a login of that name
	CodeNoSession = "ftp_no_session" // no such session (it ended)
)

// Limits on what the panel may send.
const (
	MaxUsers  = 1000 // logins an account may have
	MaxLogin  = 100
	MaxFolder = 1024
)

var (
	// A login is a name and one of the account's domains: bob@example.com.
	loginRule = regexp.MustCompile(`^[a-z0-9]([a-z0-9._-]{0,62}[a-z0-9])?@([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$`)
	// bcrypt only (PHP's password_hash): Pure-FTPd checks it with crypt(3).
	hashRule = regexp.MustCompile(`^\$2[aby]\$(0[4-9]|1[0-5])\$[./A-Za-z0-9]{53}$`)
	userRule = regexp.MustCompile(`^[a-z][a-z0-9]{1,15}$`)
)

// User is one FTP login.
type User struct {
	Login string `json:"login"`
	Hash  string `json:"hash"`
	// Folder is where the login is shut in, from the home ("" is the whole
	// home, "public_html/shop" a folder of it). It is made when missing.
	Folder string `json:"folder"`
}

// ApplyArgs are an account's FTP logins, all of them.
type ApplyArgs struct {
	Username string `json:"username"`
	Users    []User `json:"users"`
	// Suspended keeps the logins in the panel but lets none of them in.
	Suspended bool `json:"suspended"`
}

// ApplyResult says what the server has now.
type ApplyResult struct {
	Users int `json:"users"`
	// Ended counts sessions closed because their login went, changed its
	// password or folder, or the account was suspended.
	Ended int `json:"ended"`
}

// TLS modes, as Pure-FTPd numbers them.
var tlsModes = map[string]int{
	"optional": 1, // plain FTP and FTPS both
	"required": 2, // logins only over TLS; transfers may be plain
	"strict":   3, // logins and transfers over TLS
}

// SettingsArgs are the server's FTP settings.
type SettingsArgs struct {
	TLS         string `json:"tls"`
	PassiveFrom int    `json:"passive_from"`
	PassiveTo   int    `json:"passive_to"`
	// PassiveIP is the address clients are told to connect to for
	// transfers, behind NAT; "" uses the one they connected to.
	PassiveIP  string `json:"passive_ip"`
	MaxClients int    `json:"max_clients"`
	MaxPerIP   int    `json:"max_per_ip"`
}

func (s SettingsArgs) check() error {
	bad := func(msg string) error { return protocol.Fail(protocol.CodeBadArgs, msg) }
	if _, ok := tlsModes[s.TLS]; !ok {
		return bad("tls is optional, required or strict")
	}
	if s.PassiveFrom < 1024 || s.PassiveTo > 65535 || s.PassiveFrom > s.PassiveTo || s.PassiveTo-s.PassiveFrom > 10000 {
		return bad("the passive ports are a range within 1024-65535, of at most 10001 ports")
	}
	if s.PassiveIP != "" {
		ip := net.ParseIP(s.PassiveIP)
		if ip == nil || ip.To4() == nil || s.PassiveIP != ip.String() {
			return bad("passive_ip is an IPv4 address")
		}
	}
	if s.MaxClients < 1 || s.MaxClients > 10000 {
		return bad("max_clients is 1-10000")
	}
	if s.MaxPerIP < 1 || s.MaxPerIP > s.MaxClients {
		return bad("max_per_ip is 1 to max_clients")
	}
	return nil
}

// DefaultSettings are what a new server gets.
func DefaultSettings() SettingsArgs {
	return SettingsArgs{TLS: "required", PassiveFrom: 30000, PassiveTo: 30100, MaxClients: 200, MaxPerIP: 8}
}

// KillArgs name a session to end; with Username, only one of that
// account's logins.
type KillArgs struct {
	PID      int    `json:"pid"`
	Username string `json:"username,omitempty"`
}

// Session is one open FTP connection.
type Session struct {
	PID     int    `json:"pid"`
	Login   string `json:"login"`
	Account string `json:"account"`
	Seconds int    `json:"seconds"`
	State   string `json:"state"`
	File    string `json:"file"`
	Peer    string `json:"peer"`
	Current int64  `json:"current"`
	Total   int64  `json:"total"`
	Percent int    `json:"percent"`
	// Bandwidth is the transfer's speed in KB/s.
	Bandwidth int64 `json:"bandwidth"`
}

// Info is the FTP server's state for the Admin panel.
type Info struct {
	Enabled   bool         `json:"enabled"`
	Installed bool         `json:"installed"`
	Running   bool         `json:"running"`
	Version   string       `json:"version"`
	Logins    int          `json:"logins"`
	Settings  SettingsArgs `json:"settings"`
	Sessions  []Session    `json:"sessions"`
	// SessionsKnown is false when pure-ftpwho could not be asked.
	SessionsKnown bool `json:"sessions_known"`
}

// Manager does the FTP operations.
type Manager struct {
	// Enabled turns the operations on (lpa.json "ftp": "on").
	Enabled bool
	// Dir holds the agent's data: users.d, the joined passwd file, its
	// index and the settings last applied.
	Dir string
	// Conf is pure-ftpd.conf; CertFile the key and certificate it serves.
	Conf, CertFile string
	// HostCert and HostKey are the hostname certificate (the panel's).
	HostCert, HostKey string
	// Binary is Pure-FTPd itself (only its presence is checked).
	Binary string
	Run    sysexec.Runner
	Owner  func(name string) (uid, gid int, home string, err error)
	Log    *slog.Logger
	// ChownIn sets the owner of a folder made inside a home; Signal ends a
	// session's process. Tests replace them.
	ChownIn func(root *os.Root, name string, uid, gid int) error
	Signal  func(pid int) error

	mu sync.Mutex
}

// Defaults fills in what main and tests leave empty.
func (m *Manager) Defaults() {
	set := func(p *string, v string) {
		if *p == "" {
			*p = v
		}
	}
	set(&m.Dir, "/etc/pure-ftpd/lpanel")
	set(&m.Conf, "/etc/pure-ftpd/pure-ftpd.conf")
	set(&m.CertFile, "/etc/pki/pure-ftpd/pure-ftpd.pem")
	set(&m.HostCert, "/etc/lpanel/tls/panel.crt")
	set(&m.HostKey, "/etc/lpanel/tls/panel.key")
	set(&m.Binary, "/usr/sbin/pure-ftpd")
	if m.Log == nil {
		m.Log = slog.New(slog.NewTextHandler(io.Discard, nil))
	}
	if m.ChownIn == nil {
		m.ChownIn = func(root *os.Root, name string, uid, gid int) error { return root.Lchown(name, uid, gid) }
	}
	if m.Signal == nil {
		m.Signal = func(pid int) error {
			p, err := os.FindProcess(pid)
			if err != nil {
				return err
			}
			return p.Signal(syscall.SIGTERM)
		}
	}
}

func (m *Manager) usersDir() string     { return filepath.Join(m.Dir, "users.d") }
func (m *Manager) passwd() string       { return filepath.Join(m.Dir, "passwd") }
func (m *Manager) pdb() string          { return filepath.Join(m.Dir, "pureftpd.pdb") }
func (m *Manager) settingsFile() string { return filepath.Join(m.Dir, "settings.json") }

func (m *Manager) off() error {
	return protocol.Fail(CodeOff, "this server has no FTP (lpa.json \"ftp\" is \"off\")")
}

// CleanFolder checks a folder named from the home and returns it without
// slashes at either end ("" for the home itself).
func CleanFolder(folder string) (string, error) {
	f := strings.Trim(folder, "/")
	if len(f) > MaxFolder {
		return "", errors.New("the folder's name is too long")
	}
	if f == "" {
		return "", nil
	}
	for _, part := range strings.Split(f, "/") {
		if part == "" || part == "." || part == ".." || len(part) > 255 {
			return "", fmt.Errorf("%q is not a folder within the home", folder)
		}
		for _, r := range part {
			// ":" separates the fields of Pure-FTPd's file; no control
			// characters, no backslashes.
			if r < 0x20 || r == 0x7f || r == ':' || r == '\\' {
				return "", fmt.Errorf("%q has a character a folder may not have here", folder)
			}
		}
	}
	return f, nil
}

func (a *ApplyArgs) check() error {
	bad := func(msg string) error { return protocol.Fail(protocol.CodeBadArgs, msg) }
	if !userRule.MatchString(a.Username) {
		return bad("username is not an account name")
	}
	if len(a.Users) > MaxUsers {
		return bad(fmt.Sprintf("an account has at most %d FTP logins", MaxUsers))
	}
	seen := map[string]bool{}
	for i := range a.Users {
		u := &a.Users[i]
		if len(u.Login) > MaxLogin || !loginRule.MatchString(u.Login) {
			return bad(fmt.Sprintf("%q is not a login (name@domain)", u.Login))
		}
		if seen[u.Login] {
			return bad(fmt.Sprintf("%s is given twice", u.Login))
		}
		seen[u.Login] = true
		if !hashRule.MatchString(u.Hash) {
			return bad(fmt.Sprintf("the password of %s is not a bcrypt hash", u.Login))
		}
		f, err := CleanFolder(u.Folder)
		if err != nil {
			return bad(err.Error())
		}
		u.Folder = f
	}
	return nil
}

// line is a login in Pure-FTPd's text format: login, hash, uid, gid, a
// description, the home ("/./" marks where it is shut in), then twelve
// fields of limits left empty.
func line(u User, uid, gid int, home string) string {
	dir := home
	if u.Folder != "" {
		dir += "/" + u.Folder
	}
	return fmt.Sprintf("%s:%s:%d:%d::%s/./%s\n", u.Login, u.Hash, uid, gid, dir, strings.Repeat(":", 12))
}

// entry is a login as read back from a users.d file.
type entry struct {
	login, rest string
}

func readUsers(path string) ([]entry, error) {
	data, err := os.ReadFile(path)
	if errors.Is(err, fs.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var out []entry
	for _, l := range strings.Split(string(data), "\n") {
		login, rest, ok := strings.Cut(l, ":")
		if ok && login != "" {
			out = append(out, entry{login, rest})
		}
	}
	return out, nil
}

// owners maps every login on the server to its account.
func (m *Manager) owners() (map[string]string, error) {
	files, err := os.ReadDir(m.usersDir())
	if errors.Is(err, fs.ErrNotExist) {
		return map[string]string{}, nil
	}
	if err != nil {
		return nil, err
	}
	out := map[string]string{}
	for _, f := range files {
		if !f.Type().IsRegular() || !userRule.MatchString(f.Name()) {
			continue
		}
		list, err := readUsers(filepath.Join(m.usersDir(), f.Name()))
		if err != nil {
			return nil, err
		}
		for _, e := range list {
			out[e.login] = f.Name()
		}
	}
	return out, nil
}

// Apply makes an account's FTP logins on the server what the panel has.
func (m *Manager) Apply(ctx context.Context, a ApplyArgs) (ApplyResult, error) {
	if !m.Enabled {
		return ApplyResult{}, m.off()
	}
	if err := a.check(); err != nil {
		return ApplyResult{}, err
	}
	uid, gid, home, err := m.Owner(a.Username)
	if err != nil {
		return ApplyResult{}, err
	}
	if strings.ContainsAny(home, ":\n") {
		return ApplyResult{}, fmt.Errorf("the home %q cannot be written into Pure-FTPd's file", home)
	}
	m.mu.Lock()
	defer m.mu.Unlock()

	owners, err := m.owners()
	if err != nil {
		return ApplyResult{}, err
	}
	for _, u := range a.Users {
		if o, ok := owners[u.Login]; ok && o != a.Username {
			return ApplyResult{}, protocol.Fail(CodeTaken, u.Login+" is another account's login")
		}
	}

	var buf bytes.Buffer
	if !a.Suspended {
		if err := m.folders(home, uid, gid, a.Users); err != nil {
			return ApplyResult{}, err
		}
		sorted := append([]User(nil), a.Users...)
		sort.Slice(sorted, func(i, j int) bool { return sorted[i].Login < sorted[j].Login })
		for _, u := range sorted {
			buf.WriteString(line(u, uid, gid, home))
		}
	}

	path := filepath.Join(m.usersDir(), a.Username)
	before, err := readUsers(path)
	if err != nil {
		return ApplyResult{}, err
	}
	if err := m.prepare(); err != nil {
		return ApplyResult{}, err
	}
	if err := writeFile(path, buf.Bytes(), 0o600); err != nil {
		return ApplyResult{}, err
	}
	if err := m.rebuild(ctx); err != nil {
		return ApplyResult{}, err
	}

	// Sessions of logins that went or changed end: a new password or
	// folder holds from now on, not from the next login.
	now := map[string]string{}
	after, _ := readUsers(path)
	for _, e := range after {
		now[e.login] = e.rest
	}
	stale := map[string]bool{}
	for _, e := range before {
		if rest, ok := now[e.login]; !ok || rest != e.rest {
			stale[e.login] = true
		}
	}
	ended := m.end(ctx, func(s Session) bool { return stale[s.Login] })
	m.Log.Info("FTP logins applied", "username", a.Username, "logins", len(after), "ended", ended)
	return ApplyResult{Users: len(after), Ended: ended}, nil
}

// folders makes each login's folder where it is missing, as the account's,
// through an os.Root so no link leads the agent out of the home.
func (m *Manager) folders(home string, uid, gid int, users []User) error {
	root, err := os.OpenRoot(home)
	if err != nil {
		return fmt.Errorf("the home %s: %w", home, err)
	}
	defer root.Close()
	for _, u := range users {
		if u.Folder == "" {
			continue
		}
		parts := strings.Split(u.Folder, "/")
		for i := range parts {
			p := strings.Join(parts[:i+1], "/")
			info, err := root.Stat(p)
			switch {
			case err == nil && info.IsDir():
				continue
			case err == nil:
				return protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf("%s is a file, not a folder", p))
			case errors.Is(err, fs.ErrNotExist):
				if err := root.Mkdir(p, 0o755); err != nil {
					return fmt.Errorf("making %s: %w", p, err)
				}
				// The agent runs with umask 0077; set the mode outright.
				if err := root.Chmod(p, 0o755); err != nil {
					return fmt.Errorf("making %s: %w", p, err)
				}
				if err := m.ChownIn(root, p, uid, gid); err != nil {
					return fmt.Errorf("giving %s to the account: %w", p, err)
				}
			default:
				return protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf("%s: %v", p, err))
			}
		}
	}
	return nil
}

func (m *Manager) prepare() error {
	for _, d := range []string{m.Dir, m.usersDir()} {
		if err := os.MkdirAll(d, 0o700); err != nil {
			return err
		}
		if err := os.Chmod(d, 0o700); err != nil {
			return err
		}
	}
	return nil
}

// rebuild joins every account's file into Pure-FTPd's and indexes it;
// pure-pw writes the index beside it and renames it into place.
func (m *Manager) rebuild(ctx context.Context) error {
	files, err := os.ReadDir(m.usersDir())
	if err != nil && !errors.Is(err, fs.ErrNotExist) {
		return err
	}
	var all bytes.Buffer
	for _, f := range files {
		if !f.Type().IsRegular() || !userRule.MatchString(f.Name()) {
			continue
		}
		data, err := os.ReadFile(filepath.Join(m.usersDir(), f.Name()))
		if err != nil {
			return err
		}
		all.Write(data)
	}
	if err := writeFile(m.passwd(), all.Bytes(), 0o600); err != nil {
		return err
	}
	return sysexec.Must(ctx, m.Run, "pure-pw", "mkdb", m.pdb(), "-f", m.passwd())
}

// RemoveFor takes away a terminated account's logins and ends their
// sessions (before its user is deleted).
func (m *Manager) RemoveFor(ctx context.Context, username string) error {
	if !m.Enabled || !userRule.MatchString(username) {
		return nil
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	path := filepath.Join(m.usersDir(), username)
	list, err := readUsers(path)
	if err != nil {
		return err
	}
	if _, err := os.Lstat(path); errors.Is(err, fs.ErrNotExist) {
		return nil
	}
	if err := os.Remove(path); err != nil {
		return err
	}
	if err := m.rebuild(ctx); err != nil {
		return err
	}
	gone := map[string]bool{}
	for _, e := range list {
		gone[e.login] = true
	}
	m.end(ctx, func(s Session) bool { return gone[s.Login] })
	return nil
}

// Sessions lists the open connections, each with its account.
func (m *Manager) Sessions(ctx context.Context) ([]Session, error) {
	code, out, err := m.Run.Run(ctx, "pure-ftpwho", "-s", "-H")
	if err != nil {
		return nil, err
	}
	if code != 0 {
		return nil, fmt.Errorf("pure-ftpwho exited with %d: %s", code, out)
	}
	owners, err := m.owners()
	if err != nil {
		return nil, err
	}
	return parseWho(out, owners), nil
}

// parseWho reads pure-ftpwho -s:
// pid|login|seconds|state|file|peer|local|port|current|total|percent|bandwidth
func parseWho(out string, owners map[string]string) []Session {
	list := []Session{}
	for _, l := range strings.Split(out, "\n") {
		f := strings.Split(strings.TrimSpace(l), "|")
		if len(f) < 12 {
			continue
		}
		pid, err := strconv.Atoi(f[0])
		if err != nil || pid <= 1 {
			continue
		}
		num := func(s string) int64 { n, _ := strconv.ParseInt(strings.TrimSpace(s), 10, 64); return n }
		list = append(list, Session{
			PID: pid, Login: f[1], Account: owners[f[1]], Seconds: int(num(f[2])), State: f[3], File: f[4], Peer: f[5],
			Current: num(f[8]), Total: num(f[9]), Percent: int(num(f[10])), Bandwidth: num(f[11]),
		})
	}
	sort.Slice(list, func(i, j int) bool { return list[i].PID < list[j].PID })
	return list
}

// end closes the sessions picked; it counts those it could signal.
func (m *Manager) end(ctx context.Context, pick func(Session) bool) int {
	list, err := m.Sessions(ctx)
	if err != nil {
		return 0
	}
	n := 0
	for _, s := range list {
		if pick(s) && m.Signal(s.PID) == nil {
			n++
		}
	}
	return n
}

// Kill ends one session, found in pure-ftpwho's list, so only an FTP
// session's process is ever signalled.
func (m *Manager) Kill(ctx context.Context, a KillArgs) (map[string]any, error) {
	if !m.Enabled {
		return nil, m.off()
	}
	if a.PID <= 1 || (a.Username != "" && !userRule.MatchString(a.Username)) {
		return nil, protocol.Fail(protocol.CodeBadArgs, "pid is a session's process id; username an account name")
	}
	list, err := m.Sessions(ctx)
	if err != nil {
		return nil, err
	}
	for _, s := range list {
		if s.PID != a.PID {
			continue
		}
		if a.Username != "" && s.Account != a.Username {
			break
		}
		if err := m.Signal(s.PID); err != nil {
			return nil, err
		}
		m.Log.Info("FTP session ended", "pid", s.PID, "login", s.Login)
		return map[string]any{"ended": true, "login": s.Login}, nil
	}
	return nil, protocol.Fail(CodeNoSession, "no such FTP session")
}

// Settings writes pure-ftpd.conf and restarts Pure-FTPd when it changed
// (or is not running); if it will not start, the old file comes back.
func (m *Manager) Settings(ctx context.Context, s SettingsArgs) (map[string]any, error) {
	if !m.Enabled {
		return nil, m.off()
	}
	if err := s.check(); err != nil {
		return nil, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if err := m.prepare(); err != nil {
		return nil, err
	}
	// Pure-FTPd will not start on an empty index.
	if _, err := os.Stat(m.pdb()); errors.Is(err, fs.ErrNotExist) {
		if err := m.rebuild(ctx); err != nil {
			return nil, err
		}
	}
	certNew, err := m.certChanged()
	if err != nil {
		return nil, err
	}
	conf := m.render(s)
	old, readErr := os.ReadFile(m.Conf)
	changed := readErr != nil || !bytes.Equal(old, conf)
	if changed {
		if err := writeFile(m.Conf, conf, 0o644); err != nil {
			return nil, err
		}
	}
	running := m.active(ctx)
	if changed || certNew || !running {
		if err := sysexec.Must(ctx, m.Run, "systemctl", "restart", "pure-ftpd"); err != nil || !m.active(ctx) {
			if changed && readErr == nil {
				_ = writeFile(m.Conf, old, 0o644)
				_ = sysexec.Must(ctx, m.Run, "systemctl", "restart", "pure-ftpd")
			}
			if err == nil {
				err = errors.New("Pure-FTPd did not stay running")
			}
			return nil, fmt.Errorf("Pure-FTPd did not start with these settings, so the old ones stay: %w", err)
		}
	}
	data, _ := json.Marshal(s)
	if err := writeFile(m.settingsFile(), append(data, '\n'), 0o600); err != nil {
		return nil, err
	}
	m.Log.Info("FTP settings applied", "tls", s.TLS, "changed", changed)
	return map[string]any{"changed": changed, "running": true}, nil
}

func (m *Manager) active(ctx context.Context) bool {
	code, _, err := m.Run.Run(ctx, "systemctl", "is-active", "--quiet", "pure-ftpd")
	return err == nil && code == 0
}

// render is the whole of pure-ftpd.conf. Everyone is shut in their folder,
// only PureDB's logins exist (no anonymous, no system users), files are
// made 644 and folders 755, and the hostname certificate is served.
func (m *Manager) render(s SettingsArgs) []byte {
	var b strings.Builder
	w := func(k string, v any) { fmt.Fprintf(&b, "%-28s %v\n", k, v) }
	b.WriteString("# Written by LPanel's agent (Admin panel, FTP). Changes made here are\n# replaced the next time the settings are saved.\n\n")
	w("ChrootEveryone", "yes")
	w("BrokenClientsCompatibility", "no")
	w("Daemonize", "yes")
	w("MaxClientsNumber", s.MaxClients)
	w("MaxClientsPerIP", s.MaxPerIP)
	w("VerboseLog", "no")
	w("DisplayDotFiles", "yes")
	w("AnonymousOnly", "no")
	w("NoAnonymous", "yes")
	w("SyslogFacility", "ftp")
	w("DontResolve", "yes")
	w("MaxIdleTime", 15)
	w("PureDB", m.pdb())
	w("PAMAuthentication", "no")
	w("UnixAuthentication", "no")
	w("LimitRecursion", "10000 8")
	w("AnonymousCanCreateDirs", "no")
	w("AnonymousCantUpload", "yes")
	w("Umask", "133:022")
	w("MinUID", 1000)
	w("AllowUserFXP", "no")
	w("AllowAnonymousFXP", "no")
	w("ProhibitDotFilesWrite", "no")
	w("ProhibitDotFilesRead", "no")
	w("AutoRename", "no")
	w("AltLog", "clf:/var/log/pureftpd.log")
	w("MaxDiskUsage", 99)
	w("CustomerProof", "yes")
	w("PassivePortRange", fmt.Sprintf("%d %d", s.PassiveFrom, s.PassiveTo))
	if s.PassiveIP != "" {
		w("ForcePassiveIP", s.PassiveIP)
	}
	w("TLS", tlsModes[s.TLS])
	w("CertFile", m.CertFile)
	w("TLSCipherSuite", "HIGH:!aNULL:!MD5:!RC4")
	return []byte(b.String())
}

// Applied are the settings last written ("" TLS when none were yet).
func (m *Manager) Applied() SettingsArgs {
	var s SettingsArgs
	if data, err := os.ReadFile(m.settingsFile()); err == nil {
		_ = json.Unmarshal(data, &s)
	}
	return s
}

// certChanged puts the hostname's key and certificate in Pure-FTPd's file
// and reports whether that changed. Without a hostname certificate the
// package's own self-signed one stays.
func (m *Manager) certChanged() (bool, error) {
	crt, err1 := os.ReadFile(m.HostCert)
	key, err2 := os.ReadFile(m.HostKey)
	if err1 != nil || err2 != nil {
		return false, nil
	}
	pem := append(append(bytes.TrimSpace(key), '\n'), append(bytes.TrimSpace(crt), '\n')...)
	if old, err := os.ReadFile(m.CertFile); err == nil && bytes.Equal(old, pem) {
		return false, nil
	}
	if err := os.MkdirAll(filepath.Dir(m.CertFile), 0o700); err != nil {
		return false, err
	}
	return true, writeFile(m.CertFile, pem, 0o600)
}

// HostTLS gives Pure-FTPd the hostname certificate, restarting it when the
// certificate changed (it reads it at start).
func (m *Manager) HostTLS(ctx context.Context) error {
	if !m.Enabled {
		return nil
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	changed, err := m.certChanged()
	if err != nil || !changed {
		return err
	}
	if !m.active(ctx) {
		return nil
	}
	m.Log.Info("Pure-FTPd has the hostname certificate")
	return sysexec.Must(ctx, m.Run, "systemctl", "restart", "pure-ftpd")
}

// Info is the server's FTP state.
func (m *Manager) Info(ctx context.Context) Info {
	info := Info{Enabled: m.Enabled, Settings: m.Applied(), Sessions: []Session{}}
	if _, err := os.Stat(m.Binary); err == nil {
		info.Installed = true
	}
	if !m.Enabled || !info.Installed {
		return info
	}
	info.Running = m.active(ctx)
	if code, out, err := m.Run.Run(ctx, "rpm", "-q", "--qf", "%{VERSION}", "pure-ftpd"); err == nil && code == 0 {
		info.Version = strings.TrimSpace(out)
	}
	if owners, err := m.owners(); err == nil {
		info.Logins = len(owners)
	}
	if info.Running {
		if list, err := m.Sessions(ctx); err == nil {
			info.Sessions, info.SessionsKnown = list, true
		}
	}
	return info
}

// writeFile replaces path with data (root's), through a temporary file.
func writeFile(path string, data []byte, perm os.FileMode) error {
	tmp, err := os.CreateTemp(filepath.Dir(path), "."+filepath.Base(path)+".tmp-*")
	if err != nil {
		return err
	}
	name := tmp.Name()
	defer os.Remove(name)
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Sync(); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	if err := os.Chmod(name, perm); err != nil {
		return err
	}
	return os.Rename(name, path)
}
