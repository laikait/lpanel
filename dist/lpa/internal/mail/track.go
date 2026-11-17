package mail

import (
	"bufio"
	"compress/gzip"
	"context"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"

	"lpa/internal/protocol"
)

// Delivery tracking: what became of each message, read from Exim's main log
// and its rotations (plain or gzip). An account sees only mail from or to
// its own domains, and of mail to it only its own recipients.

// Tracking limits.
const (
	MaxTrackDays    = 30
	MaxTrackResults = 1000
	maxTrackBytes   = 2 << 30 // read at most this much log
)

// TrackArgs asks for the deliveries of the last Days days, newest first.
// Username limits them to one account's; Search is part of an address;
// Status is one of delivered, deferred, failed, rejected, pending, or empty.
type TrackArgs struct {
	Username string `json:"username"`
	Search   string `json:"search"`
	Status   string `json:"status"`
	Days     int    `json:"days"`
	Limit    int    `json:"limit"`
}

// Delivery is what became of one message for one recipient.
type Delivery struct {
	Time   string `json:"time"` // when the message arrived, or was refused (UTC, from the log)
	ID     string `json:"id"`
	From   string `json:"from"`
	To     string `json:"to"`
	Status string `json:"status"`
	// Detail is the host it went to, or why it was deferred, failed or
	// refused.
	Detail string `json:"detail"`
	Size   int64  `json:"size"`
	// Login is the mailbox that sent it, or the account whose site did.
	Login string `json:"login"`
	Done  string `json:"done"` // when its last attempt was
}

// TrackResult lists deliveries.
type TrackResult struct {
	Deliveries []Delivery `json:"deliveries"`
	More       bool       `json:"more"`
}

var trackStatuses = map[string]bool{"": true, "delivered": true, "deferred": true, "failed": true, "rejected": true, "pending": true}

// Track reads the log for deliveries.
func (m *Manager) Track(_ context.Context, a TrackArgs) (TrackResult, error) {
	if !m.Enabled {
		return TrackResult{}, protocol.Fail(CodeOff, "the agent is configured without mail")
	}
	if a.Username != "" {
		if err := checkAccount(a.Username); err != nil {
			return TrackResult{}, err
		}
	}
	if !trackStatuses[a.Status] {
		return TrackResult{}, badArgs("%q is not a status", a.Status)
	}
	if len(a.Search) > maxQueueQuery {
		return TrackResult{}, badArgs("the search is too long")
	}
	if a.Days <= 0 {
		a.Days = 7
	}
	if a.Days > MaxTrackDays {
		return TrackResult{}, badArgs("tracking looks back at most %d days", MaxTrackDays)
	}
	if a.Limit <= 0 || a.Limit > MaxTrackResults {
		a.Limit = MaxTrackResults
	}
	var own map[string]bool
	if a.Username != "" {
		m.mu.Lock()
		domains, err := m.domainsOf(a.Username)
		m.mu.Unlock()
		if err != nil {
			return TrackResult{}, err
		}
		own = map[string]bool{}
		for _, d := range domains {
			own[d] = true
		}
	}
	since := time.Now().UTC().AddDate(0, 0, -a.Days).Format("2006-01-02 15:04:05")
	t := newTracker()
	for _, path := range m.logFiles(a.Days) {
		if err := t.readFile(path, since); err != nil {
			m.Log.Warn("mail log not read", "path", path, "err", err)
		}
	}
	return t.result(a, own), nil
}

// logFiles lists main.log and its rotations from the last days+1 days,
// oldest first.
func (m *Manager) logFiles(days int) []string {
	entries, err := os.ReadDir(m.LogDir)
	if err != nil {
		return nil
	}
	cutoff := time.Now().AddDate(0, 0, -days-1)
	type file struct {
		path string
		mod  time.Time
	}
	var files []file
	for _, e := range entries {
		name := e.Name()
		if name != "main.log" && !strings.HasPrefix(name, "main.log-") && !strings.HasPrefix(name, "main.log.") {
			continue
		}
		info, err := e.Info()
		if err != nil || !info.Mode().IsRegular() || info.ModTime().Before(cutoff) {
			continue
		}
		files = append(files, file{filepath.Join(m.LogDir, name), info.ModTime()})
	}
	sort.Slice(files, func(i, j int) bool { return files[i].mod.Before(files[j].mod) })
	out := make([]string, len(files))
	for i, f := range files {
		out[i] = f.path
	}
	return out
}

