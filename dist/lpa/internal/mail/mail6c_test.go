package mail

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/pem"
	"errors"
	"math/big"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
	"time"

	"lpa/internal/protocol"
)

func code(err error) string {
	var pe *protocol.Error
	if errors.As(err, &pe) {
		return pe.Code
	}
	if err != nil {
		return "error: " + err.Error()
	}
	return ""
}

func TestSignOnGivesAPasswordDovecotAccepts(t *testing.T) {
	f := newFixture(t)
	now := time.Date(2026, 11, 17, 12, 0, 0, 0, time.UTC)
	f.m.Now = func() time.Time { return now }
	if _, err := f.m.Apply(context.Background(), shop()); err != nil {
		t.Fatal(err)
	}
	// Every domain has the file, empty: a missing one is a server failure to Dovecot.
	if got := f.read(t, f.m.ssoPath("example.com")); strings.Contains(got, ":") {
		t.Fatalf("sso file before any sign-on:\n%s", got)
	}

	res, err := f.m.SSO(context.Background(), SSOArgs{Username: "shop1", Address: "info@example.com"})
	if err != nil {
		t.Fatal(err)
	}
	if res.User != "info@example.com" || len(res.Password) != 32 || res.Expires != now.Add(8*time.Hour).Unix() {
		t.Fatalf("%+v", res)
	}
	line := strings.TrimSpace(strings.Split(f.read(t, f.m.ssoPath("example.com")), "\n")[1])
	hash, ok := strings.CutPrefix(line, "info:{SSHA256.b64}")
	if !ok {
		t.Fatalf("line: %q", line)
	}
	raw, _ := base64.StdEncoding.DecodeString(hash)
	sum := sha256.Sum256(append([]byte(res.Password), raw[32:]...))
	if string(sum[:]) != string(raw[:32]) {
		t.Fatal("the hash does not match the password")
	}
	if strings.Contains(f.read(t, f.m.ssoPath("example.com")), res.Password) {
		t.Fatal("the password itself is in Dovecot's file")
	}
	if st, _ := os.Stat(f.m.ssoKeepPath("example.com")); runtime.GOOS != "windows" && st.Mode().Perm() != 0o600 {
		t.Fatalf("keep file mode %v", st.Mode())
	}

	// A second sign-on while it is good: the same password, a little longer.
	now = now.Add(2 * time.Hour)
	again, err := f.m.SSO(context.Background(), SSOArgs{Username: "shop1", Address: "info@example.com"})
	if err != nil || again.Password != res.Password || again.Expires != now.Add(8*time.Hour).Unix() {
		t.Fatalf("%+v %v", again, err)
	}
	// Apply keeps it, and the file does not change.
	before := f.read(t, f.m.ssoPath("example.com"))
	if _, err := f.m.Apply(context.Background(), shop()); err != nil {
		t.Fatal(err)
	}
	if f.read(t, f.m.ssoPath("example.com")) != before {
		t.Fatal("Apply rewrote a good sign-on")
	}

	// Close to expiry: a new one.
	now = now.Add(7*time.Hour + 30*time.Minute)
	fresh, err := f.m.SSO(context.Background(), SSOArgs{Username: "shop1", Address: "info@example.com"})
	if err != nil || fresh.Password == res.Password {
		t.Fatalf("%+v %v", fresh, err)
	}

	// Expired: gone.
	now = now.Add(9 * time.Hour)
	if got, err := f.m.SSOExpire(context.Background()); err != nil || got["removed"] != 1 {
		t.Fatalf("%v %v", got, err)
	}
	if strings.Contains(f.read(t, f.m.ssoPath("example.com")), "info:") || exists(f.m.ssoKeepPath("example.com")) {
		t.Fatal("an expired sign-on stays")
	}
}

