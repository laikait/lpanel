// Package logs reads the server's logs for the panel: a fixed list of them
// (the journal of LPanel's own and the system's services, a few files under
// /var/log) and, for one account, its sites' access and error logs and its PHP
// error log.
//
// Nothing outside the list can be named. An account's logs are chosen by its
// user name (which must look like one) and found by the agent under the web
// servers' log folders, never from a path the panel sends. The account's PHP
// error log is the one file that the account itself controls (it lives in its
// home), so it is read with the account's own rights: a link planted there to
// a file only root can read yields nothing.
package logs

import (
	"bufio"
	"context"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"time"
	"unicode/utf8"

	"lpa/internal/protocol"
	"lpa/internal/sysexec"
)

// Error codes.
const (
	CodeSource  = "logs_source"
	CodeMissing = "logs_missing"
	CodeFailed  = "logs_failed"
)

const (
	// MaxLines is the most lines one read gives.
	MaxLines = 1000
	// DefaultLines is how many it gives when not asked.
	DefaultLines = 200
	// maxLineLen cuts a line that long, so one cannot fill an answer.
	maxLineLen = 2000
	// tailBytes is how much of the end of a file is looked at.
	tailBytes = 4 << 20
	// journalEntries is how many of the journal's latest entries are looked through.
	journalEntries = 5000
	maxFilter      = 100
)

var userRule = regexp.MustCompile(`^[a-z][a-z0-9]{1,15}$`)

// Source is one log that can be read.
type Source struct {
	// Name is how it is asked for: "journal:httpd", "file:maillog", "web.access".
	Name  string `json:"name"`
	Label string `json:"label"`
	Group string `json:"group"`
	// Size and Modified are of the file (zero for the journal).
	Size     int64  `json:"size"`
	Modified string `json:"modified,omitempty"`
}

type unit struct{ Name, Label, Group string }

// journalUnits are the services whose journal can be read.
var journalUnits = []unit{
	{"lpa", "LPanel agent", "panel"},
	{"lpanel-authd", "Sign-in helper", "panel"},
	{"lpanel-nginx", "Panel web server", "panel"},
	{"lpanel-php-fpm", "Panel PHP", "panel"},
	{"lpanel-scheduler", "Panel scheduler", "panel"},
	{"lpanel-upgrade", "LPanel upgrade", "panel"},
	{"httpd", "Apache", "web"},
	{"nginx", "nginx", "web"},
	{"mariadb", "MariaDB", "database"},
	{"pdns", "PowerDNS", "dns"},
	{"exim", "Exim", "mail"},
	{"dovecot", "Dovecot", "mail"},
	{"rspamd", "Rspamd", "mail"},
	{"clamd@scan", "ClamAV", "mail"},
	{"pure-ftpd", "Pure-FTPd", "ftp"},
	{"sshd", "SSH", "system"},
	{"firewalld", "firewalld", "system"},
	{"crond", "Cron", "system"},
	{"chronyd", "Time sync", "system"},
}

type file struct{ Name, Label, Group, Path string }

// Manager reads logs.
type Manager struct {
	Run sysexec.Runner
	// Files are the system's log files; Defaults fills them in.
	Files []file
	// AccessDir holds <user>.access.log of the front web server; ErrorDirs the
	// <user>.error.log of each server in use.
	AccessDir func() string
	ErrorDirs func() []string
	// Home is an account's home folder.
	Home func(user string) string
	// State is where reports are kept.
	State string
	Now   func() time.Time
}

// Defaults fills in what was not set.
func (m *Manager) Defaults() {
	if m.Files == nil {
		m.Files = []file{
			{"secure", "Sign-ins (SSH)", "system", "/var/log/secure"},
			{"messages", "System messages", "system", "/var/log/messages"},
			{"cron", "Cron", "system", "/var/log/cron"},
			{"maillog", "Mail (Dovecot)", "mail", "/var/log/maillog"},
			{"exim", "Mail delivery (Exim)", "mail", "/var/log/exim/main.log"},
			{"exim-reject", "Mail refused (Exim)", "mail", "/var/log/exim/reject.log"},
			{"pureftpd", "FTP", "ftp", "/var/log/pureftpd.log"},
			{"mariadb", "MariaDB", "database", "/var/log/mariadb/mariadb.log"},
			{"httpd-error", "Apache errors", "web", "/var/log/httpd/error_log"},
			{"nginx-error", "nginx errors", "web", "/var/log/nginx/error.log"},
		}
	}
	if m.Home == nil {
		m.Home = func(user string) string { return filepath.Join("/home", user) }
	}
	if m.Now == nil {
		m.Now = time.Now
	}
	if m.State == "" {
		m.State = "/var/lib/lpa/logs"
	}
}

