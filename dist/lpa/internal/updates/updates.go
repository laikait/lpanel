// Package updates keeps the server up to date: the operating system's
// packages (dnf), PHP (Remi's packages, the same way), and LPanel itself
// (a signed release, installed by its own installer, rolled back if that
// fails; see release.go).
//
// What may be updated is said by a scope the agent knows (everything, the
// security errata only, PHP only, or named packages that are installed now),
// never by a command: names and exclusions are checked against what package
// names look like and passed to dnf as arguments, without a shell. Nothing is
// installed that was not installed before, except what an update itself
// brings in (a new kernel). After an update the services still running old
// code are restarted, those LPanel knows to be safe to restart; the rest, and
// whether the server needs a reboot, are reported.
package updates

import (
	"context"
	"log/slog"
	"net/http"
	"os"
	"regexp"
	"slices"
	"sort"
	"strings"
	"time"

	"lpa/internal/protocol"
	"lpa/internal/sysexec"
)

// Error codes.
const (
	CodeDNF       = "updates_dnf"
	CodeBusy      = "updates_busy"
	CodeNoChannel = "updates_no_channel"
	CodeRelease   = "updates_release"
	CodeNotNewer  = "updates_not_newer"
)

// Scopes of an operating system update.
const (
	ScopeAll      = "all"
	ScopeSecurity = "security"
	ScopePHP      = "php"
	ScopePackages = "packages"
)