func TestSignOnRefusals(t *testing.T) {
	f := newFixture(t)
	if _, err := f.m.Apply(context.Background(), shop()); err != nil {
		t.Fatal(err)
	}
	other := ApplyArgs{Username: "other1", Domains: []Domain{{Name: "other.test", Local: true}}}
	if _, err := f.m.Apply(context.Background(), other); err != nil {
		t.Fatal(err)
	}
	for name, a := range map[string]SSOArgs{
		"not a mailbox":           {Username: "shop1", Address: "sales@example.com"},
		"another account's":       {Username: "other1", Address: "info@example.com"},
		"a domain nobody has":     {Username: "shop1", Address: "info@nowhere.test"},
		"a broken address":        {Username: "shop1", Address: "info@@example.com"},
		"a path in the domain":    {Username: "shop1", Address: "info@../example.com"},
		"too long a life":         {Username: "shop1", Address: "info@example.com", Hours: 25},
		"an account name it isnt": {Username: "Shop1", Address: "info@example.com"},
	} {
		if _, err := f.m.SSO(context.Background(), a); err == nil {
			t.Errorf("%s: given", name)
		}
	}

	// Suspension: no new one, and the old one goes.
	if _, err := f.m.SSO(context.Background(), SSOArgs{Username: "shop1", Address: "info@example.com"}); err != nil {
		t.Fatal(err)
	}
	s := shop()
	s.Suspended = true
	if _, err := f.m.Apply(context.Background(), s); err != nil {
		t.Fatal(err)
	}
	if strings.Contains(f.read(t, f.m.ssoPath("example.com")), "info:") {
		t.Fatal("a suspended account's sign-on stays")
	}
	if _, err := f.m.SSO(context.Background(), SSOArgs{Username: "shop1", Address: "info@example.com"}); code(err) != CodeSuspended {
		t.Fatalf("suspended: %v", err)
	}

	// A mailbox that goes takes its sign-on with it; revoking empties the rest.
	if _, err := f.m.Apply(context.Background(), shop()); err != nil {
		t.Fatal(err)
	}
	for _, addr := range []string{"info@example.com", "bob.smith@example.com"} {
		if _, err := f.m.SSO(context.Background(), SSOArgs{Username: "shop1", Address: addr}); err != nil {
			t.Fatal(err)
		}
	}
	s = shop()
	s.Domains[0].Mailboxes = s.Domains[0].Mailboxes[:1]
	if _, err := f.m.Apply(context.Background(), s); err != nil {
		t.Fatal(err)
	}
	if got := f.read(t, f.m.ssoPath("example.com")); strings.Contains(got, "bob.smith:") || !strings.Contains(got, "info:") {
		t.Fatalf("after removing a mailbox:\n%s", got)
	}
	if got, err := f.m.SSORevoke(context.Background(), SSORevokeArgs{Username: "shop1"}); err != nil || got["removed"] != 1 {
		t.Fatalf("%v %v", got, err)
	}
	if strings.Contains(f.read(t, f.m.ssoPath("example.com")), "info:") {
		t.Fatal("revoked, still there")
	}
	// Removing the domain removes both files.
	if _, err := f.m.Apply(context.Background(), ApplyArgs{Username: "shop1"}); err != nil {
		t.Fatal(err)
	}
	if exists(f.m.ssoPath("example.com")) {
		t.Fatal("a removed domain's sign-on file stays")
	}
}

// selfSigned writes a certificate for names, valid from notBefore for a
// year, with its key, and returns their paths.
func selfSigned(t *testing.T, dir string, notBefore time.Time, names ...string) (string, string) {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	tmpl := &x509.Certificate{
		SerialNumber: big.NewInt(time.Now().UnixNano()), Subject: pkix.Name{CommonName: names[0]}, DNSNames: names,
		NotBefore: notBefore, NotAfter: notBefore.Add(365 * 24 * time.Hour),
	}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, tmpl, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	keyDER, _ := x509.MarshalECPrivateKey(key)
	_ = os.MkdirAll(dir, 0o700)
	crt, kp := filepath.Join(dir, names[0]+".crt"), filepath.Join(dir, names[0]+".key")
	if err := os.WriteFile(crt, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(kp, pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: keyDER}), 0o600); err != nil {
		t.Fatal(err)
	}
	return crt, kp
}

