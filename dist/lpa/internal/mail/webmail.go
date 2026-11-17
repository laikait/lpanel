package mail

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"

	"lpa/internal/protocol"
)

// Webmail's single sign-on.
//
// The panel never has a mailbox's password, only its bcrypt hash. To open
// webmail as a mailbox, it asks for a sign-on password (SSO): the agent
// makes a random one, keeps it for a few hours, and Dovecot accepts it for
// that mailbox beside the mailbox's own (a second passdb):
//
//	/etc/dovecot/lpanel/sso.d/<domain>    each mailbox's sign-on password, hashed (Dovecot reads it)
//	/etc/dovecot/lpanel/sso.keep/<domain> the passwords and when they expire (root only)
//
// Exim checks logins through Dovecot, so webmail sends mail with it too, as
// the mailbox. A second sign-on while one is still good gives the same
// password, so a webmail session already open keeps working. A suspended
// account, a removed mailbox or an expired password leaves the file.

// Error codes.
const (
	CodeNoMailbox = "mail_no_mailbox" // the mailbox to sign on to is not set up
	CodeSuspended = "mail_suspended"  // the account is suspended
)

const (
	// SSOHours is how long a sign-on password lasts, unless asked for less.
	SSOHours = 8
	// ssoRenew is how much life an earlier password must have left to be
	// given again (and extended).
	ssoRenew = time.Hour
)

// SSOArgs name a mailbox to sign on to.
type SSOArgs struct {
	Username string `json:"username"`
	Address  string `json:"address"`
	// Hours the password lasts (1–24; 0 is SSOHours).
	Hours int `json:"hours"`
}

// SSOResult is the login.
type SSOResult struct {
	User     string `json:"user"`
	Password string `json:"password"`
	Expires  int64  `json:"expires"`
}

// SSORevokeArgs name the sign-on passwords to forget: one mailbox's, or
// (Address empty) every one of the account's.
type SSORevokeArgs struct {
	Username string `json:"username"`
	Address  string `json:"address"`
}

// ssoEntry is one kept password.
type ssoEntry struct {
	Password string `json:"password"`
	Hash     string `json:"hash"`
	Expires  int64  `json:"expires"`
}

func (m *Manager) ssoPath(domain string) string {
	return filepath.Join(m.DovecotDir, "sso.d", domain)
}

func (m *Manager) ssoKeepPath(domain string) string {
	return filepath.Join(m.DovecotDir, "sso.keep", domain)
}

func (m *Manager) now() time.Time {
	if m.Now != nil {
		return m.Now()
	}
	return time.Now()
}

