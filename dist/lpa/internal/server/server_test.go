package server

import (
	"bufio"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"io"
	"log/slog"
	"net"
	"path/filepath"
	"sync/atomic"
	"testing"
	"time"

	"lpa/internal/journal"
	"lpa/internal/ops"
	"lpa/internal/protocol"
	"lpa/internal/sysinfo"
)

const panelUID = 990

type harness struct {
	t      *testing.T
	key    ed25519.PrivateKey
	server *Server
	sock   string
	uid    int
	count  atomic.Int32
	gate   chan struct{}
}

func newHarness(t *testing.T) *harness {
	t.Helper()
	pub, key, _ := ed25519.GenerateKey(nil)
	j, err := journal.Open(filepath.Join(t.TempDir(), "journal"))
	if err != nil {
		t.Fatal(err)
	}
	h := &harness{t: t, key: key, uid: panelUID, gate: make(chan struct{})}

	reg := ops.NewRegistry()
	ops.System(reg, &sysinfo.Reader{Root: filepath.Join("..", "sysinfo", "testdata", "root"), Statfs: func(string) (sysinfo.FSStats, error) {
		return sysinfo.FSStats{TotalBytes: 100, FreeBytes: 40, AvailBytes: 30, Device: 1}, nil
	}}, "0.0.0-test", time.Now())
	reg.Add(ops.Op{Name: "test.count", Run: func(context.Context, json.RawMessage) (any, error) {
		return map[string]int32{"count": h.count.Add(1)}, nil
	}})
	reg.Add(ops.Op{Name: "test.slow", Async: true, Run: func(context.Context, json.RawMessage) (any, error) {
		<-h.gate
		return map[string]bool{"finished": true}, nil
	}})
	reg.Add(ops.Op{Name: "test.timeout", Timeout: 20 * time.Millisecond, Run: func(ctx context.Context, _ json.RawMessage) (any, error) {
		<-ctx.Done()
		return nil, ctx.Err()
	}})
	reg.Add(ops.Op{Name: "test.panic", Run: func(context.Context, json.RawMessage) (any, error) { panic("boom") }})

	h.server = &Server{
		Registry: reg, Journal: j, PanelKey: pub, AllowedUIDs: []int{panelUID},
		PeerUID:    func(net.Conn) (int, error) { return h.uid, nil },
		Now:        time.Now,
		Log:        slog.New(slog.NewTextHandler(io.Discard, nil)),
		MaxRunning: 2,
	}

	// Unix socket paths are short-limited; a temp dir name can be long.
	h.sock = filepath.Join(t.TempDir(), "a.sock")
	ln, err := net.Listen("unix", h.sock)
	if err != nil {
		t.Skipf("unix sockets unavailable here: %v", err)
	}
	go func() { _ = h.server.Serve(ln) }()
	t.Cleanup(func() {
		select {
		case <-h.gate:
		default:
			close(h.gate)
		}
		ln.Close()
		h.server.Wait()
	})
	return h
}

func nonce() string {
	b := make([]byte, 16)
	_, _ = rand.Read(b)
	return base64.RawURLEncoding.EncodeToString(b)
}

func (h *harness) line(id, op, args string) []byte {
	n := nonce()
	ts := time.Now().Unix()
	req := map[string]any{"v": 1, "id": id, "op": op, "ts": ts, "nonce": n, "args": json.RawMessage(args), "sig": protocol.Sign(h.key, id, op, ts, n, []byte(args))}
	data, _ := json.Marshal(req)
	return append(data, '\n')
}

// send writes one line and reads the answer; ok is false when the agent
// closed the connection without answering.
func (h *harness) send(line []byte) (resp protocol.Response, raw map[string]any, ok bool) {
	h.t.Helper()
	conn, err := net.Dial("unix", h.sock)
	if err != nil {
		h.t.Fatal(err)
	}
	defer conn.Close()
	_, _ = conn.Write(line)
	answer, err := bufio.NewReader(conn).ReadBytes('\n')
	if err != nil {
		return resp, nil, false
	}
	_ = json.Unmarshal(answer, &resp)
	_ = json.Unmarshal(answer, &raw)
	return resp, raw, true
}

func (h *harness) call(id, op, args string) (protocol.Response, map[string]any) {
	h.t.Helper()
	resp, raw, ok := h.send(h.line(id, op, args))
	if !ok {
		h.t.Fatalf("%s: no answer", op)
	}
	return resp, raw
}

func code(r protocol.Response) string {
	if r.Error == nil {
		return ""
	}
	return r.Error.Code
}

func TestFactsAndPing(t *testing.T) {
	h := newHarness(t)

	ping, raw := h.call("ping-0000000000001", "system.ping", `{}`)
	facts, factsRaw := h.call("facts-000000000001", "system.facts", `{}`)

	if ping.Status != protocol.StatusDone || raw["result"].(map[string]any)["agent"] != "0.0.0-test" {
		t.Fatalf("ping: %+v", raw)
	}
	result := factsRaw["result"].(map[string]any)
	if facts.Status != protocol.StatusDone || result["hostname"] != "web-01" || result["cpus"].(float64) != 2 {
		t.Fatalf("facts: %+v", factsRaw)
	}
}

