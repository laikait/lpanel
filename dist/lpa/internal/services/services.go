// Package services lists the services LPanel's server runs (the web servers,
// PHP-FPM, the database, DNS, mail, FTP, SSH, the firewall and the panel's own)
// and starts, stops and restarts them through systemd.
//
// The list is fixed: a unit that is not in it cannot be named, whatever the
// panel sends. Each unit also says which actions it allows: the services the
// panel (the agent, the panel's own web server and PHP, the database) or the
// administrator's way in (SSH) depends on cannot be stopped from here, as
// there would be no way to start them again.
package services

import (
	"context"
	"fmt"
	"regexp"
	"strconv"
	"strings"
	"time"

	"lpa/internal/protocol"
	"lpa/internal/sysexec"
)

// Error codes.
const (
	CodeRefused = "service_refused"
	CodeFailed  = "service_failed"
)

// Actions systemd can be asked for.
var allActions = []string{"start", "stop", "restart", "reload", "enable", "disable"}

// entry is one unit LPanel knows.
type entry struct {
	Unit    string
	Label   string
	Group   string
	Allowed []string
}

func all(unit, label, group string) entry { return entry{unit, label, group, allActions} }

func some(unit, label, group string, allowed ...string) entry {
	return entry{unit, label, group, allowed}
}

// catalogue is every unit that can be listed or acted on (PHP versions are added by phpEntries).
var catalogue = []entry{
	some("lpa.service", "LPanel agent", "panel"),
	some("lpanel-authd.socket", "Sign-in helper", "panel"),
	some("lpanel-nginx.service", "Panel web server", "panel", "restart", "reload"),
	some("lpanel-php-fpm.service", "Panel PHP", "panel", "restart", "reload"),
	some("lpanel-scheduler.timer", "Panel scheduler", "panel", "start", "restart"),
	all("httpd.service", "Apache", "web"),
	all("nginx.service", "nginx", "web"),
	some("mariadb.service", "MariaDB", "database", "start", "restart"),
	all("pdns.service", "PowerDNS", "dns"),
	all("exim.service", "Exim (mail delivery)", "mail"),
	all("dovecot.service", "Dovecot (mailboxes)", "mail"),
	all("rspamd.service", "Rspamd (spam filter)", "mail"),
	all("clamd@scan.service", "ClamAV (virus scanner)", "mail"),
	all("pure-ftpd.service", "Pure-FTPd", "ftp"),
	some("sshd.service", "SSH", "system", "start", "restart", "reload"),
	some("firewalld.service", "firewalld", "system", "start", "restart", "reload", "enable"),
	all("crond.service", "Cron", "system"),
	all("chronyd.service", "Time sync (chrony)", "system"),
}

// phpVersions are the versions the panel can install (two digits: 5.6 is 56).
var phpVersions = []string{"56", "70", "71", "72", "73", "74", "80", "81", "82", "83", "84", "85"}

func catalogueWithPHP() []entry {
	out := append([]entry(nil), catalogue...)
	for _, v := range phpVersions {
		out = append(out, all("php"+v+"-php-fpm.service", "PHP "+v[:1]+"."+v[1:]+" (sites)", "php"))
	}
	return out
}

// Manager reads and changes the services.
type Manager struct {
	Run sysexec.Runner
	// Now is the clock (tests change it).
	Now func() time.Time
}

// Service is one service as systemd has it.
type Service struct {
	Unit    string   `json:"unit"`
	Label   string   `json:"label"`
	Group   string   `json:"group"`
	Allowed []string `json:"allowed"`
	// Installed is false when systemd has no such unit (the service is not set up on this server).
	Installed bool `json:"installed"`
	// Active is systemd's ActiveState (active, inactive, failed, activating…), Sub its SubState.
	Active string `json:"active"`
	Sub    string `json:"sub"`
	// Enabled is true when the unit starts at boot.
	Enabled bool `json:"enabled"`
	// Since is when it last became active, as an RFC 3339 time; empty when it is not.
	Since string `json:"since"`
	// MemoryBytes is what it holds now (0 when systemd does not count it); Restarts how often systemd restarted it itself.
	MemoryBytes int64 `json:"memory_bytes"`
	Restarts    int   `json:"restarts"`
}

// Running is true when the service is up (a timer or socket being "active" is up too).
func (s Service) Running() bool { return s.Active == "active" }