// SSO gives a sign-on password for one of the account's mailboxes.
func (m *Manager) SSO(_ context.Context, a SSOArgs) (SSOResult, error) {
	if !m.Enabled {
		return SSOResult{}, protocol.Fail(CodeOff, "the agent is configured without mail")
	}
	if err := checkAccount(a.Username); err != nil {
		return SSOResult{}, err
	}
	local, domain, err := splitAddress(a.Address)
	if err != nil {
		return SSOResult{}, err
	}
	hours := a.Hours
	if hours == 0 {
		hours = SSOHours
	}
	if hours < 1 || hours > 24 {
		return SSOResult{}, badArgs("hours must be 1 to 24")
	}
	if _, _, _, err := m.Owner(a.Username); err != nil {
		return SSOResult{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if owner := m.ownerOf(domain); owner != a.Username {
		return SSOResult{}, protocol.Fail(CodeNoMailbox, a.Address+" is not a mailbox of this account")
	}
	if m.suspended(domain) {
		return SSOResult{}, protocol.Fail(CodeSuspended, "the account is suspended")
	}
	boxes := m.mailboxesOf(domain)
	if !boxes[local] {
		return SSOResult{}, protocol.Fail(CodeNoMailbox, a.Address+" is not a mailbox of this account")
	}
	_, dovecot, err := m.groups()
	if err != nil {
		return SSOResult{}, err
	}
	keep := m.readKeep(domain)
	now := m.now()
	until := now.Add(time.Duration(hours) * time.Hour).Unix()
	e, ok := keep[local]
	if !ok || e.Expires < now.Add(ssoRenew).Unix() || e.Hash == "" {
		password, err := randomPassword()
		if err != nil {
			return SSOResult{}, err
		}
		e = ssoEntry{Password: password, Hash: ssha256(password)}
	}
	if until > e.Expires {
		e.Expires = until
	}
	keep[local] = e
	if err := m.writeSSO(domain, keep, boxes, dovecot); err != nil {
		return SSOResult{}, err
	}
	m.Log.Info("webmail sign-on", "address", a.Address, "username", a.Username)
	return SSOResult{User: local + "@" + domain, Password: e.Password, Expires: e.Expires}, nil
}

// SSORevoke forgets sign-on passwords.
func (m *Manager) SSORevoke(_ context.Context, a SSORevokeArgs) (map[string]int, error) {
	if !m.Enabled {
		return nil, protocol.Fail(CodeOff, "the agent is configured without mail")
	}
	if err := checkAccount(a.Username); err != nil {
		return nil, err
	}
	var only, local string
	if a.Address != "" {
		l, d, err := splitAddress(a.Address)
		if err != nil {
			return nil, err
		}
		only, local = d, l
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	domains, err := m.domainsOf(a.Username)
	if err != nil {
		return nil, err
	}
	_, dovecot, err := m.groups()
	if err != nil {
		return nil, err
	}
	removed := 0
	for _, d := range domains {
		if only != "" && d != only {
			continue
		}
		keep := m.readKeep(d)
		for l := range keep {
			if local == "" || l == local {
				delete(keep, l)
				removed++
			}
		}
		if err := m.writeSSO(d, keep, m.mailboxesOf(d), dovecot); err != nil {
			return nil, err
		}
	}
	return map[string]int{"removed": removed}, nil
}

// SSOExpire forgets every expired sign-on password on the server.
func (m *Manager) SSOExpire(context.Context) (map[string]int, error) {
	if !m.Enabled {
		return nil, protocol.Fail(CodeOff, "the agent is configured without mail")
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	entries, err := os.ReadDir(filepath.Join(m.DovecotDir, "sso.keep"))
	if errors.Is(err, fs.ErrNotExist) {
		return map[string]int{"removed": 0}, nil
	}
	if err != nil {
		return nil, err
	}
	_, dovecot, err := m.groups()
	if err != nil {
		return nil, err
	}
	removed := 0
	for _, e := range entries {
		d := e.Name()
		if !e.Type().IsRegular() || checkDomain(d) != nil {
			continue
		}
		keep := m.readKeep(d)
		before := len(keep)
		if err := m.writeSSO(d, keep, m.mailboxesOf(d), dovecot); err != nil {
			return nil, err
		}
		removed += before - len(m.readKeep(d))
	}
	return map[string]int{"removed": removed}, nil
}

// applySSO brings a domain's sign-on file in step with its mailboxes (Apply).
// The file is there for every domain, empty when nobody signed on: Dovecot
// treats a missing one as a failure of the server, not of the password.
func (m *Manager) applySSO(d Domain, suspended bool, dovecot int) (bool, error) {
	keep := m.readKeep(d.Name)
	boxes := map[string]bool{}
	if !suspended {
		for _, b := range d.Mailboxes {
			boxes[b.Local] = true
		}
	}
	before, _ := os.ReadFile(m.ssoPath(d.Name))
	if err := m.writeSSO(d.Name, keep, boxes, dovecot); err != nil {
		return false, err
	}
	after, _ := os.ReadFile(m.ssoPath(d.Name))
	return string(before) != string(after), nil
}

// writeSSO drops what has expired or has no mailbox, and writes both files.
func (m *Manager) writeSSO(domain string, keep map[string]ssoEntry, boxes map[string]bool, dovecot int) error {
	now := m.now().Unix()
	for l, e := range keep {
		if e.Expires <= now || !boxes[l] || checkLocal(l) != nil || e.Hash == "" {
			delete(keep, l)
		}
	}
	if err := m.dir(filepath.Join(m.DovecotDir, "sso.keep"), 0o700, 0); err != nil {
		return err
	}
	if err := m.dir(filepath.Join(m.DovecotDir, "sso.d"), 0o750, dovecot); err != nil {
		return err
	}
	if len(keep) == 0 {
		if err := os.RemoveAll(m.ssoKeepPath(domain)); err != nil {
			return err
		}
	} else {
		data, err := json.Marshal(keep)
		if err != nil {
			return err
		}
		if _, err := m.writeFile(m.ssoKeepPath(domain), data, 0o600, 0); err != nil {
			return err
		}
	}
	locals := make([]string, 0, len(keep))
	for l := range keep {
		locals = append(locals, l)
	}
	sort.Strings(locals)
	var b strings.Builder
	b.WriteString("# Generated by LPanel. Webmail sign-on passwords of " + domain + ", each until it expires.\n")
	for _, l := range locals {
		fmt.Fprintf(&b, "%s:%s\n", l, keep[l].Hash)
	}
	_, err := m.writeFile(m.ssoPath(domain), []byte(b.String()), 0o640, dovecot)
	return err
}

// readKeep reads a domain's kept passwords (none when unreadable).
func (m *Manager) readKeep(domain string) map[string]ssoEntry {
	keep := map[string]ssoEntry{}
	data, err := os.ReadFile(m.ssoKeepPath(domain))
	if err != nil || json.Unmarshal(data, &keep) != nil {
		return map[string]ssoEntry{}
	}
	return keep
}

// mailboxesOf reads which mailboxes a domain has now (Dovecot's file).
func (m *Manager) mailboxesOf(domain string) map[string]bool {
	out := map[string]bool{}
	data, err := os.ReadFile(m.passwdPath(domain))
	if err != nil {
		return out
	}
	for _, line := range strings.Split(string(data), "\n") {
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		if local, _, ok := strings.Cut(line, ":"); ok {
			out[local] = true
		}
	}
	return out
}

// suspended reads whether a domain's account is suspended (its owner file).
func (m *Manager) suspended(domain string) bool {
	data, err := os.ReadFile(m.eximPath("owners", domain))
	if err != nil {
		return true
	}
	return strings.Contains(string(data), "\nsuspended: yes") || strings.HasPrefix(string(data), "suspended: yes")
}

// splitAddress checks one of an account's own addresses.
func splitAddress(address string) (local, domain string, err error) {
	local, domain, ok := strings.Cut(address, "@")
	if !ok {
		return "", "", badArgs("%q is not an address", address)
	}
	if err := checkLocal(local); err != nil {
		return "", "", err
	}
	if err := checkDomain(domain); err != nil {
		return "", "", err
	}
	return local, domain, nil
}

// randomPassword is 32 characters from 192 random bits.
func randomPassword() (string, error) {
	raw := make([]byte, 24)
	if _, err := rand.Read(raw); err != nil {
		return "", err
	}
	return base64.RawURLEncoding.EncodeToString(raw), nil
}

// ssha256 is Dovecot's salted SHA-256 of a password: enough for a random one
// that lives hours (bcrypt's cost is for passwords people choose).
func ssha256(password string) string {
	salt := make([]byte, 8)
	_, _ = rand.Read(salt)
	sum := sha256.Sum256(append([]byte(password), salt...))
	return "{SSHA256.b64}" + base64.StdEncoding.EncodeToString(append(sum[:], salt...))
}
