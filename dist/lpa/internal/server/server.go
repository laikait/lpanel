// Package server answers the panel on the agent's Unix socket.
//
// One request per connection, one JSON line each way. In order, a request
// must: come from an allowed uid (SO_PEERCRED), be a well-formed version-1
// request, carry the panel's signature, be within the clock skew, use a nonce
// not seen before, and name a registered operation. Only then does anything
// run. Operations that change the system are claimed in the journal first,
// so a retried request returns the first outcome instead of running twice.
package server

import (
	"bufio"
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"log/slog"
	"net"
	"slices"
	"sync"
	"time"

	"lpa/internal/journal"
	"lpa/internal/ops"
	"lpa/internal/protocol"
)

const (
	readDeadline = 5 * time.Second
	maxNonces    = 100_000
)

// Server holds what answering needs. Fields are set once, before Serve.
type Server struct {
	Registry    *ops.Registry
	Journal     *journal.Journal
	PanelKey    ed25519.PublicKey
	AllowedUIDs []int
	PeerUID     func(net.Conn) (int, error)
	Now         func() time.Time
	Log         *slog.Logger
	MaxRunning  int

	mu      sync.Mutex
	nonces  map[string]time.Time
	running int
	bg      sync.WaitGroup
}

// Serve accepts connections until the listener closes.
func (s *Server) Serve(ln net.Listener) error {
	for {
		conn, err := ln.Accept()
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
		go s.serveConn(conn)
	}
}

// Wait blocks until background operations have finished (for shutdown).
func (s *Server) Wait() { s.bg.Wait() }

func (s *Server) serveConn(conn net.Conn) {
	defer conn.Close()
	_ = conn.SetReadDeadline(time.Now().Add(readDeadline))

	uid, err := s.PeerUID(conn)
	if err != nil || !slices.Contains(s.AllowedUIDs, uid) {
		// Not even an answer: a process that is not the panel learns nothing.
		s.Log.Warn("refused a connection from a process that is not the panel", "uid", uid, "err", err)
		return
	}

	line, err := bufio.NewReader(io.LimitReader(conn, protocol.MaxLine+1)).ReadBytes('\n')
	var resp protocol.Response
	if err != nil || len(line) > protocol.MaxLine {
		resp = refuse("", protocol.CodeBadRequest, "send one request line of at most 128 KiB")
	} else {
		resp = s.Handle(line)
	}

	_ = conn.SetWriteDeadline(time.Now().Add(readDeadline))
	data, _ := json.Marshal(resp)
	_, _ = conn.Write(append(data, '\n'))
}

// Handle answers one request line. Separate from the socket so tests can
// drive it directly.
func (s *Server) Handle(line []byte) protocol.Response {
	started := s.Now()
	var req protocol.Request
	dec := json.NewDecoder(bytes.NewReader(bytes.TrimSpace(line)))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&req); err != nil {
		return refuse("", protocol.CodeBadRequest, "not a request")
	}
	if perr := protocol.Check(&req, s.PanelKey, started); perr != nil {
		s.Log.Warn("refused a request", "op", req.Op, "id", req.ID, "code", perr.Code)
		return refuse(req.ID, perr.Code, perr.Message)
	}
	if !s.freshNonce(req.Nonce, started) {
		s.Log.Warn("refused a replayed request", "op", req.Op, "id", req.ID)
		return refuse(req.ID, protocol.CodeReplay, "this request was already received")
	}

	resp := s.dispatch(&req)
	s.Log.Info("request", "op", req.Op, "id", req.ID, "status", resp.Status, "ms", s.Now().Sub(started).Milliseconds())
	return resp
}

func (s *Server) dispatch(req *protocol.Request) protocol.Response {
	if req.Op == "op.status" {
		return s.status(req)
	}
	op, ok := s.Registry.Get(req.Op)
	if !ok {
		return refuse(req.ID, protocol.CodeUnknownOp, "no operation called "+req.Op)
	}
	if !s.take() {
		return refuse(req.ID, protocol.CodeBusy, "too many operations are running; try again shortly")
	}

	if op.ReadOnly {
		defer s.release()
		result, perr := s.run(op, req.Args)
		return answer(req.ID, result, perr)
	}

	sum := sha256.Sum256(req.Args)
	entry, err := s.Journal.Claim(req.ID, req.Op, hex.EncodeToString(sum[:]))
	if errors.Is(err, journal.ErrExists) {
		s.release()
		if entry.Op != req.Op || entry.ArgsSHA256 != hex.EncodeToString(sum[:]) {
			return refuse(req.ID, protocol.CodeIDReused, "this id was used for a different request")
		}
		return fromEntry(entry)
	}
	if err != nil {
		s.release()
		return failed(req.ID, protocol.CodeFailed, "the journal could not record the request: "+err.Error())
	}

	work := func() protocol.Response {
		defer s.release()
		result, perr := s.run(op, req.Args)
		if perr != nil {
			if jerr := s.Journal.Fail(entry, perr.Code, perr.Message); jerr != nil {
				s.Log.Error("journal write failed", "id", req.ID, "err", jerr)
			}
		} else if jerr := s.Journal.Succeed(entry, result); jerr != nil {
			s.Log.Error("journal write failed", "id", req.ID, "err", jerr)
		}
		return answer(req.ID, result, perr)
	}

	if op.Async {
		s.bg.Add(1)
		go func() {
			defer s.bg.Done()
			resp := work()
			s.Log.Info("background operation finished", "op", req.Op, "id", req.ID, "status", resp.Status)
		}()
		return protocol.Response{V: protocol.Version, ID: req.ID, Status: protocol.StatusAccepted}
	}
	return work()
}

