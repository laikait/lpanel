package sshkeys

import (
	"context"
	"crypto/ecdsa"
	"crypto/ed25519"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/rsa"
	"encoding/base64"
	"errors"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"

	"golang.org/x/crypto/ssh"

	"lpa/internal/protocol"
)

func key(t *testing.T, raw any, comment string) Key {
	t.Helper()
	pub, err := ssh.NewPublicKey(raw)
	if err != nil {
		t.Fatal(err)
	}
	return Key{Type: pub.Type(), Key: base64.StdEncoding.EncodeToString(pub.Marshal()), Comment: comment}
}

func ed(t *testing.T, comment string) Key {
	p, _, _ := ed25519.GenerateKey(rand.Reader)
	return key(t, p, comment)
}

type chown struct{ uid, gid int }

func manager(t *testing.T) (*Manager, map[string]chown) {
	if runtime.GOOS == "windows" {
		t.Skip("file modes")
	}
	owners := map[string]chown{}
	m := &Manager{
		KeysDir: filepath.Join(t.TempDir(), "keys"),
		Owner: func(name string) (int, int, string, error) {
			if name != "shop7" {
				return 0, 0, "", protocol.Fail("not_ours", name)
			}
			return 1001, 1002, "/home/shop7", nil
		},
		Chown: func(path string, uid, gid int) error { owners[filepath.Base(path)] = chown{uid, gid}; return nil },
	}
	m.Defaults()
	return m, owners
}

func code(err error) string {
	var f *protocol.Error
	if errors.As(err, &f) {
		return f.Code
	}
	return ""
}

func TestApplyWritesRestrictedLines(t *testing.T) {
	m, _ := manager(t)
	ek, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	keys := []Key{ed(t, "laptop\nrestrict"), key(t, &ek.PublicKey, "")}
	out, err := m.Apply(context.Background(), ApplyArgs{Username: "shop7", Keys: keys})
	if err != nil {
		t.Fatal(err)
	}
	if out.Keys != 2 || len(out.Fingerprints) != 2 || !strings.HasPrefix(out.Fingerprints[0], "SHA256:") {
		t.Fatalf("answer: %+v", out)
	}
	data, err := os.ReadFile(filepath.Join(m.KeysDir, "shop7"))
	if err != nil {
		t.Fatal(err)
	}
	lines := strings.Split(strings.TrimSpace(string(data)), "\n")
	if len(lines) != 3 || !strings.HasPrefix(lines[0], "#") {
		t.Fatalf("file: %q", data)
	}
	for _, l := range lines[1:] {
		if !strings.HasPrefix(l, "restrict,pty ") {
			t.Errorf("line without restrict: %q", l)
		}
	}
	// The comment lost its newline and cannot add a line of its own.
	if !strings.HasSuffix(lines[1], " laptoprestrict") {
		t.Errorf("comment: %q", lines[1])
	}
	// sshd reads every line back as a key with those options.
	for _, l := range lines[1:] {
		_, _, opts, _, err := ssh.ParseAuthorizedKey([]byte(l))
		if err != nil || strings.Join(opts, ",") != "restrict,pty" {
			t.Errorf("%q: %v %v", l, opts, err)
		}
	}
}

func TestApplyModeOwnerAndRemoval(t *testing.T) {
	m, owners := manager(t)
	if _, err := m.Apply(context.Background(), ApplyArgs{Username: "shop7", Keys: []Key{ed(t, "a")}}); err != nil {
		t.Fatal(err)
	}
	f := filepath.Join(m.KeysDir, "shop7")
	info, err := os.Stat(f)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o640 {
		t.Errorf("mode %v", info.Mode())
	}
	for name, o := range owners {
		if !strings.HasPrefix(name, ".shop7.tmp-") || o != (chown{0, 1002}) {
			t.Errorf("chown %s %+v", name, o)
		}
	}
	if _, err := m.Apply(context.Background(), ApplyArgs{Username: "shop7"}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(f); !os.IsNotExist(err) {
		t.Errorf("an empty list left the file: %v", err)
	}
	// Removing again, or for a terminated account, is fine.
	if err := m.RemoveFor(context.Background(), "shop7"); err != nil {
		t.Error(err)
	}
}

func TestApplyRefuses(t *testing.T) {
	m, _ := manager(t)
	rk, _ := rsa.GenerateKey(rand.Reader, 1024)
	good := ed(t, "")
	cases := map[string]ApplyArgs{
		"bad username":   {Username: "../etc", Keys: []Key{good}},
		"twice":          {Username: "shop7", Keys: []Key{good, good}},
		"small rsa":      {Username: "shop7", Keys: []Key{key(t, &rk.PublicKey, "")}},
		"dsa":            {Username: "shop7", Keys: []Key{{Type: "ssh-dss", Key: good.Key}}},
		"type mismatch":  {Username: "shop7", Keys: []Key{{Type: "ssh-rsa", Key: good.Key}}},
		"not base64":     {Username: "shop7", Keys: []Key{{Type: "ssh-ed25519", Key: "!!!"}}},
		"second key":     {Username: "shop7", Keys: []Key{{Type: good.Type, Key: good.Key + "\nssh-ed25519 " + good.Key}}},
		"options inside": {Username: "shop7", Keys: []Key{{Type: good.Type, Key: good.Key + " x\ncommand=\"sh\" ssh-ed25519 " + good.Key}}},
	}
	for name, a := range cases {
		if _, err := m.Apply(context.Background(), a); code(err) != protocol.CodeBadArgs {
			t.Errorf("%s: %v", name, err)
		}
	}
	if _, err := m.Apply(context.Background(), ApplyArgs{Username: "other", Keys: []Key{good}}); code(err) != "not_ours" {
		t.Errorf("not ours: %v", err)
	}
	if _, err := os.Stat(m.KeysDir); !os.IsNotExist(err) {
		t.Error("a refused request wrote something")
	}
}