func TestEachMailDomainServesItsOwnCertificate(t *testing.T) {
	f := newFixture(t)
	store := filepath.Join(t.TempDir(), "store")
	now := time.Now()
	good, goodKey := selfSigned(t, store, now.Add(-time.Hour), "example.com", "www.example.com", "mail.example.com")
	bare, bareKey := selfSigned(t, store, now.Add(-time.Hour), "second.test", "www.second.test")
	certs := map[string][2]string{"aaaaaaaaaaaaaaaa": {good, goodKey}, "bbbbbbbbbbbbbbbb": {bare, bareKey}}
	f.m.Certs = func(user, id string) (string, string, error) {
		if user != "shop1" {
			t.Errorf("asked for %s's certificate", user)
		}
		p, ok := certs[id]
		if !ok {
			return "", "", os.ErrNotExist
		}
		return p[0], p[1], nil
	}
	a := shop()
	a.Domains[0].Cert = "aaaaaaaaaaaaaaaa"
	// A certificate without mail.<domain> serves nothing; one missing from
	// the store is skipped, not a failure.
	a.Domains = append(a.Domains, Domain{Name: "second.test", Local: true, Cert: "bbbbbbbbbbbbbbbb"}, Domain{Name: "third.test", Local: true, Cert: "cccccccccccccccc"})
	f.run.calls = nil
	if _, err := f.m.Apply(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	dir := filepath.Join(f.m.TLSDir, "sni", "mail.example.com")
	if f.read(t, dir, "crt") != f.read(t, good) || f.read(t, dir, "key") != f.read(t, goodKey) {
		t.Fatal("the copy is not the certificate")
	}
	for _, name := range []string{"mail.second.test", "mail.third.test"} {
		if exists(filepath.Join(f.m.TLSDir, "sni", name)) {
			t.Errorf("%s is served", name)
		}
	}
	conf := f.read(t, f.m.DovecotDir, "sni.conf")
	want := "local_name mail.example.com {\n  ssl_cert = <" + filepath.ToSlash(filepath.Join(dir, "crt")) + "\n  ssl_key = <" + filepath.ToSlash(filepath.Join(dir, "key")) + "\n}\n"
	if !strings.Contains(conf, want) || strings.Count(conf, "local_name") != 1 {
		t.Fatalf("sni.conf:\n%s", conf)
	}
	if !strings.Contains(strings.Join(f.run.calls, "\n"), "systemctl reload dovecot") {
		t.Fatalf("Dovecot was not reloaded: %v", f.run.calls)
	}

	// The same again: no reload.
	f.run.calls = nil
	if _, err := f.m.Apply(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	if strings.Contains(strings.Join(f.run.calls, "\n"), "reload dovecot") {
		t.Fatalf("reloaded for nothing: %v", f.run.calls)
	}

	// Renewed (a new certificate, the same list): reloaded, so Dovecot reads it.
	renewed, renewedKey := selfSigned(t, filepath.Join(store, "new"), now.Add(-time.Minute), "example.com", "mail.example.com")
	certs["dddddddddddddddd"] = [2]string{renewed, renewedKey}
	a.Domains[0].Cert = "dddddddddddddddd"
	f.run.calls = nil
	if _, err := f.m.Apply(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	if f.read(t, dir, "crt") != f.read(t, renewed) || !strings.Contains(strings.Join(f.run.calls, "\n"), "systemctl reload dovecot") {
		t.Fatalf("renewal: %v", f.run.calls)
	}

	// Not valid yet: not served (the hostname's is).
	future, futureKey := selfSigned(t, filepath.Join(store, "future"), now.Add(48*time.Hour), "example.com", "mail.example.com")
	certs["eeeeeeeeeeeeeeee"] = [2]string{future, futureKey}
	a.Domains[0].Cert = "eeeeeeeeeeeeeeee"
	if _, err := f.m.Apply(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	if exists(dir) || strings.Contains(f.read(t, f.m.DovecotDir, "sni.conf"), "local_name") {
		t.Fatal("a certificate not valid yet is served")
	}

	// A bad id is refused before anything happens.
	a.Domains[0].Cert = "../../etc/passwd"
	if _, err := f.m.Apply(context.Background(), a); code(err) != "bad_args" {
		t.Fatalf("bad id: %v", err)
	}

	// The domain goes: its copy goes.
	a.Domains[0].Cert = "dddddddddddddddd"
	if _, err := f.m.Apply(context.Background(), a); err != nil || !exists(dir) {
		t.Fatalf("back: %v", err)
	}
	a.Domains = a.Domains[1:]
	if _, err := f.m.Apply(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	if exists(dir) {
		t.Fatal("a removed domain's certificate stays")
	}
}

func TestRemovingAnAccountTakesItsCertificatesOutOfDovecot(t *testing.T) {
	f := newFixture(t)
	store := filepath.Join(t.TempDir(), "store")
	crt, key := selfSigned(t, store, time.Now().Add(-time.Hour), "example.com", "mail.example.com")
	f.m.Certs = func(string, string) (string, string, error) { return crt, key, nil }
	a := shop()
	a.Domains[0].Cert = "aaaaaaaaaaaaaaaa"
	if _, err := f.m.Apply(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(f.read(t, f.m.DovecotDir, "sni.conf"), "local_name mail.example.com") {
		t.Fatal("not served")
	}
	f.run.calls = nil
	if err := f.m.RemoveFor(context.Background(), "shop1"); err != nil {
		t.Fatal(err)
	}
	// Dovecot would not start again naming files that are gone.
	if strings.Contains(f.read(t, f.m.DovecotDir, "sni.conf"), "local_name") || exists(filepath.Join(f.m.TLSDir, "sni", "mail.example.com")) {
		t.Fatal("the removed account's certificate is still named")
	}
	if !strings.Contains(strings.Join(f.run.calls, "\n"), "systemctl reload dovecot") {
		t.Fatalf("Dovecot was not reloaded: %v", f.run.calls)
	}
}
