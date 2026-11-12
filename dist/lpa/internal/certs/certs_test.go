package certs

import (
	"context"
	"crypto"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"errors"
	"io"
	"log/slog"
	"math/big"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"lpa/internal/protocol"
)

var now = time.Date(2026, 11, 12, 12, 0, 0, 0, time.UTC)

// testCA is a root and an intermediate that sign leaves.
type testCA struct {
	root, mid       *x509.Certificate
	rootKey, midKey *ecdsa.PrivateKey
}

func newCA(t *testing.T) *testCA {
	t.Helper()
	ca := &testCA{}
	ca.rootKey, _ = ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	ca.midKey, _ = ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	tpl := &x509.Certificate{
		SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "Test Root"},
		NotBefore: now.Add(-time.Hour), NotAfter: now.Add(10 * 365 * 24 * time.Hour),
		IsCA: true, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign,
	}
	der, _ := x509.CreateCertificate(rand.Reader, tpl, tpl, &ca.rootKey.PublicKey, ca.rootKey)
	ca.root, _ = x509.ParseCertificate(der)
	mid := &x509.Certificate{
		SerialNumber: big.NewInt(2), Subject: pkix.Name{CommonName: "Test Intermediate", Organization: []string{"LPanel Test"}},
		NotBefore: now.Add(-time.Hour), NotAfter: now.Add(5 * 365 * 24 * time.Hour),
		IsCA: true, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign,
	}
	der, _ = x509.CreateCertificate(rand.Reader, mid, ca.root, &ca.midKey.PublicKey, ca.rootKey)
	ca.mid, _ = x509.ParseCertificate(der)
	return ca
}

// leaf signs a certificate for pub and names, valid from nb to na.
func (ca *testCA) leaf(t *testing.T, pub crypto.PublicKey, names []string, nb, na time.Time) [][]byte {
	t.Helper()
	serial, _ := rand.Int(rand.Reader, big.NewInt(1<<62))
	tpl := &x509.Certificate{SerialNumber: serial, Subject: pkix.Name{CommonName: names[0]}, DNSNames: names, NotBefore: nb, NotAfter: na}
	der, err := x509.CreateCertificate(rand.Reader, tpl, ca.mid, pub, ca.midKey)
	if err != nil {
		t.Fatal(err)
	}
	return [][]byte{der, ca.mid.Raw}
}

func newKey() *ecdsa.PrivateKey {
	k, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	return k
}

func codeOf(err error) string {
	var pe *protocol.Error
	if errors.As(err, &pe) {
		return pe.Code
	}
	return ""
}

func TestCheck(t *testing.T) {
	ca := newCA(t)
	key := newKey()
	chain := ca.leaf(t, &key.PublicKey, []string{"shop.test", "www.shop.test"}, now.Add(-time.Hour), now.Add(90*24*time.Hour))

	p, err := Check(key, chain, now)
	if err != nil {
		t.Fatal(err)
	}
	if len(p.ID) != 16 || p.Issuer != "LPanel Test Test Intermediate" || p.SelfSigned || p.KeyType != "ECDSA P-256" {
		t.Errorf("info = %+v", p.Info)
	}
	if strings.Join(p.Names, ",") != "shop.test,www.shop.test" {
		t.Errorf("names = %v", p.Names)
	}
	if !p.Covers("www.shop.test") || p.Covers("mail.shop.test") {
		t.Error("Covers is wrong")
	}

	if _, err := Check(newKey(), chain, now); codeOf(err) != CodeCertInvalid {
		t.Errorf("another key: %v", err)
	}
	if _, err := Check(key, chain, now.Add(91*24*time.Hour)); err == nil || !strings.Contains(err.Error(), "expired") {
		t.Errorf("expired: %v", err)
	}
	if _, err := Check(key, [][]byte{chain[0], ca.root.Raw}, now); err == nil || !strings.Contains(err.Error(), "in order") {
		t.Errorf("chain out of order: %v", err)
	}

	// Self-signed: installed, and said so.
	tpl := &x509.Certificate{SerialNumber: big.NewInt(9), Subject: pkix.Name{CommonName: "self.test"}, DNSNames: []string{"self.test", "*.self.test"}, NotBefore: now.Add(-time.Hour), NotAfter: now.Add(time.Hour)}
	der, _ := x509.CreateCertificate(rand.Reader, tpl, tpl, &key.PublicKey, key)
	p, err = Check(key, [][]byte{der}, now)
	if err != nil || !p.SelfSigned || !p.Covers("a.self.test") || p.Covers("a.b.self.test") {
		t.Errorf("self-signed: %+v %v", p.Info, err)
	}
}