// ValidUser says whether a name can be an account's.
func ValidUser(u string) bool { return userRule.MatchString(u) }

// Sources lists what can be read: the system's (those that exist) when user is
// empty, that account's own when it is not.
func (m *Manager) Sources(ctx context.Context, user string) ([]Source, error) {
	var out []Source

	if user != "" {
		if !ValidUser(user) {
			return nil, protocol.Fail(protocol.CodeBadArgs, "that is not an account name")
		}

		for _, s := range []struct{ name, label string }{
			{"web.access", "Site visits"}, {"web.error", "Site errors"}, {"php.error", "PHP errors"},
		} {
			src := Source{Name: s.name, Label: s.label, Group: "account"}
			if path, runAs := m.accountPath(s.name, user); path != "" {
				if st, err := os.Stat(path); err == nil {
					src.Size, src.Modified = st.Size(), st.ModTime().UTC().Format(time.RFC3339)
				} else if runAs == "" {
					continue
				}
			} else {
				continue
			}
			out = append(out, src)
		}

		return out, nil
	}

	for _, u := range journalUnits {
		out = append(out, Source{Name: "journal:" + u.Name, Label: u.Label, Group: u.Group})
	}

	for _, f := range m.Files {
		if st, err := os.Stat(f.Path); err == nil && st.Mode().IsRegular() {
			out = append(out, Source{Name: "file:" + f.Name, Label: f.Label, Group: f.Group, Size: st.Size(), Modified: st.ModTime().UTC().Format(time.RFC3339)})
		}
	}

	sort.SliceStable(out, func(i, j int) bool { return out[i].Group < out[j].Group })

	return out, nil
}

// ReadArgs asks for the end of a log.
type ReadArgs struct {
	Source string `json:"source"`
	// User is needed for the account's sources, and refused for the system's.
	User string `json:"user,omitempty"`
	// Lines is how many of the latest (default 200, at most 1000).
	Lines int `json:"lines,omitempty"`
	// Filter keeps the lines that contain this text, whatever its case.
	Filter string `json:"filter,omitempty"`
}

// Lines is the answer of a read.
type Lines struct {
	Source string   `json:"source"`
	Lines  []string `json:"lines"`
	// More is true when older lines exist than were looked through.
	More bool `json:"more"`
}

// Read gives the latest lines of a log.
func (m *Manager) Read(ctx context.Context, a ReadArgs) (Lines, error) {
	n := a.Lines
	if n == 0 {
		n = DefaultLines
	}

	if n < 1 || n > MaxLines {
		return Lines{}, protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf("lines is 1 to %d", MaxLines))
	}

	filter := strings.ToLower(a.Filter)
	if len(filter) > maxFilter || !utf8.ValidString(filter) || strings.ContainsAny(filter, "\x00\r\n") {
		return Lines{}, protocol.Fail(protocol.CodeBadArgs, "the filter is up to 100 characters on one line")
	}

	var raw []string
	more := false

	switch {
	case strings.HasPrefix(a.Source, "journal:"):
		if a.User != "" {
			return Lines{}, protocol.Fail(protocol.CodeBadArgs, "an account has no journal to read")
		}

		name := strings.TrimPrefix(a.Source, "journal:")
		known := false

		for _, u := range journalUnits {
			known = known || u.Name == name
		}

		if !known {
			return Lines{}, protocol.Fail(CodeSource, "there is no such log")
		}

		code, out, err := m.Run.Run(ctx, "journalctl", "-u", name+".service", "-n", fmt.Sprint(journalEntries), "--no-pager", "-q", "-o", "short-iso", "--no-hostname")
		if err != nil || code != 0 {
			return Lines{}, protocol.Fail(CodeFailed, "the journal could not be read")
		}

		raw = splitLines(out)
		more = len(raw) >= journalEntries
	case strings.HasPrefix(a.Source, "file:"):
		if a.User != "" {
			return Lines{}, protocol.Fail(protocol.CodeBadArgs, "that log is not an account's")
		}

		path := ""

		for _, f := range m.Files {
			if "file:"+f.Name == a.Source {
				path = f.Path
			}
		}

		if path == "" {
			return Lines{}, protocol.Fail(CodeSource, "there is no such log")
		}

		var err error
		if raw, more, err = tailFile(path); err != nil {
			return Lines{}, err
		}
	case a.Source == "web.access" || a.Source == "web.error" || a.Source == "php.error":
		if !ValidUser(a.User) {
			return Lines{}, protocol.Fail(protocol.CodeBadArgs, "an account's log needs the account's name")
		}

		path, runAs := m.accountPath(a.Source, a.User)
		if path == "" {
			return Lines{}, protocol.Fail(CodeSource, "there is no such log")
		}

		var err error
		if runAs != "" {
			code, out, rerr := m.Run.Run(ctx, "runuser", "-u", runAs, "--", "/usr/bin/tail", "-c", fmt.Sprint(tailBytes), path)
			if rerr != nil {
				return Lines{}, protocol.Fail(CodeFailed, "the log could not be read")
			}

			if code != 0 {
				return Lines{}, protocol.Fail(CodeMissing, "there is nothing in that log yet")
			}

			raw = splitLines(out)
			more = len(out) >= tailBytes
		} else if raw, more, err = tailFile(path); err != nil {
			return Lines{}, err
		}
	default:
		return Lines{}, protocol.Fail(CodeSource, "there is no such log")
	}

	out := make([]string, 0, n)

	for i := len(raw) - 1; i >= 0 && len(out) < n; i-- {
		line := raw[i]
		if filter != "" && !strings.Contains(strings.ToLower(line), filter) {
			continue
		}

		out = append(out, clean(line))
	}

	// Oldest first, as a log reads.
	for i, j := 0, len(out)-1; i < j; i, j = i+1, j-1 {
		out[i], out[j] = out[j], out[i]
	}

	return Lines{Source: a.Source, Lines: out, More: more}, nil
}

