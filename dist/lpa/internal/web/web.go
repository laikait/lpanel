// Package web configures the web server for hosting accounts: one Apache
// file per account with a VirtualHost per site, and one PHP-FPM pool per
// account for each PHP version its sites use (Remi's versions, side by side).
//
// The panel always sends an account's whole web state (web.apply). The agent
// renders it, and when anything differs from what is live:
//
//  1. tests the candidate before anything live changes: each new pool with
//     php-fpm -t, and Apache with the candidate file among copies of every
//     other account's, through the LPANEL_SITES variable;
//  2. swaps the files in, keeping the old ones;
//  3. reloads the PHP versions involved, then Apache;
//  4. probes: every site must answer with the new revision in its
//     X-LPanel-Revision header, and every pool's socket must be there;
//  5. if the probe fails, puts the old files back and reloads again.
//
// Applying the same state twice changes nothing, so a retry is always safe.
// Changes to the web server run one at a time.
package web

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"os"
	"os/user"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"lpa/internal/protocol"
	"lpa/internal/sysexec"
)

// Error codes particular to web operations.
const (
	CodeWebOff     = "web_off"         // the agent is configured without a web server
	CodePHPMissing = "php_missing"     // a site asks for a PHP version that is not installed
	CodeRejected   = "config_rejected" // the candidate failed its test; nothing live changed
	CodeProbe      = "probe_failed"    // the reloaded server did not serve it; the old files are back
	CodeDocroot    = "docroot"         // a document root could not be made safely
)