func TestParse(t *testing.T) {
	key := newKey()
	text, _ := KeyPEM(key)
	if k, err := ParseKey(string(text)); err != nil || !k.Public().(*ecdsa.PublicKey).Equal(&key.PublicKey) {
		t.Errorf("PKCS#8: %v", err)
	}
	der, _ := x509.MarshalECPrivateKey(key)
	if _, err := ParseKey(string(pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: der}))); err != nil {
		t.Errorf("SEC 1: %v", err)
	}
	if _, err := ParseKey("-----BEGIN ENCRYPTED PRIVATE KEY-----\nAAAA\n-----END ENCRYPTED PRIVATE KEY-----\n"); err == nil || !strings.Contains(err.Error(), "passphrase") {
		t.Errorf("encrypted: %v", err)
	}
	if _, err := ParseChain("hello"); codeOf(err) != CodeCertInvalid {
		t.Errorf("no PEM: %v", err)
	}
}

func TestStore(t *testing.T) {
	ca := newCA(t)
	clock := now
	s := &Store{Dir: filepath.Join(t.TempDir(), "sites"), Now: func() time.Time { return clock }}
	key := newKey()
	p, err := Check(key, ca.leaf(t, &key.PublicKey, []string{"shop.test"}, now.Add(-time.Hour), now.Add(time.Hour*24*90)), now)
	if err != nil {
		t.Fatal(err)
	}
	if err := s.Save("shop1", p); err != nil {
		t.Fatal(err)
	}
	crt, keyPath, err := s.Files("shop1", p.ID)
	if err != nil {
		t.Fatal(err)
	}
	data, _ := os.ReadFile(crt)
	if strings.Count(string(data), "BEGIN CERTIFICATE") != 2 {
		t.Errorf("the chain file holds %q", data)
	}
	if _, err := os.Stat(keyPath); err != nil {
		t.Fatal(err)
	}
	if _, _, err := s.Files("shop1", "0123456789abcdef"); err == nil {
		t.Error("a certificate that is not there was found")
	}
	if _, _, err := s.Files("shop1", "../../etc/passwd"); err == nil {
		t.Error("a path was accepted as an id")
	}
	if _, _, err := s.Files("../x", p.ID); err == nil {
		t.Error("a path was accepted as a username")
	}

	// Pruned only when unnamed and a day old.
	s.Prune("shop1", map[string]bool{})
	if _, _, err := s.Files("shop1", p.ID); err != nil {
		t.Error("a fresh certificate was pruned")
	}
	clock = now.Add(48 * time.Hour)
	os.Chtimes(crt, now, now)
	os.Chtimes(keyPath, now, now)
	s.Prune("shop1", map[string]bool{p.ID: true})
	if _, _, err := s.Files("shop1", p.ID); err != nil {
		t.Error("a certificate in use was pruned")
	}
	s.Prune("shop1", map[string]bool{})
	if _, _, err := s.Files("shop1", p.ID); err == nil {
		t.Error("an old certificate nobody names was kept")
	}

	// A request's key is found again from the certificate's public key.
	rk := newKey()
	if _, err := s.SaveRequestKey("shop1", rk); err != nil {
		t.Fatal(err)
	}
	got, err := s.RequestKey("shop1", &rk.PublicKey)
	if err != nil || !got.Public().(*ecdsa.PublicKey).Equal(&rk.PublicKey) {
		t.Errorf("request key: %v", err)
	}
	if _, err := s.RequestKey("shop1", &newKey().PublicKey); codeOf(err) != CodeNoKey {
		t.Errorf("unknown request key: %v", err)
	}
	if err := s.RemoveUser("shop1"); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(s.Dir, "shop1")); !os.IsNotExist(err) {
		t.Error("the account's folder is still there")
	}
}

// fakeAuthority signs every request with a test CA, after checking that
// the answer to each check is where the web server would serve it.
type fakeAuthority struct {
	t     *testing.T
	ca    *testCA
	dir   string
	fail  map[string]error // authority → its refusal
	asked []string
	names [][]string
}