// run calls an operation with its timeout, turning panics and timeouts into
// errors the panel can show.
func (s *Server) run(op ops.Op, args json.RawMessage) (result any, perr *protocol.Error) {
	ctx, cancel := context.WithTimeout(context.Background(), op.Timeout)
	defer cancel()
	defer func() {
		if p := recover(); p != nil {
			s.Log.Error("operation panicked", "op", op.Name, "panic", fmt.Sprint(p))
			result, perr = nil, protocol.Fail(protocol.CodeFailed, "the operation failed unexpectedly")
		}
	}()

	result, err := op.Run(ctx, args)
	switch {
	case err == nil:
		return result, nil
	case errors.Is(err, context.DeadlineExceeded) || ctx.Err() != nil:
		return nil, protocol.Fail(protocol.CodeTimeout, fmt.Sprintf("%s did not finish within %s", op.Name, op.Timeout))
	}
	var pe *protocol.Error
	if errors.As(err, &pe) {
		return nil, pe
	}
	return nil, protocol.Fail(protocol.CodeFailed, err.Error())
}

func (s *Server) status(req *protocol.Request) protocol.Response {
	var args struct {
		ID string `json:"id"`
	}
	if err := ops.Decode(req.Args, &args); err != nil || args.ID == "" {
		return refuse(req.ID, protocol.CodeBadArgs, "op.status needs {\"id\": …}")
	}
	if !protocol.ValidID(args.ID) {
		return refuse(req.ID, protocol.CodeBadArgs, "that is not an operation id")
	}
	entry, err := s.Journal.Get(args.ID)
	if errors.Is(err, fs.ErrNotExist) {
		return refuse(req.ID, protocol.CodeNotFound, "no operation with that id")
	}
	if err != nil {
		return failed(req.ID, protocol.CodeFailed, err.Error())
	}
	return fromEntry(entry)
}

// freshNonce records a nonce, refusing one seen within twice the clock skew
// (after that the timestamp check refuses the request anyway).
func (s *Server) freshNonce(nonce string, now time.Time) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.nonces == nil {
		s.nonces = map[string]time.Time{}
	}
	if len(s.nonces) >= maxNonces/2 {
		for n, seen := range s.nonces {
			if now.Sub(seen) > 2*protocol.Skew {
				delete(s.nonces, n)
			}
		}
	}
	if _, seen := s.nonces[nonce]; seen || len(s.nonces) >= maxNonces {
		return false
	}
	s.nonces[nonce] = now
	return true
}

func (s *Server) take() bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.running >= s.MaxRunning {
		return false
	}
	s.running++
	return true
}

func (s *Server) release() {
	s.mu.Lock()
	s.running--
	s.mu.Unlock()
}

func fromEntry(e *journal.Entry) protocol.Response {
	switch e.State {
	case journal.Running:
		return protocol.Response{V: protocol.Version, ID: e.ID, Status: protocol.StatusRunning}
	case journal.Done:
		return protocol.Response{V: protocol.Version, ID: e.ID, Status: protocol.StatusDone, Result: e.Result}
	default:
		return failed(e.ID, e.ErrCode, e.ErrMessage)
	}
}

func answer(id string, result any, perr *protocol.Error) protocol.Response {
	if perr != nil {
		return failed(id, perr.Code, perr.Message)
	}
	return protocol.Response{V: protocol.Version, ID: id, Status: protocol.StatusDone, Result: result}
}

func refuse(id, code, message string) protocol.Response {
	return protocol.Response{V: protocol.Version, ID: id, Status: protocol.StatusRefused, Error: protocol.Fail(code, message)}
}

func failed(id, code, message string) protocol.Response {
	return protocol.Response{V: protocol.Version, ID: id, Status: protocol.StatusFailed, Error: protocol.Fail(code, message)}
}
