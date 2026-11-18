// Package sshkeys writes the SSH keys an account may log in with.
//
// sshd reads an LPanel account's keys from KeysDir/<username> (the
// installer's sshd drop-in says AuthorizedKeysFile for the lpanel-users
// group), never from ~/.ssh: the account's own files cannot add a key the
// panel does not know about. Each file is root's, readable by the account's
// group only (sshd reads it as the account), and every line starts with
// "restrict,pty": no forwarding of any kind, no agent, no X11, no ~/.ssh/rc,
// but a terminal for the jailed shell.
//
// The panel sends an account's whole list each time (ssh.apply); an empty
// list removes the file.
package sshkeys

import (
	"context"
	"crypto/rsa"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"log/slog"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"

	"golang.org/x/crypto/ssh"

	"lpa/internal/protocol"
)

// Limits on what the panel may send.
const (
	MaxKeys    = 50
	MaxComment = 100
	MinRSABits = 2048
)

// Types are the key types accepted: no DSA, nothing unknown.
var Types = map[string]bool{
	ssh.KeyAlgoED25519:    true,
	ssh.KeyAlgoECDSA256:   true,
	ssh.KeyAlgoECDSA384:   true,
	ssh.KeyAlgoECDSA521:   true,
	ssh.KeyAlgoRSA:        true,
	ssh.KeyAlgoSKED25519:  true,
	ssh.KeyAlgoSKECDSA256: true,
}

var userRule = regexp.MustCompile(`^[a-z][a-z0-9]{1,15}$`)

// Key is one authorized key: its type, the base64 blob and a comment.
type Key struct {
	Type    string `json:"type"`
	Key     string `json:"key"`
	Comment string `json:"comment"`
}

// ApplyArgs are ssh.apply's arguments: an account's whole list.
type ApplyArgs struct {
	Username string `json:"username"`
	Keys     []Key  `json:"keys"`
}

// Applied is ssh.apply's answer.
type Applied struct {
	Keys         int      `json:"keys"`
	Fingerprints []string `json:"fingerprints"`
}

// Manager does the key operations.
type Manager struct {
	// KeysDir holds one file per account.
	KeysDir string
	Owner   func(name string) (uid, gid int, home string, err error)
	Log     *slog.Logger
	// Chown sets a key file's owner; tests replace it.
	Chown func(path string, uid, gid int) error

	mu sync.Mutex
}

// Defaults fills in what main and tests leave empty.
func (m *Manager) Defaults() {
	if m.KeysDir == "" {
		m.KeysDir = "/etc/ssh/lpanel/keys"
	}
	if m.Log == nil {
		m.Log = slog.New(slog.NewTextHandler(io.Discard, nil))
	}
	if m.Chown == nil {
		m.Chown = os.Lchown
	}
}

func bad(format string, a ...any) error {
	return protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf(format, a...))
}

// Parse checks one key and gives it back as a public key; a line with
// options is refused, since the agent sets them itself.
func Parse(k Key) (ssh.PublicKey, error) {
	if !Types[k.Type] {
		return nil, fmt.Errorf("%q is not a key type accepted here", k.Type)
	}
	pub, _, options, rest, err := ssh.ParseAuthorizedKey([]byte(k.Type + " " + k.Key))
	if err != nil {
		return nil, errors.New("the key is not a valid SSH public key")
	}
	if len(options) > 0 || len(strings.TrimSpace(string(rest))) > 0 || pub.Type() != k.Type {
		return nil, errors.New("the key is not a single SSH public key")
	}
	if pub.Type() == ssh.KeyAlgoRSA {
		ck, ok := pub.(ssh.CryptoPublicKey)
		if !ok {
			return nil, errors.New("the RSA key cannot be read")
		}
		r, ok := ck.CryptoPublicKey().(*rsa.PublicKey)
		if !ok || r.N.BitLen() < MinRSABits {
			return nil, fmt.Errorf("an RSA key needs at least %d bits", MinRSABits)
		}
	}
	return pub, nil
}