func (f *fakeAuthority) Issue(_ context.Context, ca string, _ *EAB, names []string, csrDER []byte, present func(token, keyAuth string) error) ([][]byte, error) {
	f.asked = append(f.asked, ca)
	f.names = append(f.names, names)
	if err := f.fail[ca]; err != nil {
		return nil, err
	}
	token := "tok_" + strings.Repeat("A", 20)
	if err := present(token, token+".thumb"); err != nil {
		return nil, err
	}
	if got, _ := os.ReadFile(filepath.Join(f.dir, token)); string(got) != token+".thumb" {
		f.t.Errorf("the check's answer is %q", got)
	}
	csr, err := x509.ParseCertificateRequest(csrDER)
	if err != nil {
		return nil, err
	}
	return f.ca.leaf(f.t, csr.PublicKey, csr.DNSNames, now.Add(-time.Minute), now.Add(90*24*time.Hour)), nil
}

type fakeRun struct {
	calls []string
	fail  map[string]bool
}

func (f *fakeRun) Run(_ context.Context, tool string, args ...string) (int, string, error) {
	call := tool + " " + strings.Join(args, " ")
	f.calls = append(f.calls, call)
	if f.fail[call] {
		return 1, "refused", nil
	}
	return 0, "", nil
}

func newManager(t *testing.T) (*Manager, *fakeAuthority, *fakeRun) {
	t.Helper()
	root := t.TempDir()
	auth := &fakeAuthority{t: t, ca: newCA(t), dir: filepath.Join(root, "acme"), fail: map[string]error{}}
	run := &fakeRun{fail: map[string]bool{}}
	m := &Manager{
		Store:      &Store{Dir: filepath.Join(root, "pki", "sites")},
		ACME:       auth,
		Challenges: auth.dir,
		HostCert:   filepath.Join(root, "tls", "panel.crt"),
		HostKey:    filepath.Join(root, "tls", "panel.key"),
		PanelNginx: filepath.Join(root, "nginx.conf"),
		HostUnits:  []string{"lpanel-nginx"},
		Run:        run,
		Log:        slog.New(slog.NewTextHandler(io.Discard, nil)),
		Owner: func(name string) (int, int, string, error) {
			if name != "shop1" {
				return 0, 0, "", protocol.Fail("not_ours", name+" was not made by LPanel")
			}
			return 1001, 1001, "/home/shop1", nil
		},
		Now:      func() time.Time { return now },
		ProbeFor: 300 * time.Millisecond,
		// shop.test and www.shop.test point here; elsewhere.test points at
		// another server; nowhere.test has no address.
		Resolve: func(_ context.Context, host string) ([]string, error) {
			switch host {
			case "nowhere.test":
				return nil, errors.New("no such host")
			case "elsewhere.test":
				return []string{"203.0.113.9"}, nil
			case "dark.test":
				return []string{"192.0.2.1"}, nil
			}
			return []string{"192.0.2.1"}, nil
		},
		Fetch: func(_ context.Context, url string) (int, []byte, error) {
			if strings.Contains(url, "//elsewhere.test/") {
				return 404, []byte("not here"), nil
			}
			if strings.Contains(url, "//dark.test/") {
				return 0, nil, errors.New("connection refused")
			}
			token := url[strings.LastIndex(url, "/")+1:]
			body, err := os.ReadFile(filepath.Join(auth.dir, token))
			if err != nil {
				return 404, nil, nil
			}
			return 200, body, nil
		},
		LocalIPs: func() []string { return []string{"127.0.0.1", "192.0.2.1"} },
	}
	m.Defaults()
	os.WriteFile(m.PanelNginx, []byte("# panel\n"), 0o644)
	return m, auth, run
}

