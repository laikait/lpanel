package mail

import (
	"context"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"unicode/utf8"

	"lpa/internal/protocol"
	"lpa/internal/sysexec"
)

// Exim's queue: what waits to be delivered, and what the administrator does
// with it. Message ids are checked before they reach Exim's arguments.

// CodeNoMessage is a message id the queue does not have.
const CodeNoMessage = "mail_no_message"

// Queue limits.
const (
	MaxQueueList  = 1000      // messages one listing returns
	MaxQueueIDs   = 500       // messages one action names
	MaxViewBytes  = 256 << 10 // of a message shown
	maxQueueQuery = 254
)

// Exim's message ids: 6-11-4 since 4.97 (base 62), 6-6-2 before.
var messageID = regexp.MustCompile(`^[0-9A-Za-z]{6}-([0-9A-Za-z]{11}-[0-9A-Za-z]{4}|[0-9A-Za-z]{6}-[0-9A-Za-z]{2})$`)

// QueueArgs filters the listing: Search is part of a sender or recipient.
type QueueArgs struct {
	Search string `json:"search"`
	Limit  int    `json:"limit"`
}

// Queued is one message in the queue.
type Queued struct {
	ID     string `json:"id"`
	Age    int64  `json:"age"`  // seconds
	Size   int64  `json:"size"` // bytes, roughly (Exim rounds)
	Sender string `json:"sender"`
	Frozen bool   `json:"frozen"`
	// Recipients are those still waiting; Done those already delivered.
	Recipients []string `json:"recipients"`
	Done       int      `json:"done"`
}

// QueueList is the queue, oldest first.
type QueueList struct {
	Total    int      `json:"total"`
	Frozen   int      `json:"frozen"`
	Messages []Queued `json:"messages"`
}

// Queue lists the messages waiting in Exim's queue.
func (m *Manager) Queue(ctx context.Context, a QueueArgs) (QueueList, error) {
	if !m.Enabled {
		return QueueList{}, protocol.Fail(CodeOff, "the agent is configured without mail")
	}
	if len(a.Search) > maxQueueQuery {
		return QueueList{}, badArgs("the search is too long")
	}
	if a.Limit <= 0 || a.Limit > MaxQueueList {
		a.Limit = MaxQueueList
	}
	code, out, err := m.Run.Run(ctx, "exim", "-bp")
	if err != nil {
		return QueueList{}, err
	}
	if code != 0 {
		return QueueList{}, protocol.Fail("mail_queue", "exim -bp exited with "+strconv.Itoa(code)+": "+out)
	}
	all := parseQueue(out)
	list := QueueList{Messages: []Queued{}}
	search := strings.ToLower(strings.TrimSpace(a.Search))
	for _, q := range all {
		if q.Frozen {
			list.Frozen++
		}
		list.Total++
		if search != "" && !q.matches(search) {
			continue
		}
		if len(list.Messages) < a.Limit {
			list.Messages = append(list.Messages, q)
		}
	}
	return list, nil
}

func (q Queued) matches(search string) bool {
	if strings.Contains(strings.ToLower(q.Sender), search) || strings.Contains(strings.ToLower(q.ID), search) {
		return true
	}
	for _, r := range q.Recipients {
		if strings.Contains(strings.ToLower(r), search) {
			return true
		}
	}
	return false
}

// queueHead is the first line of a message in exim -bp:
//
//	25m  2.1K 1tXyZb-00000000AC-ABCD <sender@example.com> *** frozen ***
var queueHead = regexp.MustCompile(`^\s*(\d+)([smhdw])\s+([0-9.]+)([KMG]?)\s+(\S+)\s+<([^>]*)>(.*)$`)

// parseQueue reads exim -bp, oldest first (as Exim lists it).
func parseQueue(out string) []Queued {
	var all []Queued
	var cur *Queued
	for _, line := range strings.Split(out, "\n") {
		if m := queueHead.FindStringSubmatch(line); m != nil && messageID.MatchString(m[5]) {
			all = append(all, Queued{ID: m[5], Sender: m[6], Frozen: strings.Contains(m[7], "frozen"), Recipients: []string{}})
			cur = &all[len(all)-1]
			n, _ := strconv.ParseInt(m[1], 10, 64)
			cur.Age = n * map[string]int64{"s": 1, "m": 60, "h": 3600, "d": 86400, "w": 604800}[m[2]]
			size, _ := strconv.ParseFloat(m[3], 64)
			cur.Size = int64(size * float64(map[string]int64{"": 1, "K": 1 << 10, "M": 1 << 20, "G": 1 << 30}[m[4]]))
			continue
		}
		if cur == nil {
			continue
		}
		f := strings.Fields(line)
		switch {
		case len(f) == 0:
			cur = nil
		case f[0] == "D" || f[0] == "+D":
			cur.Done++
		case len(f) == 1:
			cur.Recipients = append(cur.Recipients, f[0])
		}
	}
	return all
}

