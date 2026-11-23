package web

import (
	"bytes"
	"context"
	"crypto/sha256"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"

	"lpa/internal/fsutil"
	"lpa/internal/protocol"
	"lpa/internal/sysexec"
)

// The web application firewall: ModSecurity with the OWASP Core Rule Set,
// in Apache (so on the apache and hybrid stacks; nginx alone has none).
//
// The packages (mod_security, mod_security_crs) load the engine and the rules
// for every virtual host, with the engine on. LPanel does not rely on that:
// every site it writes says its own mode, so the mode is always one somebody
// chose.
//
//	off     the engine is off for the site
//	detect  rules run and log, nothing is refused (the default)
//	block   a request whose anomaly score is too high gets a 403
//
// A site may also name rules by number that it does not want (a false
// positive on its own application); nobody but the owner and the Admin panel
// can choose, and the choice stays inside the site's own VirtualHost.
//
// The paranoia level (1 to 4: how strict the rules are) is global, in a file
// the main include reads before the rules are loaded.

// WAF modes.
const (
	WAFOff    = "off"
	WAFDetect = "detect"
	WAFBlock  = "block"
)

// WAF is one site's firewall setting.
type WAF struct {
	Mode string `json:"mode"`
	// Exclude are rule numbers the site does not want.
	Exclude []int `json:"exclude"`
}

// MaxWAFExclusions is the most rules one site may switch off.
const MaxWAFExclusions = 50

func (w *WAF) check(name string) error {
	switch w.Mode {
	case WAFOff, WAFDetect, WAFBlock:
	default:
		return badArgs("%s: the firewall mode must be off, detect or block, not %q", name, w.Mode)
	}
	if len(w.Exclude) > MaxWAFExclusions {
		return badArgs("%s: at most %d firewall rules can be switched off", name, MaxWAFExclusions)
	}
	seen := map[int]bool{}
	for _, id := range w.Exclude {
		if id < 1 || id > 9999999 {
			return badArgs("%s: %d is not a rule number", name, id)
		}
		if seen[id] {
			return badArgs("%s: rule %d is listed twice", name, id)
		}
		seen[id] = true
	}
	return nil
}

// renderWAF writes a site's firewall lines. It is inside <IfModule>, so a
// server without ModSecurity loads the file as before.
func renderWAF(b *strings.Builder, s Site) {
	w := s.WAF
	if w == nil {
		w = &WAF{Mode: WAFDetect}
	}
	b.WriteString("    <IfModule security2_module>\n")
	switch w.Mode {
	case WAFOff:
		b.WriteString("        SecRuleEngine Off\n")
	case WAFBlock:
		b.WriteString("        SecRuleEngine On\n")
	default:
		b.WriteString("        SecRuleEngine DetectionOnly\n")
	}
	if w.Mode != WAFOff {
		// Uploads are the site's business: a body past the limit is let
		// through unchecked rather than refused, which is what the package's
		// own 13 MB refusal would do to every large upload.
		b.WriteString("        SecRequestBodyLimit 536870912\n")
		b.WriteString("        SecRequestBodyLimitAction ProcessPartial\n")
		ids := append([]int(nil), w.Exclude...)
		sort.Ints(ids)
		for i := 0; i < len(ids); i += 10 {
			end := i + 10
			if end > len(ids) {
				end = len(ids)
			}
			parts := make([]string, 0, 10)
			for _, id := range ids[i:end] {
				parts = append(parts, strconv.Itoa(id))
			}
			fmt.Fprintf(b, "        SecRuleRemoveById %s\n", strings.Join(parts, " "))
		}
	}
	b.WriteString("    </IfModule>\n")
}

// ---------------------------------------------------------------------------
// waf.info, waf.settings, waf.events

// WAFInfo is what waf.info answers.
type WAFInfo struct {
	// Available is true when Apache has ModSecurity loaded (the stack uses
	// Apache and the module is installed).
	Available bool `json:"available"`
	// Server is the web stack; "nginx" alone cannot have the firewall.
	Server string `json:"server"`
	// Rules is the Core Rule Set's version, when found.
	Rules    string `json:"rules"`
	Paranoia int    `json:"paranoia"`
}

// WAFSettings are waf.settings' arguments.
type WAFSettings struct {
	Paranoia int `json:"paranoia"`
}

// WAFEventsArgs are waf.events' arguments: where each log was read up to.
type WAFEventsArgs struct {
	Cursors map[string]WAFCursor `json:"cursors"`
}

// WAFCursor is a position in one account's error log, and the hash of the
// log's first line (a replaced or rotated log has another).
type WAFCursor struct {
	Offset int64  `json:"offset"`
	Head   string `json:"head"`
}

// WAFRule is one rule that matched a request.
type WAFRule struct {
	ID  int    `json:"id"`
	Msg string `json:"msg"`
}