func TestIssue(t *testing.T) {
	m, auth, _ := newManager(t)
	res, err := m.Issue(context.Background(), IssueArgs{
		Username: "shop1", Names: []string{"shop.test", "www.shop.test", "nowhere.test", "elsewhere.test", "dark.test"},
		Authorities: []string{LetsEncrypt, ZeroSSL},
	})
	if err != nil {
		t.Fatal(err)
	}
	if !res.Issued || res.Authority != LetsEncrypt || res.Cert == nil {
		t.Fatalf("result = %+v", res)
	}
	// dark.test did not answer, but resolves to this server: it counts.
	if strings.Join(res.Cert.Names, ",") != "dark.test,shop.test,www.shop.test" {
		t.Errorf("names = %v", res.Cert.Names)
	}
	if len(res.Excluded) != 2 || res.Excluded[0].Name != "nowhere.test" || !strings.Contains(res.Excluded[1].Reason, "another server answered") {
		t.Errorf("excluded = %+v", res.Excluded)
	}
	if _, _, err := m.Store.Files("shop1", res.Cert.ID); err != nil {
		t.Error(err)
	}
	// Every check's answer is gone afterwards.
	if left, _ := os.ReadDir(auth.dir); len(left) != 0 {
		t.Errorf("%d files left in the challenge folder", len(left))
	}
}

func TestIssueFallsBack(t *testing.T) {
	m, auth, _ := newManager(t)
	auth.fail[LetsEncrypt] = &AuthorityError{Msg: "its rate limit was reached"}
	res, err := m.Issue(context.Background(), IssueArgs{Username: "shop1", Names: []string{"shop.test"}, Authorities: []string{LetsEncrypt, ZeroSSL}})
	if err != nil || !res.Issued || res.Authority != ZeroSSL {
		t.Fatalf("result = %+v, %v", res, err)
	}
	if len(res.Attempts) != 1 || !strings.Contains(res.Attempts[0].Error, "rate limit") {
		t.Errorf("attempts = %+v", res.Attempts)
	}

	// A failed check would fail at the other authority too: not retried.
	m2, auth2, _ := newManager(t)
	auth2.fail[LetsEncrypt] = &AuthorityError{Validation: true, Msg: "shop.test did not pass its check"}
	res, err = m2.Issue(context.Background(), IssueArgs{Username: "shop1", Names: []string{"shop.test"}, Authorities: []string{LetsEncrypt, ZeroSSL}})
	if err != nil || res.Issued || strings.Join(auth2.asked, ",") != LetsEncrypt || !strings.Contains(res.Error, "did not pass") {
		t.Fatalf("result = %+v, asked %v, %v", res, auth2.asked, err)
	}
}

func TestIssueRefusals(t *testing.T) {
	m, auth, _ := newManager(t)
	// The site's own name must point here; no authority is asked.
	res, err := m.Issue(context.Background(), IssueArgs{Username: "shop1", Names: []string{"nowhere.test", "shop.test"}, Authorities: []string{LetsEncrypt}})
	if err != nil || res.Issued || len(auth.asked) != 0 || !strings.Contains(res.Error, "nowhere.test: it has no DNS address") {
		t.Fatalf("result = %+v, %v", res, err)
	}
	for _, a := range []IssueArgs{
		{Username: "shop1", Names: []string{"*.shop.test"}, Authorities: []string{LetsEncrypt}},
		{Username: "shop1", Names: []string{"shop.test"}, Authorities: []string{"evil"}},
		{Username: "shop1", Names: []string{}, Authorities: []string{LetsEncrypt}},
		{Username: "shop1", Names: []string{"shop.test"}, Authorities: []string{ZeroSSL}, EAB: &EAB{KID: "a b"}},
	} {
		if _, err := m.Issue(context.Background(), a); codeOf(err) != protocol.CodeBadArgs {
			t.Errorf("%+v: %v", a, err)
		}
	}
	if _, err := m.Issue(context.Background(), IssueArgs{Username: "root", Names: []string{"shop.test"}, Authorities: []string{LetsEncrypt}}); codeOf(err) != "not_ours" {
		t.Errorf("another user: %v", err)
	}
}