var (
	nameRule = regexp.MustCompile(`^[A-Za-z0-9_][A-Za-z0-9._+-]{0,127}$`)
	globRule = regexp.MustCompile(`^[A-Za-z0-9_*?\[][A-Za-z0-9._+*?\[\]-]{0,99}$`)
	unitRule = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.@:-]{0,127}\.service$`)
	// phpGlob is every package of every Remi PHP version (php83-php-fpm, php83-runtime…).
	phpGlob = "php[5-8][0-9]-*"
	// restartNow are the services restarted as soon as an update leaves them on old code.
	restartNow = regexp.MustCompile(`^(httpd|nginx|php[5-8][0-9]-php-fpm|pdns|exim|dovecot|rspamd|clamd@scan|pure-ftpd|crond|chronyd|sshd|mariadb|firewalld|rsyslog|lpanel-authd)\.service$`)
	// restartSoon are the panel's own: restarted a few seconds after the
	// operation has answered, so the page that asked for the update gets its answer.
	restartSoon = map[string]bool{"lpanel-php-fpm.service": true, "lpanel-nginx.service": true}
)

// Manager updates the server.
type Manager struct {
	Run sysexec.Runner
	Log *slog.Logger
	// Now is the clock (tests change it).
	Now func() time.Time
	// OSRelease is the file the running kernel's version is read from.
	OSRelease string

	// Channel is the address of the release manifest (lpa.json updates.url);
	// empty means LPanel itself is not updated from here.
	Channel string
	// KeyFile holds the public keys releases are signed with, one per line,
	// base64 (lpa.json updates.key_file).
	KeyFile string
	// State is where downloaded releases, the upgrade's log, its status and
	// the copy to roll back to are kept (/var/lib/lpa/updates).
	State string
	// PanelDir is where the panel is installed; its VERSION file says which release it is.
	PanelDir string
	// HTTP fetches the manifest and the release.
	HTTP *http.Client
	// MaxBundle is the largest release accepted, in bytes.
	MaxBundle int64
}

// Defaults fill in the paths of an AlmaLinux 9 server.
func (m *Manager) Defaults() {
	set := func(p *string, v string) {
		if *p == "" {
			*p = v
		}
	}
	set(&m.OSRelease, "/proc/sys/kernel/osrelease")
	set(&m.KeyFile, "/etc/lpa/release.pub")
	set(&m.State, "/var/lib/lpa/updates")
	set(&m.PanelDir, "/usr/local/lpanel")
	if m.HTTP == nil {
		m.HTTP = &http.Client{Timeout: 10 * time.Minute}
	}
	if m.MaxBundle == 0 {
		m.MaxBundle = 1 << 30
	}
}

// PHPUpdate is a PHP version with a newer release waiting.
type PHPUpdate struct {
	Version string `json:"version"`
	From    string `json:"from"`
	To      string `json:"to"`
}

// Reboot says whether the server runs on code an update replaced that only a reboot brings into use.
type Reboot struct {
	// Known is false when the server could not say (dnf's needs-restarting is missing).
	Known  bool     `json:"known"`
	Needed bool     `json:"needed"`
	Why    []string `json:"why"`
	// Scheduled is true while a reboot asked for from the panel is on its way.
	Scheduled bool `json:"scheduled"`
}

// Check is what is waiting to be installed.
type Check struct {
	Checked  string      `json:"checked"`
	Kernel   string      `json:"kernel"`
	Packages []Package   `json:"packages"`
	Security int         `json:"security"`
	PHP      []PHPUpdate `json:"php"`
	// Restart are the services running old code now (a reboot is not needed for them).
	Restart []string `json:"restart"`
	Reboot  Reboot   `json:"reboot"`
	Panel   Release  `json:"panel"`
}

// Check refreshes the repositories' lists and says what can be updated.
func (m *Manager) Check(ctx context.Context) (Check, error) {
	sysexec.Packages.Lock()
	defer sysexec.Packages.Unlock()

	c := Check{Checked: m.now().UTC().Format(time.RFC3339), Kernel: m.kernel(), Packages: []Package{}, PHP: []PHPUpdate{}, Restart: []string{}}
	code, out, err := m.Run.Run(ctx, "dnf", "-q", "--refresh", "repoquery", "--upgrades", "--latest-limit", "1", "--qf", repoqueryFormat)
	if err != nil {
		return c, err
	}
	if code != 0 {
		return c, protocol.Fail(CodeDNF, "dnf could not read the repositories: "+tail(out))
	}
	c.Packages = parseRepoquery(out)
	installed, err := m.installed(ctx)
	if err != nil {
		return c, err
	}
	// Errata are optional: a repository without them (Remi, most of EPEL) just has none.
	errata := map[string]advisory{}
	if code, out, err := m.Run.Run(ctx, "dnf", "-q", "updateinfo", "list", "--updates"); err == nil && code == 0 {
		errata = parseUpdateinfo(out)
	}
	for i := range c.Packages {
		p := &c.Packages[i]
		p.From = strings.Join(installed[key(p.Name, p.Arch)], ", ")
		if a, ok := errata[key(p.Name, p.Arch)]; ok {
			p.Advisory, p.Kind, p.Severity = a.ID, a.Kind, a.Severity
		}
		if p.Security() {
			c.Security++
		}
		if v, ok := strings.CutPrefix(p.Name, "php"); ok && len(v) > 2 && v[2:] == "-php-common" && strings.Trim(v[:2], "0123456789") == "" {
			c.PHP = append(c.PHP, PHPUpdate{Version: v[:2], From: trimVersion(p.From), To: trimVersion(p.To)})
		}
	}
	c.Restart = m.needsRestart(ctx)
	c.Reboot = m.reboot(ctx)
	c.Panel = m.Release(ctx)
	return c, nil
}

// ApplyArgs says what to update.
type ApplyArgs struct {
	// Scope is all, security (the packages a security erratum updates), php
	// (every installed PHP version's packages) or packages (Packages, by name).
	Scope    string   `json:"scope"`
	Packages []string `json:"packages"`
	// Exclude are package names (or patterns: "kernel*") never to update.
	Exclude []string `json:"exclude"`
}

// Change is one package an update changed; From is empty for one it brought in.
type Change struct {
	Name string `json:"name"`
	Arch string `json:"arch"`
	From string `json:"from"`
	To   string `json:"to"`
}

// Applied is what an update did.
type Applied struct {
	Scope    string   `json:"scope"`
	Upgraded []Change `json:"upgraded"`
	// Restarted were running old code and were restarted; RestartFailed did not come back;
	// Soon are the panel's own, restarted a few seconds from now; Pending need somebody
	// (or a reboot).
	Restarted     []string `json:"restarted"`
	RestartFailed []string `json:"restart_failed"`
	Soon          []string `json:"soon"`
	Pending       []string `json:"pending"`
	Reboot        Reboot   `json:"reboot"`
}

func (a ApplyArgs) check() error {
	switch a.Scope {
	case ScopeAll, ScopeSecurity, ScopePHP:
		if len(a.Packages) > 0 {
			return protocol.Fail(protocol.CodeBadArgs, "packages are named only with the scope \"packages\"")
		}
	case ScopePackages:
		if len(a.Packages) == 0 || len(a.Packages) > 200 {
			return protocol.Fail(protocol.CodeBadArgs, "name between 1 and 200 packages")
		}
		for _, n := range a.Packages {
			if !nameRule.MatchString(n) {
				return protocol.Fail(protocol.CodeBadArgs, "\""+n+"\" is not a package name")
			}
		}
	default:
		return protocol.Fail(protocol.CodeBadArgs, "the scope must be all, security, php or packages")
	}
	if len(a.Exclude) > 50 {
		return protocol.Fail(protocol.CodeBadArgs, "at most 50 exclusions")
	}
	for _, e := range a.Exclude {
		if !globRule.MatchString(e) {
			return protocol.Fail(protocol.CodeBadArgs, "\""+e+"\" is not a package name or pattern")
		}
	}
	return nil
}

// Apply updates what the scope says, then restarts what runs old code.
func (m *Manager) Apply(ctx context.Context, a ApplyArgs) (Applied, error) {
	if err := a.check(); err != nil {
		return Applied{}, err
	}
	sysexec.Packages.Lock()
	defer sysexec.Packages.Unlock()

	before, err := m.installed(ctx)
	if err != nil {
		return Applied{}, err
	}
	if a.Scope == ScopePackages {
		for _, n := range a.Packages {
			if !hasName(before, n) {
				return Applied{}, protocol.Fail(protocol.CodeBadArgs, n+" is not installed")
			}
		}
	}
	args := []string{"-y", "-q", "--setopt=install_weak_deps=False"}
	for _, e := range a.Exclude {
		args = append(args, "--exclude="+e)
	}
	args = append(args, "upgrade")
	switch a.Scope {
	case ScopeSecurity:
		args = append(args, "--security")
	case ScopePHP:
		args = append(args, phpGlob)
	case ScopePackages:
		args = append(args, a.Packages...)
	}
	code, out, err := m.Run.Run(ctx, "dnf", args...)
	if err != nil {
		return Applied{}, err
	}
	if code != 0 && !strings.Contains(out, "No match for argument") {
		return Applied{}, protocol.Fail(CodeDNF, "dnf upgrade failed: "+tail(out))
	}
	after, err := m.installed(ctx)
	if err != nil {
		return Applied{}, err
	}
	res := Applied{Scope: a.Scope, Upgraded: changes(before, after), Restarted: []string{}, RestartFailed: []string{}, Soon: []string{}, Pending: []string{}}
	var soon []string
	for _, unit := range m.needsRestart(ctx) {
		switch {
		case restartNow.MatchString(unit):
			if c, _, err := m.Run.Run(ctx, "systemctl", "try-restart", unit); err == nil && c == 0 {
				res.Restarted = append(res.Restarted, unit)
			} else {
				res.RestartFailed = append(res.RestartFailed, unit)
			}
		case restartSoon[unit]:
			soon = append(soon, unit)
		default:
			res.Pending = append(res.Pending, unit)
		}
	}
	if len(soon) > 0 {
		args := append([]string{"--on-active=15", "--collect", "--description=LPanel: restart the panel after an update", "/usr/bin/systemctl", "try-restart"}, soon...)
		if c, _, err := m.Run.Run(ctx, "systemd-run", args...); err == nil && c == 0 {
			res.Soon = soon
		} else {
			res.Pending = append(res.Pending, soon...)
		}
	}
	res.Reboot = m.reboot(ctx)
	if m.Log != nil {
		m.Log.Info("updates applied", "scope", a.Scope, "packages", len(res.Upgraded), "restarted", len(res.Restarted), "reboot", res.Reboot.Needed)
	}
	return res, nil
}

// RebootArgs asks for a reboot a minute from now, or calls one off.
type RebootArgs struct {
	Cancel bool `json:"cancel"`
}

const rebootUnit = "lpanel-reboot"

// ScheduleReboot reboots the server a minute from now (so the answer reaches
// the panel first), or calls off a reboot that has not happened yet.
func (m *Manager) ScheduleReboot(ctx context.Context, a RebootArgs) (Reboot, error) {
	if a.Cancel {
		m.Run.Run(ctx, "systemctl", "stop", rebootUnit+".timer")
		return m.reboot(ctx), nil
	}
	if !m.rebootScheduled(ctx) {
		code, out, err := m.Run.Run(ctx, "systemd-run", "--on-active=60", "--unit="+rebootUnit, "--description=LPanel: reboot asked for in the panel", "/usr/bin/systemctl", "reboot")
		if err != nil {
			return Reboot{}, err
		}
		if code != 0 {
			return Reboot{}, protocol.Fail(CodeDNF, "the reboot could not be scheduled: "+tail(out))
		}
	}
	return m.reboot(ctx), nil
}

func (m *Manager) rebootScheduled(ctx context.Context) bool {
	code, _, err := m.Run.Run(ctx, "systemctl", "is-active", "--quiet", rebootUnit+".timer")
	return err == nil && code == 0
}

// installed is every installed package, name.arch to version.
func (m *Manager) installed(ctx context.Context) (map[string][]string, error) {
	code, out, err := m.Run.Run(ctx, "rpm", "-qa", "--qf", rpmFormat)
	if err != nil {
		return nil, err
	}
	if code != 0 {
		return nil, protocol.Fail(CodeDNF, "rpm could not list the installed packages: "+tail(out))
	}
	return parseInstalled(out), nil
}

func (m *Manager) needsRestart(ctx context.Context) []string {
	code, out, err := m.Run.Run(ctx, "dnf", "-q", "needs-restarting", "-s")
	if err != nil || code != 0 {
		return []string{}
	}
	if units := parseNeedsRestarting(out); units != nil {
		return units
	}
	return []string{}
}

func (m *Manager) reboot(ctx context.Context) Reboot {
	r := Reboot{Why: []string{}, Scheduled: m.rebootScheduled(ctx)}
	code, out, err := m.Run.Run(ctx, "dnf", "-q", "needs-restarting", "-r")
	if err != nil || strings.Contains(out, "No such command") {
		return r
	}
	switch code {
	case 0:
		r.Known = true
	case 1:
		r.Known, r.Needed = true, true
		if why := parseReboot(out); why != nil {
			r.Why = why
		}
	}
	return r
}

func (m *Manager) kernel() string {
	data, err := os.ReadFile(m.OSRelease)
	if err != nil {
		return ""
	}
	return strings.TrimSpace(string(data))
}

func (m *Manager) now() time.Time {
	if m.Now != nil {
		return m.Now()
	}
	return time.Now()
}

// changes lists what differs between two package lists: updated, or new.
func changes(before, after map[string][]string) []Change {
	list := []Change{}
	for k, versions := range after {
		for _, to := range versions {
			if slices.Contains(before[k], to) {
				continue
			}
			dot := strings.LastIndex(k, ".")
			list = append(list, Change{Name: k[:dot], Arch: k[dot+1:], From: strings.Join(before[k], ", "), To: to})
		}
	}
	sort.Slice(list, func(i, j int) bool { return list[i].Name+list[i].Arch < list[j].Name+list[j].Arch })
	return list
}

func hasName(installed map[string][]string, name string) bool {
	for k := range installed {
		if strings.HasPrefix(k, name+".") && !strings.Contains(k[len(name)+1:], ".") {
			return true
		}
	}
	return false
}

// tail is the last lines of a tool's output, for an error message.
func tail(out string) string {
	lines := strings.Split(strings.TrimSpace(out), "\n")
	if len(lines) > 6 {
		lines = lines[len(lines)-6:]
	}
	s := strings.Join(lines, " / ")
	if len(s) > 600 {
		s = s[len(s)-600:]
	}
	return s
}
