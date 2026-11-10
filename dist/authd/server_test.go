//go:build linux

package main

import (
	"bufio"
	"encoding/json"
	"io"
	"log/slog"
	"net"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

// fakeVerifier accepts one password per user and counts the calls.
type fakeVerifier struct {
	mu    sync.Mutex
	calls int
	err   error
}

func (f *fakeVerifier) Verify(user, password, _ string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.calls++
	if f.err != nil {
		return f.err
	}
	if user == "root" && password == "right horse" {
		return nil
	}
	return ErrDenied
}

func (f *fakeVerifier) count() int {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.calls
}

type clock struct {
	mu  sync.Mutex
	now time.Time
}

func (c *clock) Now() time.Time { c.mu.Lock(); defer c.mu.Unlock(); return c.now }
func (c *clock) add(d time.Duration) {
	c.mu.Lock()
	c.now = c.now.Add(d)
	c.mu.Unlock()
}

type fixture struct {
	path     string
	verifier *fakeVerifier
	clock    *clock
}

func start(t *testing.T, cfg Config, clientUID uint32) *fixture {
	t.Helper()
	if err := cfg.prepare(); err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(t.TempDir(), "authd.sock")
	ln, err := listener(path)
	if err != nil {
		t.Fatal(err)
	}
	f := &fixture{path: path, verifier: &fakeVerifier{}, clock: &clock{now: time.Unix(1793000000, 0)}}
	s := &Server{Config: cfg, Verifier: f.verifier, ClientUID: clientUID, PeerUID: peerUID, Now: f.clock.Now,
		Log: slog.New(slog.NewTextHandler(io.Discard, nil))}
	go func() { _ = s.Serve(ln) }()
	t.Cleanup(func() { ln.Close() })
	return f
}

func (f *fixture) ask(t *testing.T, line string) (answer, bool) {
	t.Helper()
	c, err := net.Dial("unix", f.path)
	if err != nil {
		t.Fatal(err)
	}
	defer c.Close()
	_ = c.SetDeadline(time.Now().Add(10 * time.Second))
	if _, err := io.WriteString(c, line+"\n"); err != nil {
		return answer{}, false
	}
	reply, err := bufio.NewReader(c).ReadBytes('\n')
	if err != nil {
		return answer{}, false
	}
	var a answer
	if err := json.Unmarshal(reply, &a); err != nil {
		t.Fatalf("not JSON: %q", reply)
	}
	return a, true
}

func req(user, password, ip string) string {
	b, _ := json.Marshal(request{V: 1, User: user, Password: password, IP: ip})
	return string(b)
}

func me() uint32 { return uint32(os.Getuid()) }

func TestRightAndWrongPassword(t *testing.T) {
	f := start(t, Defaults(), me())
	if a, _ := f.ask(t, req("root", "right horse", "203.0.113.9")); !a.OK {
		t.Fatalf("right password refused: %+v", a)
	}
	if a, _ := f.ask(t, req("root", "wrong", "203.0.113.9")); a.OK || a.Code != codeDenied {
		t.Fatalf("wrong password: %+v", a)
	}
}

func TestOnlyAllowlistedUsersAreChecked(t *testing.T) {
	f := start(t, Defaults(), me())
	if a, _ := f.ask(t, req("www-data", "anything", "203.0.113.9")); a.OK || a.Code != codeDenied {
		t.Fatalf("%+v", a)
	}
	if f.verifier.count() != 0 {
		t.Fatal("PAM was asked about a user outside the allowlist")
	}
}

func TestStrictLockoutPerAddress(t *testing.T) {
	f := start(t, Defaults(), me())
	attacker, other := "198.51.100.7", "203.0.113.9"
	for i := 0; i < 5; i++ {
		if a, _ := f.ask(t, req("root", "guess", attacker)); a.Code != codeDenied {
			t.Fatalf("attempt %d: %+v", i+1, a)
		}
	}
	a, _ := f.ask(t, req("root", "right horse", attacker))
	if a.OK || a.Code != codeLocked || a.RetryAfter < 890 || a.RetryAfter > 900 {
		t.Fatalf("after 5 failures even the right password must wait ~15 min: %+v", a)
	}
	if calls := f.verifier.count(); calls != 5 {
		t.Fatalf("a locked address still reached PAM (%d calls)", calls)
	}
	if a, _ := f.ask(t, req("root", "right horse", other)); !a.OK {
		t.Fatalf("another address was locked out too: %+v", a)
	}

	f.clock.add(15*time.Minute + time.Second)
	if a, _ := f.ask(t, req("root", "right horse", attacker)); !a.OK {
		t.Fatalf("lock did not lift after 15 minutes: %+v", a)
	}
}

func TestFailuresOutsideTheWindowDoNotCount(t *testing.T) {
	f := start(t, Defaults(), me())
	for i := 0; i < 4; i++ {
		f.ask(t, req("root", "guess", "198.51.100.7"))
	}
	f.clock.add(16 * time.Minute)
	f.ask(t, req("root", "guess", "198.51.100.7"))
	if a, _ := f.ask(t, req("root", "right horse", "198.51.100.7")); !a.OK {
		t.Fatalf("old failures still counted: %+v", a)
	}
}

func TestGlobalLimitStopsALyingPanel(t *testing.T) {
	cfg := Defaults()
	cfg.GlobalPerMinute = 3
	f := start(t, cfg, me())
	// A compromised panel can claim a new address for every guess.
	for i, ip := range []string{"192.0.2.1", "192.0.2.2", "192.0.2.3"} {
		if a, _ := f.ask(t, req("root", "guess", ip)); a.Code != codeDenied {
			t.Fatalf("guess %d: %+v", i, a)
		}
	}
	if a, _ := f.ask(t, req("root", "guess", "192.0.2.4")); a.Code != codeLocked {
		t.Fatalf("fourth check in a minute was not refused: %+v", a)
	}
	f.clock.add(time.Minute)
	if a, _ := f.ask(t, req("root", "right horse", "192.0.2.5")); !a.OK {
		t.Fatalf("limit did not reset: %+v", a)
	}
}

func TestRootIPAllowlist(t *testing.T) {
	cfg := Defaults()
	cfg.AllowFrom = map[string][]string{"root": {"203.0.113.0/24", "2001:db8::1"}}
	f := start(t, cfg, me())

	for _, ip := range []string{"203.0.113.77", "2001:db8::1", "::ffff:203.0.113.5"} {
		if a, _ := f.ask(t, req("root", "right horse", ip)); !a.OK {
			t.Errorf("%s should be allowed: %+v", ip, a)
		}
	}
	for _, ip := range []string{"198.51.100.1", "2001:db8::2"} {
		if a, _ := f.ask(t, req("root", "right horse", ip)); a.OK || a.Code != codeIPNotAllowed {
			t.Errorf("%s should be refused: %+v", ip, a)
		}
	}
	if f.verifier.count() != 3 {
		t.Error("PAM was asked for an address outside allow_from")
	}
}

func TestMalformedRequests(t *testing.T) {
	f := start(t, Defaults(), me())
	for _, line := range []string{
		`not json`,
		`{"v":2,"user":"root","password":"x","ip":"192.0.2.1"}`,
		`{"v":1,"user":"root","password":"","ip":"192.0.2.1"}`,
		`{"v":1,"user":"root","password":"x","ip":"localhost"}`,
		`{"v":1,"user":"root","password":"x","ip":"192.0.2.1","as":"admin"}`,
		req("root", strings.Repeat("x", maxPasswordBytes+1), "192.0.2.1"),
		strings.Repeat("a", maxRequestBytes+10),
	} {
		if a, _ := f.ask(t, line); a.OK || a.Code != codeBadRequest {
			t.Errorf("%.40q: %+v", line, a)
		}
	}
	if f.verifier.count() != 0 {
		t.Error("a malformed request reached PAM")
	}
}

func TestOtherLocalUsersGetNoAnswer(t *testing.T) {
	f := start(t, Defaults(), me()+1) // the panel is somebody else
	if _, answered := f.ask(t, req("root", "right horse", "192.0.2.1")); answered {
		t.Fatal("a process that is not the panel got an answer")
	}
	if f.verifier.count() != 0 {
		t.Fatal("a process that is not the panel reached PAM")
	}
}

func TestPAMUnavailableIsNotADenial(t *testing.T) {
	f := start(t, Defaults(), me())
	f.verifier.err = ErrUnavailable
	for i := 0; i < 6; i++ {
		if a, _ := f.ask(t, req("root", "right horse", "192.0.2.1")); a.Code != codeUnavailable {
			t.Fatalf("%+v", a)
		}
	}
	f.verifier.err = nil
	if a, _ := f.ask(t, req("root", "right horse", "192.0.2.1")); !a.OK {
		t.Fatalf("PAM outages counted as failures and locked root out: %+v", a)
	}
}

func TestConfig(t *testing.T) {
	dir := t.TempDir()
	if c, err := LoadConfig(filepath.Join(dir, "missing.json")); err != nil || c.Users[0] != "root" {
		t.Fatalf("defaults: %+v %v", c, err)
	}
	for _, bad := range []string{
		`{"users":[]}`, `{"users":["Root!"]}`, `{"surprise":1}`,
		`{"allow_from":{"admin":["10.0.0.0/8"]}}`, `{"allow_from":{"root":["not-an-ip"]}}`,
		`{"lockout":{"failures":0,"window_seconds":900,"block_seconds":900}}`, `{"global_per_minute":0}`,
	} {
		p := filepath.Join(dir, "c.json")
		_ = os.WriteFile(p, []byte(bad), 0o600)
		if _, err := LoadConfig(p); err == nil {
			t.Errorf("%s accepted", bad)
		}
	}
}
