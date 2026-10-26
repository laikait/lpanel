package main

import (
	"bufio"
	"bytes"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net"
	"net/netip"
	"sync"
	"time"
)

// Errors a Verifier returns.
var (
	// ErrDenied: wrong password, unknown user, locked or expired account.
	ErrDenied = errors.New("denied")
	// ErrUnavailable: PAM itself failed; the password was not judged.
	ErrUnavailable = errors.New("unavailable")
)

// Verifier checks a system password. pamVerifier is the real one.
type Verifier interface {
	Verify(user, password, rhost string) error
}

// Answer codes, the only ones the panel ever sees.
const (
	codeDenied       = "denied"
	codeLocked       = "locked"
	codeIPNotAllowed = "ip_not_allowed"
	codeBadRequest   = "bad_request"
	codeUnavailable  = "unavailable"
)

const (
	maxRequestBytes  = 4096
	maxPasswordBytes = 1024
	requestDeadline  = 5 * time.Second
	maxTrackedIPs    = 10000
)

type request struct {
	V        int    `json:"v"`
	User     string `json:"user"`
	Password string `json:"password"`
	IP       string `json:"ip"`
}

type answer struct {
	V          int    `json:"v"`
	OK         bool   `json:"ok"`
	Code       string `json:"code,omitempty"`
	RetryAfter int    `json:"retry_after,omitempty"`
}

// Server answers one question per connection.
type Server struct {
	Config    Config
	Verifier  Verifier
	ClientUID uint32
	PeerUID   func(*net.UnixConn) (uint32, error)
	Now       func() time.Time
	Log       *slog.Logger

	mu       sync.Mutex
	failures map[netip.Addr][]time.Time
	blocked  map[netip.Addr]time.Time
	recent   []time.Time // every check, for the global limit
	verifyMu sync.Mutex  // PAM modules are not all safe to run concurrently
}

// Serve accepts connections until ln is closed.
func (s *Server) Serve(ln net.Listener) error {
	for {
		c, err := ln.Accept()
		if err != nil {
			if errors.Is(err, net.ErrClosed) {
				return nil
			}
			var ne net.Error
			if errors.As(err, &ne) && ne.Timeout() {
				continue
			}
			return err
		}
		go s.handle(c)
	}
}

func (s *Server) handle(c net.Conn) {
	defer c.Close()
	// The wall clock, not s.Now: an I/O deadline is about real time even when the lockout's clock is faked.
	_ = c.SetDeadline(time.Now().Add(requestDeadline))

	uc, ok := c.(*net.UnixConn)
	if !ok {
		return
	}
	uid, err := s.PeerUID(uc)
	if err != nil || uid != s.ClientUID {
		s.Log.Warn("refused a connection from a process that is not the panel", "uid", uid, "err", err)
		return // not even an answer
	}

	line, err := bufio.NewReader(io.LimitReader(c, maxRequestBytes+1)).ReadBytes('\n')
	if err != nil || len(line) > maxRequestBytes {
		s.reply(c, answer{V: 1, Code: codeBadRequest})
		return
	}
	s.reply(c, s.decide(line))
}

func (s *Server) reply(w io.Writer, a answer) {
	data, _ := json.Marshal(a)
	_, _ = w.Write(append(data, '\n'))
}

// decide judges one request line. Every refusal is logged with its reason; the
// password never is.
func (s *Server) decide(line []byte) answer {
	var req request
	dec := json.NewDecoder(bytes.NewReader(line))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&req); err != nil || req.V != 1 || req.Password == "" || len(req.Password) > maxPasswordBytes {
		return answer{V: 1, Code: codeBadRequest}
	}
	ip, err := netip.ParseAddr(req.IP)
	if err != nil {
		return answer{V: 1, Code: codeBadRequest}
	}
	ip = ip.Unmap()
	log := s.Log.With("user", req.User, "ip", ip.String())

	if !s.Config.checks(req.User) {
		log.Warn("refused: user is not one authd checks")
		return answer{V: 1, Code: codeDenied}
	}
	if !s.Config.allowed(req.User, ip) {
		log.Warn("refused: address not in allow_from")
		return answer{V: 1, Code: codeIPNotAllowed}
	}
	if retry, locked := s.admit(ip); locked {
		log.Warn("refused: locked out", "retry_after", retry)
		return answer{V: 1, Code: codeLocked, RetryAfter: retry}
	}

	s.verifyMu.Lock()
	err = s.Verifier.Verify(req.User, req.Password, ip.String())
	s.verifyMu.Unlock()

	switch {
	case err == nil:
		s.succeeded(ip)
		log.Info("password accepted")
		return answer{V: 1, OK: true}
	case errors.Is(err, ErrDenied):
		s.failed(ip)
		log.Warn("password refused")
		return answer{V: 1, Code: codeDenied}
	default:
		log.Error("PAM unavailable", "err", err)
		return answer{V: 1, Code: codeUnavailable}
	}
}

// admit applies the lockout and the global limit, and counts this check.
func (s *Server) admit(ip netip.Addr) (retryAfter int, locked bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	now := s.Now()
	s.prune(now)

	if until, ok := s.blocked[ip]; ok && now.Before(until) {
		return seconds(until.Sub(now)), true
	}
	if len(s.recent) >= s.Config.GlobalPerMinute {
		return seconds(s.recent[0].Add(time.Minute).Sub(now)), true
	}
	if len(s.failures) >= maxTrackedIPs {
		// So many addresses failing at once is an attack; stop tracking and stop checking.
		return 60, true
	}
	s.recent = append(s.recent, now)
	return 0, false
}

func (s *Server) failed(ip netip.Addr) {
	s.mu.Lock()
	defer s.mu.Unlock()
	now := s.Now()
	s.failures[ip] = append(s.failures[ip], now)
	if len(s.failures[ip]) >= s.Config.Lockout.Failures {
		s.blocked[ip] = now.Add(s.Config.Lockout.Block())
		delete(s.failures, ip)
	}
}

func (s *Server) succeeded(ip netip.Addr) {
	s.mu.Lock()
	defer s.mu.Unlock()
	delete(s.failures, ip)
}

// prune drops what no longer counts. Called with s.mu held.
func (s *Server) prune(now time.Time) {
	if s.failures == nil {
		s.failures = map[netip.Addr][]time.Time{}
		s.blocked = map[netip.Addr]time.Time{}
	}
	cut := 0
	for cut < len(s.recent) && now.Sub(s.recent[cut]) >= time.Minute {
		cut++
	}
	s.recent = s.recent[cut:]

	window := s.Config.Lockout.Window()
	for ip, times := range s.failures {
		kept := times[:0]
		for _, t := range times {
			if now.Sub(t) < window {
				kept = append(kept, t)
			}
		}
		if len(kept) == 0 {
			delete(s.failures, ip)
		} else {
			s.failures[ip] = kept
		}
	}
	for ip, until := range s.blocked {
		if !now.Before(until) {
			delete(s.blocked, ip)
		}
	}
}

func seconds(d time.Duration) int {
	if d <= 0 {
		return 1
	}
	return int((d + time.Second - 1) / time.Second)
}