// QueueViewArgs names a message.
type QueueViewArgs struct {
	ID string `json:"id"`
}

// QueueView is one queued message: its text (cut at MaxViewBytes) and
// Exim's log of attempts to deliver it.
type QueueView struct {
	ID        string `json:"id"`
	Message   string `json:"message"`
	Truncated bool   `json:"truncated"`
	Log       string `json:"log"`
}

// QueueMessage shows one queued message.
func (m *Manager) QueueMessage(ctx context.Context, a QueueViewArgs) (QueueView, error) {
	if !m.Enabled {
		return QueueView{}, protocol.Fail(CodeOff, "the agent is configured without mail")
	}
	if !messageID.MatchString(a.ID) {
		return QueueView{}, badArgs("%q is not a message id", a.ID)
	}
	code, text, err := m.Run.Run(ctx, "exim", "-Mvc", a.ID)
	if err != nil {
		return QueueView{}, err
	}
	if code != 0 {
		return QueueView{}, protocol.Fail(CodeNoMessage, a.ID+" is not in the queue")
	}
	v := QueueView{ID: a.ID, Message: text}
	if len(v.Message) > MaxViewBytes {
		cut := MaxViewBytes
		for cut > 0 && !utf8.RuneStart(v.Message[cut]) {
			cut--
		}
		v.Message, v.Truncated = v.Message[:cut], true
	}
	if !utf8.ValidString(v.Message) {
		v.Message = strings.ToValidUTF8(v.Message, "�")
	}
	if code, log, err := m.Run.Run(ctx, "exim", "-Mvl", a.ID); err == nil && code == 0 {
		v.Log = strings.ToValidUTF8(log, "�")
	}
	return v, nil
}

// QueueActArgs is what to do with queued messages.
type QueueActArgs struct {
	// Action: deliver (try now), remove, freeze, thaw, or flush (try every
	// message now; IDs are ignored).
	Action string   `json:"action"`
	IDs    []string `json:"ids"`
}

// QueueAct carries out an action on queued messages. Deliveries run in the
// background (a slow server must not hold the agent): the result says only
// that they started.
func (m *Manager) QueueAct(ctx context.Context, a QueueActArgs) (map[string]any, error) {
	if !m.Enabled {
		return nil, protocol.Fail(CodeOff, "the agent is configured without mail")
	}
	flag := map[string]string{"deliver": "-M", "remove": "-Mrm", "freeze": "-Mf", "thaw": "-Mt"}[a.Action]
	if a.Action == "flush" {
		if err := sysexec.Must(ctx, m.Run, "systemd-run", "--no-block", "--collect", "--quiet", "--unit=lpanel-mail-flush", "/usr/sbin/exim", "-qff"); err != nil {
			return nil, err
		}
		m.Log.Info("mail queue flush started")
		return map[string]any{"action": "flush", "started": true}, nil
	}
	if flag == "" {
		return nil, badArgs("%q is not a queue action (deliver, remove, freeze, thaw, flush)", a.Action)
	}
	if len(a.IDs) == 0 || len(a.IDs) > MaxQueueIDs {
		return nil, badArgs("name 1 to %d messages", MaxQueueIDs)
	}
	seen := map[string]bool{}
	ids := make([]string, 0, len(a.IDs))
	for _, id := range a.IDs {
		if !messageID.MatchString(id) {
			return nil, badArgs("%q is not a message id", id)
		}
		if !seen[id] {
			seen[id] = true
			ids = append(ids, id)
		}
	}
	sort.Strings(ids)
	if a.Action == "deliver" {
		args := append([]string{"--no-block", "--collect", "--quiet", "/usr/sbin/exim", "-M"}, ids...)
		if err := sysexec.Must(ctx, m.Run, "systemd-run", args...); err != nil {
			return nil, err
		}
		m.Log.Info("mail queue delivery started", "messages", len(ids))
		return map[string]any{"action": a.Action, "started": true, "messages": len(ids)}, nil
	}
	// Exim goes on past a message it does not have, and says so; what it
	// says per message is returned.
	_, out, err := m.Run.Run(ctx, "exim", append([]string{flag}, ids...)...)
	if err != nil {
		return nil, err
	}
	m.Log.Info("mail queue action", "action", a.Action, "messages", len(ids))
	return map[string]any{"action": a.Action, "messages": len(ids), "output": out}, nil
}