type trackedMessage struct {
	time, from, login string
	size              int64
	order             int
	rcpts             []string
	status            map[string]*Delivery
}

type tracker struct {
	messages map[string]*trackedMessage
	rejected []Delivery
	read     int64
	n        int
}

func newTracker() *tracker { return &tracker{messages: map[string]*trackedMessage{}} }

func (t *tracker) readFile(path, since string) error {
	f, err := os.Open(path)
	if err != nil {
		return err
	}
	defer f.Close()
	var r io.Reader = f
	if strings.HasSuffix(path, ".gz") {
		gz, err := gzip.NewReader(f)
		if err != nil {
			return err
		}
		defer gz.Close()
		r = gz
	}
	sc := bufio.NewScanner(r)
	sc.Buffer(make([]byte, 64<<10), 1<<20)
	for sc.Scan() {
		line := sc.Text()
		t.read += int64(len(line))
		if t.read > maxTrackBytes {
			return nil
		}
		if len(line) < 20 || line[:19] < since {
			continue
		}
		t.line(line)
	}
	return sc.Err()
}

var (
	logField  = regexp.MustCompile(`\b([A-Z]{1,2})=(\S+)`)
	logForRcp = regexp.MustCompile(` for (.+)$`)
	logReject = regexp.MustCompile(`F=<([^>]*)> (?:\S+ )?rejected RCPT <([^>]*)>: (.*)$`)
	logRejDat = regexp.MustCompile(`F=<([^>]*)> rejected after DATA: (.*)$`)
)

// line reads one log line:
//
//	2026-11-16 10:00:00 1tXyZa-00000000AB-ABCD <= a@x H=... A=dovecot_plain:a@x S=1234 for b@y
//	2026-11-16 10:00:01 1tXyZa-00000000AB-ABCD => b@y R=dnslookup T=remote_smtp H=mx.y [1.2.3.4]:25 ...
//	2026-11-16 10:00:01 1tXyZa-00000000AB-ABCD == b@y R=... defer (-44) H=...: SMTP error ... 451 ...
//	2026-11-16 10:00:01 1tXyZa-00000000AB-ABCD ** b@y R=... T=...: SMTP error ... 550 ...
//	2026-11-16 10:00:01 H=(helo) [1.2.3.4]:5000 I=... F=<a@x> rejected RCPT <b@y>: relay not permitted
func (t *tracker) line(line string) {
	stamp := line[:19]
	rest := line[20:]
	id, after, _ := strings.Cut(rest, " ")
	if !messageID.MatchString(id) {
		if m := logReject.FindStringSubmatch(rest); m != nil {
			t.rejected = append(t.rejected, Delivery{Time: stamp, From: m[1], To: m[2], Status: "rejected", Detail: m[3]})
		}
		return
	}
	if m := logRejDat.FindStringSubmatch(after); m != nil {
		// LPanel's own refusals end with their recipients (" for a@x, b@y").
		why, list, found := strings.Cut(m[2], " for ")
		if !found {
			t.rejected = append(t.rejected, Delivery{Time: stamp, ID: id, From: m[1], Status: "rejected", Detail: m[2]})
			return
		}
		for _, rcpt := range strings.Split(list, ",") {
			if rcpt = strings.TrimSpace(rcpt); rcpt != "" {
				t.rejected = append(t.rejected, Delivery{Time: stamp, ID: id, From: m[1], To: rcpt, Status: "rejected", Detail: why})
			}
		}
		return
	}
	flag, body, _ := strings.Cut(after, " ")
	if flag == "<=" {
		from, fields, _ := strings.Cut(body, " ")
		if from == "<>" {
			from = ""
		}
		msg := &trackedMessage{time: stamp, from: from, order: t.n, status: map[string]*Delivery{}}
		t.n++
		for _, f := range logField.FindAllStringSubmatch(fields, -1) {
			switch f[1] {
			case "S":
				msg.size, _ = strconv.ParseInt(f[2], 10, 64)
			case "A":
				if _, who, ok := strings.Cut(f[2], ":"); ok {
					msg.login = strings.ToLower(who)
				}
			case "U":
				if msg.login == "" {
					msg.login = f[2]
				}
			}
		}
		if m := logForRcp.FindStringSubmatch(fields); m != nil {
			msg.rcpts = strings.Fields(m[1])
		}
		t.messages[id] = msg
		return
	}
	msg := t.messages[id]
	if msg == nil {
		return // arrived before the days asked for
	}
	status := map[string]string{"=>": "delivered", "->": "delivered", "*>": "delivered", "==": "deferred", "**": "failed"}[flag]
	if status == "" {
		return
	}
	rcpt, fields, _ := strings.Cut(body, " ")
	// "=> info <info@example.com>": a local delivery names the local part
	// first; "=> b@y <a@example.com>" went to b@y for a forwarder of a@.
	// Either way it is filed under the address the message was sent to.
	forwardedTo := ""
	if strings.HasPrefix(fields, "<") {
		if end := strings.IndexByte(fields, '>'); end > 0 {
			if strings.Contains(rcpt, "@") {
				forwardedTo = rcpt
			}
			rcpt, fields = fields[1:end], strings.TrimSpace(fields[end+1:])
		}
	}
	d := msg.status[rcpt]
	if d == nil {
		d = &Delivery{To: rcpt}
		msg.status[rcpt] = d
		if !contains(msg.rcpts, rcpt) {
			msg.rcpts = append(msg.rcpts, rcpt)
		}
	}
	d.Status, d.Done = status, stamp
	switch status {
	case "delivered":
		d.Detail = ""
		for _, f := range logField.FindAllStringSubmatch(fields, -1) {
			if f[1] == "H" {
				d.Detail = f[2]
			}
		}
		if d.Detail == "" {
			if strings.Contains(fields, "T=dovecot_lmtp") {
				d.Detail = "mailbox"
			} else if strings.Contains(fields, "T=") {
				d.Detail = "local"
			}
		}
		if forwardedTo != "" {
			d.Detail = strings.TrimSpace("forwarded to " + forwardedTo + " " + d.Detail)
		}
	default:
		// The reason follows the routing fields, after ": ".
		if _, why, ok := strings.Cut(fields, ": "); ok {
			d.Detail = why
		} else {
			d.Detail = fields
		}
	}
}

