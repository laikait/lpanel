// Package protocol is the wire format between the panel and lpa (ADR-0003).
//
// One JSON line each way over the agent's Unix socket. A request:
//
//	{"v":1,"id":"…","op":"system.facts","ts":1730000000,"nonce":"…","args":{…},"sig":"…"}
//
// The signature is Ed25519, made with the panel's key, over SignedBytes: the
// fields joined by newlines and the SHA-256 of the args exactly as sent. No
// JSON canonicalisation is needed, because the agent hashes the raw bytes it
// received and the panel hashes the bytes it wrote.
//
// Responses are not signed: the socket lives in a root-owned directory, so
// whatever answers on it is the agent.
package protocol

import (
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"regexp"
	"strconv"
	"time"
)

const (
	Version = 1

	// MaxLine is the longest request line the agent reads.
	MaxLine = 128 << 10
	// MaxArgs is the largest args object.
	MaxArgs = 64 << 10
	// Skew is how far a request's timestamp may be from the agent's clock.
	Skew = 60 * time.Second
)

// Error codes the agent answers with. The panel shows a message per code.
const (
	CodeBadRequest   = "bad_request"   // not a request this version understands
	CodeBadSignature = "bad_signature" // not signed with the panel's key
	CodeStale        = "stale"         // timestamp outside Skew
	CodeReplay       = "replay"        // this nonce was already used
	CodeUnknownOp    = "unknown_op"    // no such operation
	CodeBadArgs      = "bad_args"      // the operation refused its arguments
	CodeIDReused     = "id_reused"     // this id was used for a different request
	CodeBusy         = "busy"          // too many operations running
	CodeFailed       = "failed"        // the operation ran and failed
	CodeInterrupted  = "interrupted"   // the agent stopped while it ran; effect unknown
	CodeTimeout      = "timeout"       // the operation ran out of time
	CodeNotFound     = "not_found"     // op.status: no such id
)

// Statuses of a response.
const (
	StatusDone     = "done"     // finished; Result holds the answer
	StatusFailed   = "failed"   // finished with Error
	StatusAccepted = "accepted" // started in the background; ask op.status
	StatusRunning  = "running"  // op.status: still running
	StatusRefused  = "refused"  // never started; Error says why
)

var (
	idPattern    = regexp.MustCompile(`^[A-Za-z0-9_-]{16,64}$`)
	opPattern    = regexp.MustCompile(`^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$`)
	noncePattern = regexp.MustCompile(`^[A-Za-z0-9_-]{22}$`)
)

// Request is what the panel sends. Args keeps the exact bytes received.
type Request struct {
	V     int             `json:"v"`
	ID    string          `json:"id"`
	Op    string          `json:"op"`
	TS    int64           `json:"ts"`
	Nonce string          `json:"nonce"`
	Args  json.RawMessage `json:"args"`
	Sig   string          `json:"sig"`
}

// Error is a machine-readable code and a sentence for a person.
type Error struct {
	Code    string `json:"code"`
	Message string `json:"message"`
}

func (e *Error) Error() string { return e.Code + ": " + e.Message }

// Fail makes an Error.
func Fail(code, message string) *Error { return &Error{Code: code, Message: message} }

// Response is what the agent answers.
type Response struct {
	V      int    `json:"v"`
	ID     string `json:"id,omitempty"`
	Status string `json:"status"`
	Result any    `json:"result,omitempty"`
	Error  *Error `json:"error,omitempty"`
}

// ValidID reports whether id is shaped like a request id. Ids name journal
// files, so nothing else may reach the filesystem.
func ValidID(id string) bool { return idPattern.MatchString(id) }

// SignedBytes is what the signature covers.
func SignedBytes(id, op string, ts int64, nonce string, args []byte) []byte {
	sum := sha256.Sum256(args)
	return []byte("lpa-request-v1\n" + id + "\n" + op + "\n" + strconv.FormatInt(ts, 10) + "\n" + nonce + "\n" + hex.EncodeToString(sum[:]))
}

// Sign makes a request's signature (the panel does this in PHP; Go needs it
// for tests and the golden vectors).
func Sign(key ed25519.PrivateKey, id, op string, ts int64, nonce string, args []byte) string {
	return base64.StdEncoding.EncodeToString(ed25519.Sign(key, SignedBytes(id, op, ts, nonce, args)))
}

// Check validates a decoded request's shape, clock and signature. It does
// not look at replays; the server does, because it holds the nonce cache.
func Check(req *Request, panel ed25519.PublicKey, now time.Time) *Error {
	if req.V != Version {
		return Fail(CodeBadRequest, "unsupported protocol version")
	}
	if !idPattern.MatchString(req.ID) || !opPattern.MatchString(req.Op) || !noncePattern.MatchString(req.Nonce) {
		return Fail(CodeBadRequest, "id, op or nonce is malformed")
	}
	if len(req.Args) == 0 || len(req.Args) > MaxArgs || req.Args[0] != '{' {
		return Fail(CodeBadRequest, "args must be a JSON object")
	}
	sig, err := base64.StdEncoding.DecodeString(req.Sig)
	if err != nil || len(sig) != ed25519.SignatureSize {
		return Fail(CodeBadSignature, "signature is malformed")
	}
	// The signature is checked before the clock, so an unsigned request
	// learns nothing about the agent's time.
	if !ed25519.Verify(panel, SignedBytes(req.ID, req.Op, req.TS, req.Nonce, req.Args), sig) {
		return Fail(CodeBadSignature, "not signed by the panel")
	}
	skew := now.Sub(time.Unix(req.TS, 0))
	if skew > Skew || skew < -Skew {
		return Fail(CodeStale, "timestamp is too far from the agent's clock")
	}
	return nil
}

// ParsePublicKey reads the panel's public key: base64 of 32 bytes.
func ParsePublicKey(text string) (ed25519.PublicKey, error) {
	raw, err := base64.StdEncoding.DecodeString(text)
	if err != nil || len(raw) != ed25519.PublicKeySize {
		return nil, errors.New("the panel key must be base64 of 32 bytes")
	}
	return ed25519.PublicKey(raw), nil
}