func TestOnlyThePanelsUIDGetsAnAnswer(t *testing.T) {
	h := newHarness(t)
	h.uid = 1000

	if _, _, ok := h.send(h.line("ping-0000000000001", "system.ping", `{}`)); ok {
		t.Fatal("a process that is not the panel got an answer")
	}
}

func TestRefusals(t *testing.T) {
	h := newHarness(t)
	_, other, _ := ed25519.GenerateKey(nil)

	line := h.line("ping-0000000000001", "system.ping", `{}`)
	first, _, _ := h.send(line)
	replayed, _, _ := h.send(line)

	forged := map[string]any{"v": 1, "id": "forged-00000000001", "op": "system.ping", "ts": time.Now().Unix(), "nonce": nonce(), "args": json.RawMessage(`{}`)}
	forged["sig"] = protocol.Sign(other, "forged-00000000001", "system.ping", forged["ts"].(int64), forged["nonce"].(string), []byte(`{}`))
	forgedLine, _ := json.Marshal(forged)
	forgedResp, _, _ := h.send(append(forgedLine, '\n'))

	unknown, _ := h.call("unknown-0000000001", "system.shell", `{}`)
	badArgs, _ := h.call("badargs-0000000001", "system.facts", `{"path":"/etc/shadow"}`)
	garbage, _, _ := h.send([]byte("rm -rf /\n"))

	for name, c := range map[string]struct {
		resp protocol.Response
		code string
	}{
		"first":    {first, ""},
		"replayed": {replayed, protocol.CodeReplay},
		"forged":   {forgedResp, protocol.CodeBadSignature},
		"unknown":  {unknown, protocol.CodeUnknownOp},
		"bad args": {badArgs, protocol.CodeBadArgs},
		"garbage":  {garbage, protocol.CodeBadRequest},
	} {
		if code(c.resp) != c.code {
			t.Errorf("%s: got %q (%+v), want %q", name, code(c.resp), c.resp, c.code)
		}
	}
}

func TestChangesRunExactlyOncePerID(t *testing.T) {
	h := newHarness(t)

	first, raw1 := h.call("change-00000000001", "test.count", `{}`)
	retry, raw2 := h.call("change-00000000001", "test.count", `{}`)
	different, _ := h.call("change-00000000001", "test.count", `{"x":1}`)

	if first.Status != protocol.StatusDone || retry.Status != protocol.StatusDone {
		t.Fatalf("first %+v, retry %+v", first, retry)
	}
	if h.count.Load() != 1 {
		t.Fatalf("the work ran %d times", h.count.Load())
	}
	if raw1["result"].(map[string]any)["count"] != raw2["result"].(map[string]any)["count"] {
		t.Fatal("the retry did not get the first result")
	}
	if code(different) != protocol.CodeIDReused {
		t.Fatalf("an id reused for other work: %+v", different)
	}
}

func TestBackgroundOperationsReportThroughOpStatus(t *testing.T) {
	h := newHarness(t)

	accepted, _ := h.call("slow-0000000000001", "test.slow", `{}`)
	running, _ := h.call("status-00000000001", "op.status", `{"id":"slow-0000000000001"}`)
	close(h.gate)
	h.server.Wait()
	done, raw := h.call("status-00000000002", "op.status", `{"id":"slow-0000000000001"}`)
	missing, _ := h.call("status-00000000003", "op.status", `{"id":"never-sent-0000001"}`)
	traversal, _ := h.call("status-00000000004", "op.status", `{"id":"../../../etc/passwd"}`)

	if accepted.Status != protocol.StatusAccepted || running.Status != protocol.StatusRunning {
		t.Fatalf("accepted %+v, running %+v", accepted, running)
	}
	if done.Status != protocol.StatusDone || raw["result"].(map[string]any)["finished"] != true {
		t.Fatalf("done: %+v", raw)
	}
	if code(missing) != protocol.CodeNotFound || code(traversal) != protocol.CodeBadArgs {
		t.Fatalf("missing %+v, traversal %+v", missing, traversal)
	}
}

func TestTimeoutsPanicsAndBusy(t *testing.T) {
	h := newHarness(t)

	timeout, _ := h.call("timeout-0000000001", "test.timeout", `{}`)
	panicked, _ := h.call("panic-000000000001", "test.panic", `{}`)

	// Two slow operations fill the two slots; a third request is turned away.
	h.call("slow-0000000000001", "test.slow", `{}`)
	h.call("slow-0000000000002", "test.slow", `{}`)
	busy, _ := h.call("ping-0000000000001", "system.ping", `{}`)

	if code(timeout) != protocol.CodeTimeout || code(panicked) != protocol.CodeFailed || code(busy) != protocol.CodeBusy {
		t.Fatalf("timeout %+v, panic %+v, busy %+v", timeout, panicked, busy)
	}
}