var (
	userRule    = regexp.MustCompile(`^[a-z][a-z0-9]{1,15}$`)
	labelRule   = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$`)
	versionRule = regexp.MustCompile(`^[5-8][0-9]$`)
	partRule    = regexp.MustCompile(`^[A-Za-z0-9_][A-Za-z0-9._-]{0,63}$`)
)

// Site is one VirtualHost: a domain, the other names it answers to, the
// folder it serves (relative to the home) and its PHP version ("83").
type Site struct {
	Name    string   `json:"name"`
	Aliases []string `json:"aliases"`
	Docroot string   `json:"docroot"`
	PHP     string   `json:"php"`
	// Canonical sends visitors to the www. name ("www") or away from it
	// ("bare"); "" leaves both.
	Canonical  string            `json:"canonical"`
	Redirects  []Redirect        `json:"redirects"`
	ErrorPages map[string]string `json:"error_pages"`
	Protected  []Protected       `json:"protected"`
	Hotlink    *Hotlink          `json:"hotlink"`
}

// Pool holds the PHP-FPM limits the package sets.
type Pool struct {
	MaxChildren int `json:"max_children"`
}

// ApplyArgs are web.apply's arguments: an account's whole web state.
type ApplyArgs struct {
	Username  string `json:"username"`
	Suspended bool   `json:"suspended"`
	// Limited is true when the account has used its bandwidth for the
	// month: its sites answer 503 and say so, but keep their settings.
	Limited bool   `json:"limited"`
	Sites   []Site `json:"sites"`
	Pool    Pool   `json:"pool"`
	// PHPSettings are the owner's php.ini choices for each PHP version
	// ("83" → "memory_limit" → "256M"), written into that version's pool.
	PHPSettings map[string]map[string]string `json:"php_settings"`
}

// NameArgs name one account.
type NameArgs struct {
	Username string `json:"username"`
}

// Result is web.apply's and web.remove's answer.
type Result struct {
	Username string   `json:"username"`
	Changed  bool     `json:"changed"`
	Revision string   `json:"revision,omitempty"`
	PHP      []string `json:"php"`
	Sites    int      `json:"sites"`
}

// PHPVersion describes one installed PHP version.
type PHPVersion struct {
	Version string `json:"version"`
	Pools   int    `json:"pools"`
	Running bool   `json:"running"`
}

// Info is web.info's answer.
type Info struct {
	Server    string       `json:"server"`
	Installed bool         `json:"installed"`
	Running   bool         `json:"running"`
	PHP       []PHPVersion `json:"php"`
	Accounts  int          `json:"accounts"`
}

// Manager carries out web changes. Paths are fields so tests can use a
// temporary folder.
type Manager struct {
	Server   string // "apache" or "off"
	Include  string // /etc/httpd/conf.d/lpanel.conf
	Sites    string // /etc/httpd/lpanel/sites
	Htpasswd string // /etc/httpd/lpanel/htpasswd: logins of protected folders
	LogDir   string // /var/log/httpd/lpanel
	PHPRoot  string // /opt/remi: phpXY/root/usr/sbin/php-fpm
	PHPConf  string // /etc/opt/remi: phpXY/php-fpm.d/
	PHPRun   string // /var/opt/remi: phpXY/run/php-fpm/
	WebGroup string // apache
	Run      sysexec.Runner
	Log      *slog.Logger
	// Owner describes an LPanel account and refuses anything else
	// (accounts.Manager.Owner).
	Owner func(name string) (uid, gid int, home string, err error)
	// PanelPHP is the PHP version the panel itself runs on ("83"): it
	// cannot be removed, nor the extensions the panel needs.
	PanelPHP string
	// TrafficState is the agent's own record of how far it has read each
	// access log, and the bytes counted per account and day.
	TrafficState string
	// GroupID looks a group up; os/user when nil.
	GroupID func(name string) (int, error)
	// Chown sets a file's owner; os.Chown when nil, tests replace it.
	Chown func(path string, uid, gid int) error
	// MakeDirs makes an account's document roots and its tmp and logs
	// folders without following links (docroot_linux.go), and returns the
	// folders it made; tests replace it.
	MakeDirs func(home string, docroots []string, uid, gid, webGID int) ([]string, error)
	// SELinux is true on a host with SELinux on: folders the agent makes get
	// the policy's labels (restorecon), not just their parent's.
	SELinux bool
	// Probe asks the web server for host and checks the revision it sends.
	Probe func(ctx context.Context, host, revision string) error
	// ProbeFor is how long a reloaded server has to answer correctly.
	ProbeFor time.Duration

	mu sync.Mutex
	// pkgMu keeps dnf runs one at a time (dnf has its own lock, and would
	// fail the second rather than wait).
	pkgMu sync.Mutex
	// trafficMu keeps web.traffic runs one at a time.
	trafficMu sync.Mutex
}

// Defaults fill in the paths of an AlmaLinux 9 server with Apache and Remi.
func (m *Manager) Defaults() {
	set := func(p *string, v string) {
		if *p == "" {
			*p = v
		}
	}
	set(&m.Server, "apache")
	set(&m.Include, "/etc/httpd/conf.d/lpanel.conf")
	set(&m.Sites, "/etc/httpd/lpanel/sites")
	set(&m.Htpasswd, "/etc/httpd/lpanel/htpasswd")
	set(&m.LogDir, "/var/log/httpd/lpanel")
	set(&m.PHPRoot, "/opt/remi")
	set(&m.PHPConf, "/etc/opt/remi")
	set(&m.PHPRun, "/var/opt/remi")
	set(&m.WebGroup, "apache")
	set(&m.PanelPHP, "83")
	set(&m.TrafficState, "/var/lib/lpa/traffic.json")
	if m.ProbeFor == 0 {
		m.ProbeFor = 10 * time.Second
	}
	if m.Probe == nil {
		m.Probe = httpProbe
	}
	if m.MakeDirs == nil {
		m.MakeDirs = makeDirs
	}
	if m.Chown == nil {
		m.Chown = os.Chown
	}
	if m.GroupID == nil {
		m.GroupID = func(name string) (int, error) {
			g, err := user.LookupGroup(name)
			if err != nil {
				return 0, err
			}
			return strconv.Atoi(g.Gid)
		}
	}
}

func (m *Manager) socket(version, username string) string {
	return fmt.Sprintf("%s/php%s/run/php-fpm/lpanel-%s.sock", m.PHPRun, version, username)
}

func (m *Manager) poolFile(version, username string) string {
	return fmt.Sprintf("%s/php%s/php-fpm.d/lpanel-%s.conf", m.PHPConf, version, username)
}

func (m *Manager) siteFile(username string) string {
	return filepath.Join(m.Sites, username+".conf")
}

func badArgs(format string, a ...any) error {
	return protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf(format, a...))
}

// checkHost accepts a lowercase domain name with at least two labels; a
// leading "*." too when wildcard is true.
func checkHost(name string, wildcard bool) error {
	h := name
	if wildcard {
		h = strings.TrimPrefix(h, "*.")
	}
	if len(name) > 253 || strings.Count(h, ".") < 1 {
		return badArgs("%q is not a domain name", name)
	}
	for _, l := range strings.Split(h, ".") {
		if !labelRule.MatchString(l) {
			return badArgs("%q is not a domain name", name)
		}
	}
	return nil
}

// checkDocroot accepts public_html or a folder under it: plain names, no
// "." or "..", no hidden folders. Everything served lives in public_html,
// which has the web server's group and the SELinux label for web content.
func checkDocroot(rel string) error {
	parts := strings.Split(rel, "/")
	if parts[0] != "public_html" || len(parts) > 6 {
		return badArgs("document root %q must be public_html or a folder inside it, at most 5 deep", rel)
	}
	for _, p := range parts {
		if !partRule.MatchString(p) || p == "." || p == ".." {
			return badArgs("document root %q may only hold letters, digits, dots, dashes and underscores", rel)
		}
	}
	return nil
}

func (a *ApplyArgs) check() error {
	if !userRule.MatchString(a.Username) {
		return badArgs("username %q is not 2–16 lowercase letters and digits", a.Username)
	}
	if len(a.Sites) == 0 || len(a.Sites) > 2000 {
		return badArgs("an account has 1 to 2000 sites, not %d", len(a.Sites))
	}
	if a.Pool.MaxChildren == 0 {
		a.Pool.MaxChildren = 5
	}
	if a.Pool.MaxChildren < 1 || a.Pool.MaxChildren > 500 {
		return badArgs("pool.max_children must be 1 to 500")
	}
	names := map[string]bool{}
	claim := func(n string) error {
		if names[n] {
			return badArgs("%q appears twice", n)
		}
		names[n] = true
		return nil
	}
	for _, s := range a.Sites {
		if err := checkHost(s.Name, false); err != nil {
			return err
		}
		if err := claim(s.Name); err != nil {
			return err
		}
		if len(s.Aliases) > 200 {
			return badArgs("%s has more than 200 aliases", s.Name)
		}
		for _, al := range s.Aliases {
			if err := checkHost(al, true); err != nil {
				return err
			}
			if err := claim(al); err != nil {
				return err
			}
		}
		if err := checkDocroot(s.Docroot); err != nil {
			return err
		}
		if !versionRule.MatchString(s.PHP) {
			return badArgs("PHP version %q is not two digits such as \"83\"", s.PHP)
		}
		if err := s.checkFeatures(); err != nil {
			return err
		}
	}
	return checkPHPSettings(a.PHPSettings)
}

// installed lists the PHP versions with an FPM binary, sorted.
func (m *Manager) installed() []string {
	matches, _ := filepath.Glob(filepath.Join(m.PHPRoot, "php*", "root", "usr", "sbin", "php-fpm"))
	var out []string
	for _, p := range matches {
		v := strings.TrimPrefix(filepath.Base(filepath.Dir(filepath.Dir(filepath.Dir(filepath.Dir(p))))), "php")
		if versionRule.MatchString(v) {
			out = append(out, v)
		}
	}
	sort.Strings(out)
	return out
}

func (m *Manager) on() error {
	if m.Server != "apache" {
		return protocol.Fail(CodeWebOff, "this server is set up without a web server (lpa.json \"web\": \"off\")")
	}
	return nil
}

// file is one file the pipeline writes or removes, with what was there.
type file struct {
	path     string
	content  []byte // nil: remove
	old      []byte
	existed  bool
	reloadPH string // the PHP version to reload for it, or "" for Apache
	// mode and gid of the file; 0644 root when mode is 0. Logins of
	// protected folders are 0640 with the web server's group.
	mode os.FileMode
	gid  int
	// data files take no part in Apache's test (htpasswd files).
	data bool
}

func (f file) fileMode() os.FileMode {
	if f.mode == 0 {
		return 0o644
	}
	return f.mode
}

// Apply makes the account's sites and pools what a says.
func (m *Manager) Apply(ctx context.Context, a ApplyArgs) (Result, error) {
	if err := m.on(); err != nil {
		return Result{}, err
	}
	if err := a.check(); err != nil {
		return Result{}, err
	}
	uid, gid, home, err := m.Owner(a.Username)
	if err != nil {
		return Result{}, err
	}
	have := map[string]bool{}
	for _, v := range m.installed() {
		have[v] = true
	}
	wanted := versions(a)
	for _, v := range wanted {
		if !have[v] {
			return Result{}, protocol.Fail(CodePHPMissing, fmt.Sprintf("PHP %s.%s is not installed on this server", v[:1], v[1:]))
		}
	}

	m.mu.Lock()
	defer m.mu.Unlock()
	if err := m.prepare(); err != nil {
		return Result{}, err
	}

	webGID, err := m.GroupID(m.WebGroup)
	if err != nil {
		return Result{}, fmt.Errorf("web group %s: %w", m.WebGroup, err)
	}
	if err := m.privateDir(m.Htpasswd, webGID); err != nil {
		return Result{}, err
	}
	if !a.Suspended {
		roots := make([]string, 0, len(a.Sites))
		for _, s := range a.Sites {
			roots = append(roots, s.Docroot)
		}
		made, err := m.MakeDirs(home, roots, uid, gid, webGID)
		if err != nil {
			return Result{}, err
		}
		if m.SELinux && len(made) > 0 {
			if err := sysexec.Must(ctx, m.Run, "restorecon", made...); err != nil {
				return Result{}, err
			}
		}
	}

	site, rev := m.renderSites(a, home)
	files := []file{{path: m.siteFile(a.Username), content: site}}
	// A suspended account runs no PHP at all.
	pools := wanted
	if a.Suspended {
		pools = nil
	}
	keep := map[string]bool{}
	for _, v := range pools {
		keep[v] = true
		files = append(files, file{path: m.poolFile(v, a.Username), content: m.renderPool(a, home, v), reloadPH: v})
	}
	for _, v := range m.poolsOf(a.Username) {
		if !keep[v] {
			files = append(files, file{path: m.poolFile(v, a.Username), reloadPH: v})
		}
	}
	// The logins of protected folders, and none for folders no longer
	// protected. A suspended account's sites ask for nothing, so it has none.
	logins := map[string]bool{}
	if !a.Suspended {
		for _, s := range a.Sites {
			for _, p := range s.Protected {
				path := m.htpasswdFile(a.Username, s.Name, p.Path)
				logins[path] = true
				files = append(files, file{path: path, content: renderHtpasswd(p), mode: 0o640, gid: webGID, data: true})
			}
		}
	}
	for _, path := range m.htpasswdOf(a.Username) {
		if !logins[path] {
			files = append(files, file{path: path, data: true})
		}
	}

	res := Result{Username: a.Username, Revision: rev, PHP: pools, Sites: len(a.Sites)}
	if res.PHP == nil {
		res.PHP = []string{}
	}
	changed, err := m.change(ctx, files, func(ctx context.Context) error {
		for _, s := range a.Sites {
			if err := m.probeUntil(ctx, s.Name, rev); err != nil {
				return err
			}
		}
		for _, v := range pools {
			if err := m.waitFor(ctx, m.socket(v, a.Username)); err != nil {
				return err
			}
		}
		return nil
	})
	res.Changed = changed
	return res, err
}

// Remove takes an account's sites and pools away. It is safe to repeat, and
// it only touches files named after the account in LPanel's own folders.
func (m *Manager) Remove(ctx context.Context, a NameArgs) (Result, error) {
	if err := m.on(); err != nil {
		return Result{}, err
	}
	if !userRule.MatchString(a.Username) {
		return Result{}, badArgs("username %q is not 2–16 lowercase letters and digits", a.Username)
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if err := m.prepare(); err != nil {
		return Result{}, err
	}
	files := []file{{path: m.siteFile(a.Username)}}
	for _, v := range m.poolsOf(a.Username) {
		files = append(files, file{path: m.poolFile(v, a.Username), reloadPH: v})
	}
	for _, path := range m.htpasswdOf(a.Username) {
		files = append(files, file{path: path, data: true})
	}
	changed, err := m.change(ctx, files, nil)
	if err == nil {
		// After the reload, so Apache no longer writes to them.
		err = m.forgetTraffic(a.Username)
	}
	return Result{Username: a.Username, Changed: changed, PHP: []string{}}, err
}

// RemoveFor is Remove for account.terminate's BeforeRemove hook. With no web
// server configured, or none installed, there is nothing to remove.
func (m *Manager) RemoveFor(ctx context.Context, username string) error {
	if m.Server != "apache" {
		return nil
	}
	if _, err := os.Stat(filepath.Dir(m.Include)); errors.Is(err, os.ErrNotExist) {
		return nil
	}
	_, err := m.Remove(ctx, NameArgs{Username: username})
	return err
}

// Info describes the web server and the PHP versions installed.
func (m *Manager) Info(ctx context.Context) (Info, error) {
	info := Info{Server: m.Server, PHP: []PHPVersion{}}
	if m.Server != "apache" {
		return info, nil
	}
	_, err := os.Stat(filepath.Join(filepath.Dir(m.Include), "..", "conf", "httpd.conf"))
	info.Installed = err == nil
	info.Running = m.active(ctx, "httpd")
	sites, _ := filepath.Glob(filepath.Join(m.Sites, "*.conf"))
	info.Accounts = len(sites)
	for _, v := range m.installed() {
		pools, _ := filepath.Glob(filepath.Join(m.PHPConf, "php"+v, "php-fpm.d", "lpanel-*.conf"))
		info.PHP = append(info.PHP, PHPVersion{Version: v, Pools: len(pools), Running: m.active(ctx, phpService(v))})
	}
	return info, nil
}

func phpService(v string) string { return "php" + v + "-php-fpm" }

func (m *Manager) active(ctx context.Context, unit string) bool {
	code, _, err := m.Run.Run(ctx, "systemctl", "is-active", "--quiet", unit)
	return err == nil && code == 0
}

// htpasswdOf lists the account's login files for protected folders.
func (m *Manager) htpasswdOf(username string) []string {
	matches, _ := filepath.Glob(filepath.Join(m.Htpasswd, username+"-*.htpasswd"))
	sort.Strings(matches)
	return matches
}

// privateDir makes dir 0750 root with the web server's group: Apache reads
// the logins in it, other local users do not. Its parent (/etc/httpd/lpanel,
// the agent's own) must be 0755: under the agent's 077 umask MkdirAll would
// make it 0700, and Apache's workers, which open the logins on each request
// as the web server's user, could not reach them (a 500, not a 401).
func (m *Manager) privateDir(dir string, gid int) error {
	parent := filepath.Dir(dir)
	if err := os.MkdirAll(parent, 0o755); err != nil {
		return err
	}
	if err := os.Chmod(parent, 0o755); err != nil {
		return err
	}
	if err := os.MkdirAll(dir, 0o750); err != nil {
		return err
	}
	if err := os.Chmod(dir, 0o750); err != nil {
		return err
	}
	return m.Chown(dir, 0, gid)
}

// poolsOf lists the PHP versions that have a pool file for the account.
func (m *Manager) poolsOf(username string) []string {
	matches, _ := filepath.Glob(filepath.Join(m.PHPConf, "php*", "php-fpm.d", "lpanel-"+username+".conf"))
	var out []string
	for _, p := range matches {
		v := strings.TrimPrefix(filepath.Base(filepath.Dir(filepath.Dir(p))), "php")
		if versionRule.MatchString(v) {
			out = append(out, v)
		}
	}
	sort.Strings(out)
	return out
}

// prepare makes LPanel's folders and include file, once per server.
func (m *Manager) prepare() error {
	if st, err := os.Stat(filepath.Dir(m.Include)); err != nil || !st.IsDir() {
		return protocol.Fail(CodeWebOff, "Apache is not installed here ("+filepath.Dir(m.Include)+" is missing)")
	}
	for _, d := range []string{m.Sites, m.LogDir} {
		if err := os.MkdirAll(d, 0o755); err != nil {
			return err
		}
	}
	want := renderInclude(m.Sites)
	if got, err := os.ReadFile(m.Include); err == nil && string(got) == string(want) {
		return nil
	}
	return writeAtomic(m.Include, want, 0o644)
}

// change runs the pipeline over files: test, swap, reload, probe, and roll
// back. It reports whether anything changed.
func (m *Manager) change(ctx context.Context, files []file, probe func(context.Context) error) (bool, error) {
	var todo []file
	for _, f := range files {
		old, err := os.ReadFile(f.path)
		switch {
		case err == nil:
			f.old, f.existed = old, true
		case !errors.Is(err, os.ErrNotExist):
			return false, err
		}
		if (f.content == nil && !f.existed) || (f.content != nil && f.existed && string(f.old) == string(f.content)) {
			continue
		}
		todo = append(todo, f)
	}
	if len(todo) == 0 {
		return false, nil
	}

	if err := m.test(ctx, todo); err != nil {
		return false, err
	}
	if err := m.swap(todo); err != nil {
		m.restore(todo)
		return false, err
	}
	err := m.reload(ctx, todo)
	if err == nil && probe != nil {
		pctx, cancel := context.WithTimeout(ctx, m.ProbeFor)
		err = probe(pctx)
		cancel()
	}
	if err == nil {
		return true, nil
	}

	// Put the old files back. Uses a context of its own: the operation's may
	// be what ran out.
	m.restore(todo)
	back := context.WithoutCancel(ctx)
	if rerr := m.reload(back, todo); rerr != nil {
		m.Log.Error("the old web configuration did not reload either", "err", rerr)
	}
	return false, protocol.Fail(CodeProbe, "the web server did not serve the change, so it was undone: "+err.Error())
}

// test checks every candidate before anything live changes.
func (m *Manager) test(ctx context.Context, todo []file) error {
	apache := false
	for _, f := range todo {
		if f.data {
			continue
		}
		if f.reloadPH == "" {
			apache = true
			continue
		}
		if f.content == nil {
			continue
		}
		if err := m.testPool(ctx, f); err != nil {
			return err
		}
	}
	if apache {
		return m.testApache(ctx, todo)
	}
	return nil
}

func (m *Manager) testPool(ctx context.Context, f file) error {
	dir, err := os.MkdirTemp(filepath.Join(m.PHPConf, "php"+f.reloadPH), ".lpanel-test-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(dir)
	pool := filepath.Join(dir, "pool.conf")
	main := filepath.Join(dir, "main.conf")
	if err := os.WriteFile(pool, f.content, 0o600); err != nil {
		return err
	}
	if err := os.WriteFile(main, []byte("[global]\nerror_log = syslog\ninclude = "+pool+"\n"), 0o600); err != nil {
		return err
	}
	code, out, err := m.Run.Run(ctx, "php-fpm"+f.reloadPH, "-t", "-y", main)
	if err != nil {
		return err
	}
	if code != 0 {
		return protocol.Fail(CodeRejected, "PHP "+f.reloadPH+" refused the pool: "+lastLines(out))
	}
	return nil
}

// testApache runs httpd -t over a copy of the sites folder with the
// candidates in it, and copies of every other account's file.
func (m *Manager) testApache(ctx context.Context, todo []file) error {
	stage, err := os.MkdirTemp(filepath.Dir(m.Sites), ".stage-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(stage)
	mine := map[string]file{}
	for _, f := range todo {
		if f.reloadPH == "" && !f.data {
			mine[filepath.Base(f.path)] = f
		}
	}
	live, err := filepath.Glob(filepath.Join(m.Sites, "*.conf"))
	if err != nil {
		return err
	}
	for _, p := range live {
		if _, ok := mine[filepath.Base(p)]; ok {
			continue
		}
		content, err := os.ReadFile(p)
		if err != nil {
			return err
		}
		if err := os.WriteFile(filepath.Join(stage, filepath.Base(p)), content, 0o644); err != nil {
			return err
		}
	}
	for name, f := range mine {
		if f.content == nil {
			continue
		}
		if err := os.WriteFile(filepath.Join(stage, name), f.content, 0o644); err != nil {
			return err
		}
	}
	code, out, err := m.Run.Run(ctx, "httpd", "-t", "-C", "Define LPANEL_SITES "+stage)
	if err != nil {
		return err
	}
	if code != 0 {
		return protocol.Fail(CodeRejected, "Apache refused the configuration: "+lastLines(out))
	}
	return nil
}

func (m *Manager) swap(todo []file) error {
	for _, f := range todo {
		if f.content == nil {
			if err := os.Remove(f.path); err != nil && !errors.Is(err, os.ErrNotExist) {
				return err
			}
			continue
		}
		if err := os.MkdirAll(filepath.Dir(f.path), 0o755); err != nil {
			return err
		}
		if err := m.write(f.path, f.content, f); err != nil {
			return err
		}
	}
	return nil
}

func (m *Manager) write(path string, content []byte, f file) error {
	if err := writeAtomic(path, content, f.fileMode()); err != nil {
		return err
	}
	if f.gid != 0 {
		return m.Chown(path, 0, f.gid)
	}
	return nil
}

func (m *Manager) restore(todo []file) {
	for _, f := range todo {
		var err error
		if f.existed {
			err = m.write(f.path, f.old, f)
		} else {
			err = os.Remove(f.path)
			if errors.Is(err, os.ErrNotExist) {
				err = nil
			}
		}
		if err != nil {
			m.Log.Error("could not put a web file back", "path", f.path, "err", err)
		}
	}
}

// reload restarts what the files concern: each PHP version first (a version
// left without pools is stopped, since PHP-FPM refuses to run with none),
// then Apache.
func (m *Manager) reload(ctx context.Context, todo []file) error {
	seen := map[string]bool{}
	apache := false
	for _, f := range todo {
		if f.data {
			continue // read by Apache on each request; nothing to reload
		}
		if f.reloadPH == "" {
			apache = true
			continue
		}
		v := f.reloadPH
		if seen[v] {
			continue
		}
		seen[v] = true
		left, _ := filepath.Glob(filepath.Join(m.PHPConf, "php"+v, "php-fpm.d", "*.conf"))
		var err error
		if len(left) == 0 {
			err = sysexec.Must(ctx, m.Run, "systemctl", "stop", phpService(v))
		} else {
			err = sysexec.Must(ctx, m.Run, "systemctl", "enable", "--quiet", phpService(v))
			if err == nil {
				err = sysexec.Must(ctx, m.Run, "systemctl", "reload-or-restart", phpService(v))
			}
		}
		if err != nil {
			return err
		}
	}
	if apache {
		if err := sysexec.Must(ctx, m.Run, "systemctl", "enable", "--quiet", "httpd"); err != nil {
			return err
		}
		return sysexec.Must(ctx, m.Run, "systemctl", "reload-or-restart", "httpd")
	}
	return nil
}

func (m *Manager) probeUntil(ctx context.Context, host, rev string) error {
	var last error
	for {
		if last = m.Probe(ctx, host, rev); last == nil {
			return nil
		}
		select {
		case <-ctx.Done():
			return fmt.Errorf("%s: %w", host, last)
		case <-time.After(200 * time.Millisecond):
		}
	}
}

func (m *Manager) waitFor(ctx context.Context, sock string) error {
	for {
		if _, err := os.Stat(sock); err == nil {
			return nil
		}
		select {
		case <-ctx.Done():
			return fmt.Errorf("PHP-FPM did not open %s", sock)
		case <-time.After(200 * time.Millisecond):
		}
	}
}

func writeAtomic(path string, content []byte, mode os.FileMode) error {
	tmp, err := os.CreateTemp(filepath.Dir(path), ".lpanel-")
	if err != nil {
		return err
	}
	name := tmp.Name()
	_, werr := tmp.Write(content)
	cerr := tmp.Close()
	if werr == nil {
		werr = cerr
	}
	if werr == nil {
		werr = os.Chmod(name, mode)
	}
	if werr == nil {
		werr = os.Rename(name, path)
	}
	if werr != nil {
		os.Remove(name)
	}
	return werr
}

func lastLines(s string) string {
	lines := strings.Split(strings.TrimSpace(s), "\n")
	if len(lines) > 6 {
		lines = lines[len(lines)-6:]
	}
	return strings.Join(lines, " / ")
}