func TestCSRAndInstall(t *testing.T) {
	m, auth, _ := newManager(t)
	res, err := m.CSR(context.Background(), CSRArgs{Username: "shop1", Names: []string{"shop.test", "www.shop.test"}, KeyType: "rsa", Country: "BD", Organization: "Shop & Co."})
	if err != nil {
		t.Fatal(err)
	}
	block, _ := pem.Decode([]byte(res.CSR))
	csr, err := x509.ParseCertificateRequest(block.Bytes)
	if err != nil || csr.Subject.Country[0] != "BD" || len(csr.DNSNames) != 2 {
		t.Fatalf("csr = %+v, %v", csr, err)
	}
	// The certificate comes back without its key: the request's is used.
	chain := ChainPEM(auth.ca.leaf(t, csr.PublicKey, csr.DNSNames, now.Add(-time.Hour), now.Add(365*24*time.Hour)))
	info, err := m.Install(context.Background(), InstallArgs{Username: "shop1", Chain: string(chain)})
	if err != nil || info.KeyType != "RSA 2048" {
		t.Fatalf("install = %+v, %v", info, err)
	}
	if _, _, err := m.Store.Files("shop1", info.ID); err != nil {
		t.Error(err)
	}
	if _, err := m.CSR(context.Background(), CSRArgs{Username: "shop1", Names: []string{"shop.test"}, Country: "Bangladesh"}); codeOf(err) != protocol.CodeBadArgs {
		t.Errorf("long country: %v", err)
	}
	if _, err := m.CSR(context.Background(), CSRArgs{Username: "shop1", Names: []string{"shop.test"}, Organization: "x\"\nSSLEngine off"}); codeOf(err) != protocol.CodeBadArgs {
		t.Errorf("quote in organization: %v", err)
	}

	// Another key's certificate, with no key: refused.
	other := ChainPEM(auth.ca.leaf(t, &newKey().PublicKey, []string{"shop.test"}, now.Add(-time.Hour), now.Add(time.Hour)))
	if _, err := m.Install(context.Background(), InstallArgs{Username: "shop1", Chain: string(other)}); codeOf(err) != CodeNoKey {
		t.Errorf("no key: %v", err)
	}
}

func TestHostInstall(t *testing.T) {
	m, auth, run := newManager(t)
	oldKey := newKey()
	oldPair, _ := Check(oldKey, auth.ca.leaf(t, &oldKey.PublicKey, []string{"host.test"}, now.Add(-time.Hour), now.Add(time.Hour)), now)
	os.MkdirAll(filepath.Dir(m.HostCert), 0o700)
	keyText, _ := KeyPEM(oldKey)
	os.WriteFile(m.HostKey, keyText, 0o600)
	os.WriteFile(m.HostCert, ChainPEM(oldPair.Chain), 0o644)

	served := oldPair.Serial
	m.ProbeHost = func(_ context.Context, name, serial string) error {
		if served != serial {
			return errors.New("old certificate")
		}
		return nil
	}
	key := newKey()
	newChain := ChainPEM(auth.ca.leaf(t, &key.PublicKey, []string{"host.test"}, now.Add(-time.Hour), now.Add(90*24*time.Hour)))
	newKeyText, _ := KeyPEM(key)

	// The panel's nginx never serves it: the old certificate comes back.
	_, err := m.HostInstall(context.Background(), HostInstallArgs{Key: string(newKeyText), Chain: string(newChain)})
	if codeOf(err) != CodeHostTLS {
		t.Fatalf("err = %v", err)
	}
	if got, _ := os.ReadFile(m.HostCert); string(got) != string(ChainPEM(oldPair.Chain)) {
		t.Error("the old certificate is not back")
	}

	// It does: installed.
	run.calls = nil
	m.ProbeHost = func(context.Context, string, string) error { return nil }
	info, err := m.HostInstall(context.Background(), HostInstallArgs{Key: string(newKeyText), Chain: string(newChain)})
	if err != nil {
		t.Fatal(err)
	}
	if strings.Join(run.calls, "|") != "nginx -t -q -c "+m.PanelNginx+"|systemctl is-active --quiet lpanel-nginx|systemctl reload lpanel-nginx" {
		t.Errorf("calls = %v", run.calls)
	}
	hi, _ := m.HostInfo(context.Background())
	if !hi.Installed || hi.Cert == nil || hi.Cert.ID != info.ID {
		t.Errorf("host info = %+v", hi)
	}

	// nginx refuses it: nothing reloaded, the certificate before is back.
	run.calls = nil
	run.fail["nginx -t -q -c "+m.PanelNginx] = true
	key3 := newKey()
	k3, _ := KeyPEM(key3)
	_, err = m.HostInstall(context.Background(), HostInstallArgs{Key: string(k3), Chain: string(ChainPEM(auth.ca.leaf(t, &key3.PublicKey, []string{"host.test"}, now.Add(-time.Hour), now.Add(time.Hour))))})
	if codeOf(err) != CodeHostTLS {
		t.Fatalf("err = %v", err)
	}
	if got, _ := os.ReadFile(m.HostCert); string(got) != string(newChain) {
		t.Error("the certificate before is not back")
	}
}