// WAFEvent is one request the rules looked at and disliked.
type WAFEvent struct {
	Time    string    `json:"time"`
	Account string    `json:"account"`
	Host    string    `json:"host"`
	IP      string    `json:"ip"`
	URI     string    `json:"uri"`
	Blocked bool      `json:"blocked"`
	Score   int       `json:"score"`
	Rules   []WAFRule `json:"rules"`
}

// WAFEventsResult is waf.events' answer.
type WAFEventsResult struct {
	Events  []WAFEvent           `json:"events"`
	Cursors map[string]WAFCursor `json:"cursors"`
}

const (
	maxWAFRead   = 8 << 20
	maxWAFEvents = 500
	maxWAFRules  = 12
)

// wafFile is the global part: the paranoia level.
func (m *Manager) wafFile() string { return filepath.Join(filepath.Dir(m.Sites), "waf.conf") }

// wafRules is where the Core Rule Set's package puts its rules (tests change it).
func (m *Manager) wafRules() string {
	if m.WAFRules != "" {
		return m.WAFRules
	}
	return "/usr/share/mod_modsecurity_crs/rules"
}

var (
	paranoiaLine = regexp.MustCompile(`tx\.blocking_paranoia_level=([1-4])`)
	crsVersion   = regexp.MustCompile(`ver:'?OWASP_CRS/([0-9]+\.[0-9]+\.[0-9]+)`)
)

// WAFInfo reports the firewall's state.
func (m *Manager) WAFInfo(ctx context.Context) (WAFInfo, error) {
	info := WAFInfo{Server: m.Server, Paranoia: 1}
	if data, err := os.ReadFile(m.wafFile()); err == nil {
		if g := paranoiaLine.FindSubmatch(data); g != nil {
			info.Paranoia, _ = strconv.Atoi(string(g[1]))
		}
	}
	if m.on() != nil || !m.usesApache() {
		return info, nil
	}
	code, out, err := m.Run.Run(ctx, "httpd", "-M")
	info.Available = err == nil && code == 0 && bytes.Contains([]byte(out), []byte("security2_module"))
	if data, err := os.ReadFile(filepath.Join(m.wafRules(), "REQUEST-901-INITIALIZATION.conf")); err == nil {
		if g := crsVersion.FindSubmatch(data); g != nil {
			info.Rules = string(g[1])
		}
	}
	return info, nil
}

// SetWAF writes the paranoia level and makes Apache read it.
func (m *Manager) SetWAF(ctx context.Context, a WAFSettings) (WAFInfo, error) {
	if err := m.on(); err != nil {
		return WAFInfo{}, err
	}
	if a.Paranoia < 1 || a.Paranoia > 4 {
		return WAFInfo{}, badArgs("the paranoia level is 1 to 4, not %d", a.Paranoia)
	}
	if !m.usesApache() {
		return WAFInfo{}, protocol.Fail("waf_unavailable", "the web application firewall needs Apache (the apache or hybrid stack)")
	}
	old, _ := os.ReadFile(m.wafFile())
	data := []byte(fmt.Sprintf("# %s\n# The Core Rule Set's strictness; read before the rules are loaded.\n"+
		"<IfModule security2_module>\n"+
		"    SecAction \"id:9009001,phase:1,nolog,pass,t:none,setvar:tx.blocking_paranoia_level=%d,setvar:tx.detection_paranoia_level=%d\"\n"+
		"</IfModule>\n", banner, a.Paranoia, a.Paranoia))
	if !bytes.Equal(old, data) {
		if err := fsutil.WriteFileAtomic(m.wafFile(), data, 0o644); err != nil {
			return WAFInfo{}, err
		}
		if err := sysexec.Must(ctx, m.Run, "httpd", "-t"); err != nil {
			if old == nil {
				_ = os.Remove(m.wafFile())
			} else {
				_ = fsutil.WriteFileAtomic(m.wafFile(), old, 0o644)
			}
			return WAFInfo{}, protocol.Fail("waf_config", "Apache refused the setting: "+err.Error())
		}
		if err := sysexec.Must(ctx, m.Run, "systemctl", "reload-or-restart", "httpd"); err != nil {
			return WAFInfo{}, err
		}
	}
	return m.WAFInfo(ctx)
}

var (
	wafLine    = regexp.MustCompile(`^\[([A-Z][a-z]{2} [A-Z][a-z]{2} +\d+ \d\d:\d\d:\d\d)(?:\.\d+)? (\d{4})\] .*\[client ([0-9a-fA-F:.]+)(?::\d+)?\] ModSecurity: (Access denied|Warning|Intercepted)`)
	wafID      = regexp.MustCompile(`\[id "(\d+)"\]`)
	wafMsg     = regexp.MustCompile(`\[msg "((?:[^"\\]|\\.)*)"\]`)
	wafHost    = regexp.MustCompile(`\[hostname "([^"]*)"\]`)
	wafURI     = regexp.MustCompile(`\[uri "((?:[^"\\]|\\.)*)"\]`)
	wafUnique  = regexp.MustCompile(`\[unique_id "([^"]*)"\]`)
	wafScore   = regexp.MustCompile(`\(Total (?:Inbound )?Score: (\d+)`)
	wafSummary = map[int]bool{949110: true, 980130: true, 949111: true}
)