func contains(list []string, s string) bool {
	for _, v := range list {
		if v == s {
			return true
		}
	}
	return false
}

func domainOf(addr string) string {
	if at := strings.LastIndexByte(addr, '@'); at >= 0 {
		return strings.ToLower(addr[at+1:])
	}
	return ""
}

// result picks and orders the deliveries asked for, newest first.
func (t *tracker) result(a TrackArgs, own map[string]bool) TrackResult {
	search := strings.ToLower(strings.TrimSpace(a.Search))
	var out []Delivery
	add := func(d Delivery, senderSide bool) {
		if own != nil && !senderSide && !own[domainOf(d.To)] {
			return
		}
		if a.Status != "" && d.Status != a.Status {
			return
		}
		if search != "" && !strings.Contains(strings.ToLower(d.From), search) && !strings.Contains(strings.ToLower(d.To), search) && !strings.Contains(strings.ToLower(d.ID), search) {
			return
		}
		out = append(out, d)
	}
	ids := make([]string, 0, len(t.messages))
	for id := range t.messages {
		ids = append(ids, id)
	}
	sort.Slice(ids, func(i, j int) bool { return t.messages[ids[i]].order > t.messages[ids[j]].order })
	for _, id := range ids {
		msg := t.messages[id]
		// The account sent it: from its domain, by its mailbox, or by its site.
		senderSide := own == nil || own[domainOf(msg.from)] || own[domainOf(msg.login)] || (a.Username != "" && msg.login == a.Username)
		for _, rcpt := range msg.rcpts {
			d := Delivery{Time: msg.time, ID: id, From: msg.from, To: rcpt, Status: "pending", Size: msg.size, Login: msg.login}
			if s := msg.status[rcpt]; s != nil {
				d.Status, d.Detail, d.Done = s.Status, s.Detail, s.Done
			}
			add(d, senderSide)
		}
	}
	for i := len(t.rejected) - 1; i >= 0; i-- {
		r := t.rejected[i]
		add(r, own == nil || own[domainOf(r.From)])
	}
	sort.SliceStable(out, func(i, j int) bool { return out[i].Time > out[j].Time })
	res := TrackResult{Deliveries: []Delivery{}}
	if len(out) > a.Limit {
		out, res.More = out[:a.Limit], true
	}
	if out != nil {
		res.Deliveries = out
	}
	return res
}
