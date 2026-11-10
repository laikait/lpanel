package protocol

import (
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"flag"
	"os"
	"path/filepath"
	"testing"
	"time"
)

var update = flag.Bool("update", false, "rewrite testdata/vectors.json")

// vector is one signed request. The panel's PHP tests read the same file
// (tests/Unit/AgentProtocolTest.php) and must produce identical signatures.
type vector struct {
	Name        string `json:"name"`
	Seed        string `json:"seed"` // base64, 32 bytes: the Ed25519 private key seed
	PublicKey   string `json:"public_key"`
	ID          string `json:"id"`
	Op          string `json:"op"`
	TS          int64  `json:"ts"`
	Nonce       string `json:"nonce"`
	Args        string `json:"args"`
	SignedBytes string `json:"signed_bytes"`
	Signature   string `json:"signature"`
}

func vectors() []vector {
	seed := make([]byte, ed25519.SeedSize)
	for i := range seed {
		seed[i] = byte(i + 1)
	}
	key := ed25519.NewKeyFromSeed(seed)
	cases := []struct{ name, id, op, nonce, args string }{
		{"empty args", "0123456789abcdef0123456789abcdef", "system.ping", "AAAAAAAAAAAAAAAAAAAAAA", `{}`},
		{"args with unicode and spaces", "req_2026-10-29_000001", "system.facts", "q1w2e3r4t5y6u7i8o9p0aZ", `{"note": "Ünïcødé — ok", "n": 1}`},
	}
	out := make([]vector, 0, len(cases))
	for _, c := range cases {
		out = append(out, vector{
			Name: c.name, Seed: base64.StdEncoding.EncodeToString(seed),
			PublicKey: base64.StdEncoding.EncodeToString(key.Public().(ed25519.PublicKey)),
			ID:        c.id, Op: c.op, TS: 1793232000, Nonce: c.nonce, Args: c.args,
			SignedBytes: string(SignedBytes(c.id, c.op, 1793232000, c.nonce, []byte(c.args))),
			Signature:   Sign(key, c.id, c.op, 1793232000, c.nonce, []byte(c.args)),
		})
	}
	return out
}

func TestGoldenVectors(t *testing.T) {
	path := filepath.Join("testdata", "vectors.json")
	want, _ := json.MarshalIndent(vectors(), "", "  ")
	want = append(want, '\n')
	if *update {
		if err := os.MkdirAll("testdata", 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(path, want, 0o644); err != nil {
			t.Fatal(err)
		}
	}
	got, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("%v (run go test ./internal/protocol -update)", err)
	}
	if string(got) != string(want) {
		t.Fatal("testdata/vectors.json is out of date; run go test ./internal/protocol -update")
	}
}

func signed(t *testing.T, key ed25519.PrivateKey, ts int64, args string) *Request {
	t.Helper()
	r := &Request{V: 1, ID: "0123456789abcdef", Op: "system.ping", TS: ts, Nonce: "AAAAAAAAAAAAAAAAAAAAAA", Args: json.RawMessage(args)}
	r.Sig = Sign(key, r.ID, r.Op, r.TS, r.Nonce, r.Args)
	return r
}

func TestCheck(t *testing.T) {
	pub, key, _ := ed25519.GenerateKey(nil)
	_, other, _ := ed25519.GenerateKey(nil)
	now := time.Unix(1793232000, 0)

	if err := Check(signed(t, key, now.Unix(), `{}`), pub, now); err != nil {
		t.Fatalf("a good request was refused: %v", err)
	}

	tampered := signed(t, key, now.Unix(), `{"a":1}`)
	tampered.Args = json.RawMessage(`{"a":2}`)
	wrongOp := signed(t, key, now.Unix(), `{}`)
	wrongOp.Op = "system.facts"

	cases := map[string]struct {
		req  *Request
		code string
	}{
		"other key":       {signed(t, other, now.Unix(), `{}`), CodeBadSignature},
		"tampered args":   {tampered, CodeBadSignature},
		"op swapped":      {wrongOp, CodeBadSignature},
		"too old":         {signed(t, key, now.Unix()-61, `{}`), CodeStale},
		"from the future": {signed(t, key, now.Unix()+61, `{}`), CodeStale},
		"args not object": {signed(t, key, now.Unix(), `[]`), CodeBadRequest},
	}
	for name, c := range cases {
		err := Check(c.req, pub, now)
		if err == nil || err.Code != c.code {
			t.Errorf("%s: got %v, want %s", name, err, c.code)
		}
	}

	bad := signed(t, key, now.Unix(), `{}`)
	bad.ID = "../../etc/passwd"
	if err := Check(bad, pub, now); err == nil || err.Code != CodeBadRequest {
		t.Errorf("a path as an id was not refused: %v", err)
	}
}