// cleanComment keeps a comment to printable characters, without newlines.
func cleanComment(c string) string {
	var b strings.Builder
	for _, r := range c {
		if r >= 0x20 && r != 0x7f {
			b.WriteRune(r)
		}
	}
	out := strings.TrimSpace(b.String())
	if len(out) > MaxComment {
		out = out[:MaxComment]
	}
	return out
}

func (a *ApplyArgs) check() ([]ssh.PublicKey, error) {
	if !userRule.MatchString(a.Username) {
		return nil, bad("username is not an account name")
	}
	if len(a.Keys) > MaxKeys {
		return nil, bad("an account has at most %d SSH keys", MaxKeys)
	}
	seen := map[string]bool{}
	out := make([]ssh.PublicKey, 0, len(a.Keys))
	for i, k := range a.Keys {
		pub, err := Parse(k)
		if err != nil {
			return nil, bad("key %d: %v", i+1, err)
		}
		fp := ssh.FingerprintSHA256(pub)
		if seen[fp] {
			return nil, bad("key %d is given twice", i+1)
		}
		seen[fp] = true
		out = append(out, pub)
	}
	return out, nil
}

// Render is the file sshd reads for an account.
func Render(keys []Key, pubs []ssh.PublicKey) []byte {
	var b strings.Builder
	b.WriteString("# Written by LPanel; changes here are overwritten. Keys are managed in the User panel.\n")
	for i, pub := range pubs {
		line := "restrict,pty " + strings.TrimSpace(string(ssh.MarshalAuthorizedKey(pub)))
		if c := cleanComment(keys[i].Comment); c != "" {
			line += " " + c
		}
		b.WriteString(line + "\n")
	}
	return []byte(b.String())
}

func (m *Manager) file(username string) string { return filepath.Join(m.KeysDir, username) }

// Apply writes an account's keys, or removes its file when it has none.
func (m *Manager) Apply(ctx context.Context, a ApplyArgs) (Applied, error) {
	pubs, err := a.check()
	if err != nil {
		return Applied{}, err
	}
	_, gid, _, err := m.Owner(a.Username)
	if err != nil {
		return Applied{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	out := Applied{Keys: len(pubs), Fingerprints: make([]string, 0, len(pubs))}
	for _, pub := range pubs {
		out.Fingerprints = append(out.Fingerprints, ssh.FingerprintSHA256(pub))
	}
	if len(pubs) == 0 {
		if err := os.Remove(m.file(a.Username)); err != nil && !errors.Is(err, fs.ErrNotExist) {
			return Applied{}, err
		}
		return out, nil
	}
	if err := os.MkdirAll(m.KeysDir, 0o711); err != nil {
		return Applied{}, err
	}
	// sshd reads the file as the account: root's, the account's group may read.
	if err := m.write(m.file(a.Username), Render(a.Keys, pubs), gid); err != nil {
		return Applied{}, err
	}
	m.Log.Info("ssh keys written", "username", a.Username, "keys", len(pubs))
	return out, nil
}

// RemoveFor takes a terminated account's keys away.
func (m *Manager) RemoveFor(ctx context.Context, username string) error {
	if !userRule.MatchString(username) {
		return nil
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if err := os.Remove(m.file(username)); err != nil && !errors.Is(err, fs.ErrNotExist) {
		return err
	}
	return nil
}

// write replaces path with data through a temporary file, root's and the
// group's (0640).
func (m *Manager) write(path string, data []byte, gid int) error {
	tmp, err := os.CreateTemp(filepath.Dir(path), "."+filepath.Base(path)+".tmp-*")
	if err != nil {
		return err
	}
	name := tmp.Name()
	defer os.Remove(name)
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Sync(); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	if err := m.Chown(name, 0, gid); err != nil {
		return err
	}
	if err := os.Chmod(name, 0o640); err != nil {
		return err
	}
	return os.Rename(name, path)
}