// List reads every catalogue unit that is installed on this server.
func (m *Manager) List(ctx context.Context) ([]Service, error) {
	entries := catalogueWithPHP()
	units := make([]string, 0, len(entries))
	for _, e := range entries {
		units = append(units, e.Unit)
	}
	args := append([]string{"show", "--property=Id,LoadState,ActiveState,SubState,UnitFileState,ActiveEnterTimestamp,MemoryCurrent,NRestarts", "--"}, units...)
	code, out, err := m.Run.Run(ctx, "systemctl", args...)
	if err != nil {
		return nil, err
	}
	if code != 0 {
		return nil, protocol.Fail(CodeFailed, "systemctl could not list the services: "+strings.TrimSpace(out))
	}
	byUnit := parseShow(out)
	var list []Service
	for _, e := range entries {
		p, ok := byUnit[e.Unit]
		if !ok || p["LoadState"] != "loaded" {
			continue
		}
		s := Service{
			Unit: e.Unit, Label: e.Label, Group: e.Group, Allowed: append([]string{}, e.Allowed...), Installed: true,
			Active: p["ActiveState"], Sub: p["SubState"],
			Enabled: p["UnitFileState"] == "enabled",
			Since:   parseTime(p["ActiveEnterTimestamp"], m.now()),
		}
		if mem, err := strconv.ParseInt(p["MemoryCurrent"], 10, 64); err == nil && mem >= 0 && mem < 1<<60 {
			s.MemoryBytes = mem
		}
		s.Restarts, _ = strconv.Atoi(p["NRestarts"])
		if s.Active != "active" {
			s.Since = ""
		}
		list = append(list, s)
	}
	return list, nil
}

func (m *Manager) now() time.Time {
	if m.Now != nil {
		return m.Now()
	}
	return time.Now()
}

// parseShow reads `systemctl show` for several units: blocks of Key=Value
// lines, a blank line between, each with its Id.
func parseShow(out string) map[string]map[string]string {
	res := map[string]map[string]string{}
	cur := map[string]string{}
	flush := func() {
		if id := cur["Id"]; id != "" {
			res[id] = cur
		}
		cur = map[string]string{}
	}
	for _, line := range strings.Split(out, "\n") {
		line = strings.TrimRight(line, "\r")
		if line == "" {
			flush()
			continue
		}
		if k, v, ok := strings.Cut(line, "="); ok {
			cur[k] = v
		}
	}
	flush()
	return res
}

// systemd's "Mon 2026-11-24 10:00:00 UTC", or empty.
var timeRule = regexp.MustCompile(`^[A-Z][a-z]{2} (\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)(?: ([A-Z]{2,5}))?$`)

func parseTime(s string, _ time.Time) string {
	g := timeRule.FindStringSubmatch(strings.TrimSpace(s))
	if g == nil {
		return ""
	}
	loc := time.Local
	if g[2] == "UTC" {
		loc = time.UTC
	}
	t, err := time.ParseInLocation("2006-01-02 15:04:05", g[1], loc)
	if err != nil {
		return ""
	}
	return t.UTC().Format(time.RFC3339)
}

// ActArgs are services.act's arguments.
type ActArgs struct {
	Unit   string `json:"unit"`
	Action string `json:"action"`
}

// Act does one thing to one unit and returns the unit as it is after.
func (m *Manager) Act(ctx context.Context, a ActArgs) (Service, error) {
	var found *entry
	for _, e := range catalogueWithPHP() {
		if e.Unit == a.Unit {
			e := e
			found = &e
			break
		}
	}
	if found == nil {
		return Service{}, protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf("%q is not a service LPanel manages", a.Unit))
	}
	known := false
	for _, x := range allActions {
		known = known || x == a.Action
	}
	if !known {
		return Service{}, protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf("%q is not an action: use %s", a.Action, strings.Join(allActions, ", ")))
	}
	allowed := false
	for _, x := range found.Allowed {
		allowed = allowed || x == a.Action
	}
	if !allowed {
		return Service{}, protocol.Fail(CodeRefused, fmt.Sprintf("%s cannot be %s from here: %s", found.Label, past(a.Action), why(found)))
	}
	if err := sysexec.Must(ctx, m.Run, "systemctl", a.Action, "--", a.Unit); err != nil {
		return Service{}, protocol.Fail(CodeFailed, fmt.Sprintf("%s could not be %s: %s", found.Label, past(a.Action), err.Error()))
	}
	list, err := m.List(ctx)
	if err != nil {
		return Service{}, err
	}
	for _, s := range list {
		if s.Unit == a.Unit {
			return s, nil
		}
	}
	return Service{Unit: a.Unit, Label: found.Label, Group: found.Group, Allowed: found.Allowed}, nil
}

func past(action string) string {
	switch action {
	case "stop":
		return "stopped"
	case "enable":
		return "enabled"
	case "disable":
		return "disabled"
	}
	return action + "ed"
}

func why(e *entry) string {
	switch {
	case e.Group == "panel" || e.Unit == "mariadb.service":
		return "the panel depends on it, and nothing could start it again"
	case e.Unit == "sshd.service" || e.Unit == "firewalld.service":
		return "it is how the server is reached and protected"
	}
	return "it is not allowed"
}