// WAFEvents reads the accounts' error logs for what the rules said since
// each cursor. A log never read before starts at its end.
func (m *Manager) WAFEvents(ctx context.Context, a WAFEventsArgs) (WAFEventsResult, error) {
	out := WAFEventsResult{Events: []WAFEvent{}, Cursors: map[string]WAFCursor{}}
	files, _ := filepath.Glob(filepath.Join(m.LogDir, "*.error.log"))
	sort.Strings(files)
	for _, path := range files {
		if err := ctx.Err(); err != nil {
			return out, err
		}
		account := strings.TrimSuffix(filepath.Base(path), ".error.log")
		cur, had := a.Cursors[account]
		next, events, err := readWAFLog(path, account, cur, had)
		if err != nil {
			continue
		}
		out.Cursors[account] = next
		out.Events = append(out.Events, events...)
	}
	if len(out.Events) > maxWAFEvents {
		out.Events = out.Events[len(out.Events)-maxWAFEvents:]
	}
	return out, nil
}

func readWAFLog(path, account string, cur WAFCursor, had bool) (WAFCursor, []WAFEvent, error) {
	f, err := os.Open(path)
	if err != nil {
		return WAFCursor{}, nil, err
	}
	defer f.Close()
	st, err := f.Stat()
	if err != nil {
		return WAFCursor{}, nil, err
	}
	head := make([]byte, 256)
	n, _ := f.ReadAt(head, 0)
	head = head[:n]
	if i := bytes.IndexByte(head, '\n'); i >= 0 {
		head = head[:i]
	}
	h := fmt.Sprintf("%x", sumHead(head))
	size := st.Size()
	end := WAFCursor{Offset: size, Head: h}
	if !had {
		return end, nil, nil
	}
	start := cur.Offset
	if cur.Head != h || start > size || start < 0 {
		start = 0
	}
	if size-start > maxWAFRead {
		start = size - maxWAFRead
	}
	if start == size {
		return end, nil, nil
	}
	buf, err := io.ReadAll(io.NewSectionReader(f, start, size-start))
	if err != nil {
		return WAFCursor{}, nil, err
	}
	last := bytes.LastIndexByte(buf, '\n')
	if last < 0 {
		return WAFCursor{Offset: start, Head: h}, nil, nil
	}
	buf = buf[:last+1]
	end.Offset = start + int64(len(buf))
	return end, wafEvents(string(buf), account), nil
}

// wafEvents turns log text into one event per request (its unique id): the
// rules that matched, whether the request was refused and the score.
func wafEvents(text, account string) []WAFEvent {
	var order []string
	byID := map[string]*WAFEvent{}
	for _, line := range strings.Split(text, "\n") {
		g := wafLine.FindStringSubmatch(line)
		if g == nil {
			continue
		}
		uid := firstGroup(wafUnique, line)
		if uid == "" {
			continue
		}
		ev := byID[uid]
		if ev == nil {
			t, err := time.ParseInLocation("Mon Jan _2 15:04:05 2006", g[1]+" "+g[2], time.Local)
			ts := ""
			if err == nil {
				ts = t.UTC().Format(time.RFC3339)
			}
			ev = &WAFEvent{Time: ts, Account: account, IP: g[3], Rules: []WAFRule{}}
			byID[uid] = ev
			order = append(order, uid)
		}
		if h := firstGroup(wafHost, line); h != "" {
			ev.Host = h
		}
		if u := firstGroup(wafURI, line); u != "" && ev.URI == "" {
			ev.URI = u
		}
		id, _ := strconv.Atoi(firstGroup(wafID, line))
		msg := strings.ReplaceAll(firstGroup(wafMsg, line), `\"`, `"`)
		if g[4] != "Warning" && wafSummary[id] {
			ev.Blocked = true
		}
		if wafSummary[id] {
			if s := wafScore.FindStringSubmatch(msg); s != nil {
				ev.Score, _ = strconv.Atoi(s[1])
			}
			continue
		}
		if id > 0 && len(ev.Rules) < maxWAFRules {
			ev.Rules = append(ev.Rules, WAFRule{ID: id, Msg: truncate(msg, 160)})
		}
	}
	out := make([]WAFEvent, 0, len(order))
	for _, uid := range order {
		ev := byID[uid]
		ev.URI = truncate(ev.URI, 300)
		out = append(out, *ev)
	}
	return out
}

func firstGroup(re *regexp.Regexp, s string) string {
	if g := re.FindStringSubmatch(s); g != nil {
		return g[1]
	}
	return ""
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n]
}

func sumHead(b []byte) []byte {
	s := sha256.Sum256(b)
	return s[:8]
}