// accountPath is where an account's log is, and (for PHP's, which the account
// controls) the user to read it as.
func (m *Manager) accountPath(source, user string) (path, runAs string) {
	switch source {
	case "web.access":
		if m.AccessDir == nil {
			return "", ""
		}

		return filepath.Join(m.AccessDir(), user+".access.log"), ""
	case "web.error":
		if m.ErrorDirs == nil {
			return "", ""
		}

		for _, d := range m.ErrorDirs() {
			p := filepath.Join(d, user+".error.log")
			if _, err := os.Stat(p); err == nil {
				return p, ""
			}
		}

		if dirs := m.ErrorDirs(); len(dirs) > 0 {
			return filepath.Join(dirs[0], user+".error.log"), ""
		}
	case "php.error":
		return filepath.Join(m.Home(user), "logs", "php-error.log"), user
	}

	return "", ""
}

// tailFile reads the end of a regular file (the last few megabytes).
func tailFile(path string) ([]string, bool, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, false, protocol.Fail(CodeMissing, "there is nothing in that log yet")
	}
	defer f.Close()

	st, err := f.Stat()
	if err != nil || !st.Mode().IsRegular() {
		return nil, false, protocol.Fail(CodeMissing, "there is nothing in that log yet")
	}

	more := false
	if st.Size() > tailBytes {
		more = true
		if _, err := f.Seek(st.Size()-tailBytes, io.SeekStart); err != nil {
			return nil, false, protocol.Fail(CodeFailed, "the log could not be read")
		}
	}

	data, err := io.ReadAll(io.LimitReader(f, tailBytes+1))
	if err != nil {
		return nil, false, protocol.Fail(CodeFailed, "the log could not be read")
	}

	lines := splitLines(string(data))
	if more && len(lines) > 0 {
		lines = lines[1:] // the first is cut in the middle
	}

	return lines, more, nil
}

func splitLines(s string) []string {
	var out []string

	sc := bufio.NewScanner(strings.NewReader(s))
	sc.Buffer(make([]byte, 64<<10), 1<<20)

	for sc.Scan() {
		if t := strings.TrimRight(sc.Text(), "\r"); t != "" {
			out = append(out, t)
		}
	}

	return out
}

// clean makes a line safe to show: valid text, no control characters, not too long.
func clean(line string) string {
	if !utf8.ValidString(line) {
		line = strings.ToValidUTF8(line, "�")
	}

	line = strings.Map(func(r rune) rune {
		if r < 0x20 && r != '\t' || r == 0x7f {
			return ' '
		}

		return r
	}, line)

	if len(line) > maxLineLen {
		line = strings.ToValidUTF8(line[:maxLineLen], "") + "…"
	}

	return line
}
