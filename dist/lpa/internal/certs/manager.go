package certs

import (
	"context"
	"crypto"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/rsa"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/hex"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"

	"lpa/internal/protocol"
	"lpa/internal/sysexec"
)

// tokenRule: an ACME token is base64url (RFC 8555 §8.3), so it is a safe
// file name.
var tokenRule = regexp.MustCompile(`^[A-Za-z0-9_-]{16,128}$`)

// Authority issues certificates (ACME; tests use a fake).
type Authority interface {
	Issue(ctx context.Context, ca string, eab *EAB, names []string, csr []byte, present func(token, keyAuth string) error) ([][]byte, error)
}

// Manager carries out the certificate operations.
type Manager struct {
	Store *Store
	ACME  Authority
	// Challenges is the folder every site serves at
	// /.well-known/acme-challenge/ on port 80 (/var/www/lpanel-acme).
	Challenges string
	// HostCert and HostKey are the server's own certificate: the panel's
	// ports, and the default HTTPS site (/etc/lpanel/tls/panel.crt, .key).
	HostCert, HostKey string
	// PanelNginx is the panel's nginx configuration, tested before the
	// hostname certificate is reloaded.
	PanelNginx string
	// HostUnits are reloaded, when running, after the hostname certificate
	// changes: lpanel-nginx, and httpd when Apache serves HTTPS itself.
	HostUnits []string
	Run       sysexec.Runner
	Log       *slog.Logger
	// Owner describes an LPanel account and refuses anything else.
	Owner func(name string) (uid, gid int, home string, err error)

	// What the checks before ordering use; tests replace them.
	Resolve   func(ctx context.Context, host string) ([]string, error)
	Fetch     func(ctx context.Context, url string) (int, []byte, error)
	LocalIPs  func() []string
	ProbeHost func(ctx context.Context, serverName, serial string) error
	Now       func() time.Time
	// ProbeFor is how long the panel's server has to serve a new hostname
	// certificate.
	ProbeFor time.Duration

	hostMu sync.Mutex
}

// Defaults fills in what tests leave out.
func (m *Manager) Defaults() {
	if m.Challenges == "" {
		m.Challenges = "/var/www/lpanel-acme"
	}
	if m.HostCert == "" {
		m.HostCert = "/etc/lpanel/tls/panel.crt"
	}
	if m.HostKey == "" {
		m.HostKey = "/etc/lpanel/tls/panel.key"
	}
	if m.PanelNginx == "" {
		m.PanelNginx = "/etc/lpanel/nginx/nginx.conf"
	}
	if m.Now == nil {
		m.Now = time.Now
	}
	if m.Store != nil && m.Store.Now == nil {
		m.Store.Now = m.Now
	}
	if m.ProbeFor == 0 {
		m.ProbeFor = 10 * time.Second
	}
	if m.Resolve == nil {
		m.Resolve = net.DefaultResolver.LookupHost
	}
	if m.Fetch == nil {
		m.Fetch = fetch
	}
	if m.LocalIPs == nil {
		m.LocalIPs = localIPs
	}
	if m.ProbeHost == nil {
		m.ProbeHost = probeHost
	}
	if m.Log == nil {
		m.Log = slog.New(slog.NewTextHandler(io.Discard, nil))
	}
}

// IssueArgs are ssl.issue's arguments.
type IssueArgs struct {
	Username string   `json:"username"`
	Names    []string `json:"names"`
	// Authorities are tried in order: "letsencrypt", "zerossl".
	Authorities []string `json:"authorities"`
	EAB         *EAB     `json:"zerossl_eab"`
}

// HostIssueArgs are ssl.host_issue's.
type HostIssueArgs struct {
	Names       []string `json:"names"`
	Authorities []string `json:"authorities"`
	EAB         *EAB     `json:"zerossl_eab"`
}

// Excluded is a name left out of a certificate, and why.
type Excluded struct {
	Name   string `json:"name"`
	Reason string `json:"reason"`
}

// Attempt is one authority's refusal.
type Attempt struct {
	Authority string `json:"authority"`
	Error     string `json:"error"`
}

// IssueResult is ssl.issue's answer. A refusal is an answer too (Issued
// false), so the panel learns which names failed and why.
type IssueResult struct {
	Issued    bool       `json:"issued"`
	Cert      *Info      `json:"cert"`
	Authority string     `json:"authority"`
	Excluded  []Excluded `json:"excluded"`
	Attempts  []Attempt  `json:"attempts"`
	Error     string     `json:"error,omitempty"`
}

// InstallArgs are ssl.install's: a certificate the owner brings, with its
// key, or without when it answers a request (CSR) made here.
type InstallArgs struct {
	Username string `json:"username"`
	Key      string `json:"key"`
	Chain    string `json:"chain"`
}

// HostInstallArgs are ssl.host_install's.
type HostInstallArgs struct {
	Key   string `json:"key"`
	Chain string `json:"chain"`
}

// CSRArgs are ssl.csr's.
type CSRArgs struct {
	Username     string   `json:"username"`
	Names        []string `json:"names"`
	KeyType      string   `json:"key_type"` // "ecdsa" (P-256, the default) or "rsa" (2048)
	Country      string   `json:"country"`
	State        string   `json:"state"`
	Locality     string   `json:"locality"`
	Organization string   `json:"organization"`
}

// CSRResult is ssl.csr's answer.
type CSRResult struct {
	CSR   string `json:"csr"`
	KeyID string `json:"key_id"`
}

// HostInfo is ssl.host_info's answer.
type HostInfo struct {
	Installed bool  `json:"installed"`
	Cert      *Info `json:"cert"`
}

func (m *Manager) checkNames(names []string) ([]string, error) {
	if len(names) == 0 || len(names) > 100 {
		return nil, protocol.Fail(protocol.CodeBadArgs, "a certificate has 1 to 100 names")
	}
	seen := map[string]bool{}
	var out []string
	for _, n := range names {
		if err := CheckHost(n); err != nil {
			return nil, err
		}
		if !seen[n] {
			seen[n] = true
			out = append(out, n)
		}
	}
	return out, nil
}

func checkAuthorities(list []string) error {
	if len(list) == 0 || len(list) > 2 {
		return protocol.Fail(protocol.CodeBadArgs, "name one or two authorities")
	}
	for _, a := range list {
		if a != LetsEncrypt && a != ZeroSSL {
			return protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf("authority %q is not letsencrypt or zerossl", a))
		}
	}
	return nil
}

func (e *EAB) check() error {
	if e == nil {
		return nil
	}
	if len(e.KID) > 200 || len(e.HMAC) > 200 || strings.Trim(e.KID+e.HMAC, "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-=") != "" {
		return protocol.Fail(protocol.CodeBadArgs, "the external account binding may only hold base64url characters")
	}
	return nil
}

// Issue gets a site a certificate from an authority and stores it. The site
// starts serving it when web.apply names its id.
func (m *Manager) Issue(ctx context.Context, a IssueArgs) (IssueResult, error) {
	names, err := m.checkNames(a.Names)
	if err != nil {
		return IssueResult{}, err
	}
	if err := checkAuthorities(a.Authorities); err != nil {
		return IssueResult{}, err
	}
	if err := a.EAB.check(); err != nil {
		return IssueResult{}, err
	}
	if _, _, _, err := m.Owner(a.Username); err != nil {
		return IssueResult{}, err
	}
	res, pair, err := m.obtain(ctx, names, a.Authorities, a.EAB)
	if err != nil || pair == nil {
		return res, err
	}
	if err := m.Store.Save(a.Username, *pair); err != nil {
		return IssueResult{}, err
	}
	m.Log.Info("certificate issued", "username", a.Username, "names", strings.Join(pair.Names, ","), "authority", res.Authority, "id", pair.ID)
	return res, nil
}

// HostIssue gets the server's hostname a certificate and installs it for
// the panel's ports and the default HTTPS site.
func (m *Manager) HostIssue(ctx context.Context, a HostIssueArgs) (IssueResult, error) {
	names, err := m.checkNames(a.Names)
	if err != nil {
		return IssueResult{}, err
	}
	if err := checkAuthorities(a.Authorities); err != nil {
		return IssueResult{}, err
	}
	if err := a.EAB.check(); err != nil {
		return IssueResult{}, err
	}
	res, pair, err := m.obtain(ctx, names, a.Authorities, a.EAB)
	if err != nil || pair == nil {
		return res, err
	}
	if err := m.installHost(ctx, *pair); err != nil {
		return IssueResult{}, err
	}
	return res, nil
}

// obtain checks which names point here, then asks each authority in turn.
// A nil pair with no error is a refusal, described in the result.
func (m *Manager) obtain(ctx context.Context, names, authorities []string, eab *EAB) (IssueResult, *Pair, error) {
	res := IssueResult{Excluded: []Excluded{}, Attempts: []Attempt{}}
	ok, excluded := m.preflight(ctx, names)
	res.Excluded = excluded
	if len(ok) == 0 || ok[0] != names[0] {
		reason := names[0] + " does not point at this server"
		for _, e := range excluded {
			if e.Name == names[0] {
				reason = names[0] + ": " + e.Reason
			}
		}
		res.Error = reason
		return res, nil, nil
	}

	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return res, nil, err
	}
	csr, err := x509.CreateCertificateRequest(rand.Reader, &x509.CertificateRequest{Subject: pkix.Name{CommonName: ok[0]}, DNSNames: ok}, key)
	if err != nil {
		return res, nil, err
	}

	var tokens []string
	defer func() {
		for _, t := range tokens {
			os.Remove(filepath.Join(m.Challenges, t))
		}
	}()
	present := func(token, keyAuth string) error {
		if !tokenRule.MatchString(token) || !strings.HasPrefix(keyAuth, token+".") {
			return errors.New("the authority sent a token LPanel does not accept")
		}
		tokens = append(tokens, token)
		return m.putChallenge(token, keyAuth)
	}
	for _, ca := range authorities {
		chain, err := m.ACME.Issue(ctx, ca, eab, ok, csr, present)
		if err != nil {
			res.Attempts = append(res.Attempts, Attempt{Authority: ca, Error: clip(err.Error())})
			m.Log.Warn("the authority did not issue", "authority", ca, "names", strings.Join(ok, ","), "err", err)
			var ae *AuthorityError
			if errors.As(err, &ae) && ae.Validation {
				break // another authority would check the same and fail too
			}
			continue
		}
		pair, err := Check(key, chain, m.Now())
		if err != nil {
			res.Attempts = append(res.Attempts, Attempt{Authority: ca, Error: "its certificate was unusable: " + err.Error()})
			continue
		}
		for _, n := range ok {
			if !pair.Covers(n) {
				res.Attempts = append(res.Attempts, Attempt{Authority: ca, Error: "its certificate does not cover " + n})
				return res, nil, nil
			}
		}
		res.Issued, res.Cert, res.Authority = true, &pair.Info, ca
		return res, &pair, nil
	}
	var parts []string
	for _, at := range res.Attempts {
		parts = append(parts, at.Authority+": "+at.Error)
	}
	res.Error = strings.Join(parts, "; ")
	return res, nil, nil
}

func (m *Manager) putChallenge(token, content string) error {
	if err := os.MkdirAll(m.Challenges, 0o755); err != nil {
		return err
	}
	if err := os.Chmod(m.Challenges, 0o755); err != nil {
		return err
	}
	return WriteAtomic(filepath.Join(m.Challenges, token), []byte(content), 0o644)
}

// preflight keeps the names that point at this server: each must resolve,
// and the site must answer a check of our own at its HTTP-01 address. A name
// that cannot be reached at all still counts when it resolves to an address
// of this server (some networks cannot reach their own public address).
// Failures count against the authorities' limits, so they are found here.
func (m *Manager) preflight(ctx context.Context, names []string) ([]string, []Excluded) {
	token := randomToken()
	content := randomToken()
	if err := m.putChallenge(token, content); err != nil {
		m.Log.Warn("could not write the check file", "err", err)
		return names, []Excluded{}
	}
	defer os.Remove(filepath.Join(m.Challenges, token))
	local := map[string]bool{}
	for _, ip := range m.LocalIPs() {
		local[ip] = true
	}

	reasons := make([]string, len(names))
	var wg sync.WaitGroup
	sem := make(chan struct{}, 8)
	for i, n := range names {
		wg.Add(1)
		go func(i int, n string) {
			defer wg.Done()
			sem <- struct{}{}
			defer func() { <-sem }()
			cctx, cancel := context.WithTimeout(ctx, 15*time.Second)
			defer cancel()
			addrs, err := m.Resolve(cctx, n)
			if err != nil || len(addrs) == 0 {
				reasons[i] = "it has no DNS address (A or AAAA record)"
				return
			}
			status, body, err := m.Fetch(cctx, "http://"+n+"/.well-known/acme-challenge/"+token)
			switch {
			case err == nil && status == 200 && strings.TrimSpace(string(body)) == content:
			case err != nil:
				for _, a := range addrs {
					if local[a] {
						return
					}
				}
				reasons[i] = fmt.Sprintf("it points at %s, which did not answer, and is not an address of this server", strings.Join(addrs, ", "))
			default:
				reasons[i] = fmt.Sprintf("it points at %s, and another server answered there (HTTP %d)", strings.Join(addrs, ", "), status)
			}
		}(i, n)
	}
	wg.Wait()
	var ok []string
	excluded := []Excluded{}
	for i, n := range names {
		if reasons[i] == "" {
			ok = append(ok, n)
		} else {
			excluded = append(excluded, Excluded{Name: n, Reason: reasons[i]})
		}
	}
	return ok, excluded
}

// Install stores a certificate the owner brings.
func (m *Manager) Install(_ context.Context, a InstallArgs) (Info, error) {
	if _, _, _, err := m.Owner(a.Username); err != nil {
		return Info{}, err
	}
	pair, err := m.pairFrom(a.Username, a.Key, a.Chain)
	if err != nil {
		return Info{}, err
	}
	if err := m.Store.Save(a.Username, pair); err != nil {
		return Info{}, err
	}
	m.Log.Info("certificate installed", "username", a.Username, "names", strings.Join(pair.Names, ","), "id", pair.ID)
	return pair.Info, nil
}

// HostInstall installs a certificate the administrator brings for the
// server's hostname.
func (m *Manager) HostInstall(ctx context.Context, a HostInstallArgs) (Info, error) {
	if strings.TrimSpace(a.Key) == "" {
		return Info{}, protocol.Fail(CodeNoKey, "the hostname certificate needs its private key")
	}
	pair, err := m.pairFrom("", a.Key, a.Chain)
	if err != nil {
		return Info{}, err
	}
	return pair.Info, m.installHost(ctx, pair)
}

func (m *Manager) pairFrom(user, keyText, chainText string) (Pair, error) {
	if len(keyText) > 64<<10 || len(chainText) > 128<<10 {
		return Pair{}, invalid("the key or the chain is too large")
	}
	chain, err := ParseChain(chainText)
	if err != nil {
		return Pair{}, err
	}
	var key crypto.Signer
	if strings.TrimSpace(keyText) != "" {
		if key, err = ParseKey(keyText); err != nil {
			return Pair{}, err
		}
	} else {
		leaf, _ := x509.ParseCertificate(chain[0])
		if key, err = m.Store.RequestKey(user, leaf.PublicKey); err != nil {
			return Pair{}, err
		}
	}
	return Check(key, chain, m.Now())
}

var subjectRule = regexp.MustCompile(`^[A-Za-z0-9 .,'&()/-]{0,64}$`)

// CSR makes a key and a certificate request for a commercial certificate.
// The key stays here; the certificate is installed with ssl.install.
func (m *Manager) CSR(_ context.Context, a CSRArgs) (CSRResult, error) {
	names, err := m.checkNames(a.Names)
	if err != nil {
		return CSRResult{}, err
	}
	if _, _, _, err := m.Owner(a.Username); err != nil {
		return CSRResult{}, err
	}
	if a.Country != "" && (len(a.Country) != 2 || strings.Trim(a.Country, "ABCDEFGHIJKLMNOPQRSTUVWXYZ") != "") {
		return CSRResult{}, protocol.Fail(protocol.CodeBadArgs, "country must be two capital letters, such as US")
	}
	for _, f := range []string{a.State, a.Locality, a.Organization} {
		if !subjectRule.MatchString(f) {
			return CSRResult{}, protocol.Fail(protocol.CodeBadArgs, "state, city and organization may hold letters, digits, spaces and . , ' & ( ) / - (at most 64)")
		}
	}
	var key crypto.Signer
	switch a.KeyType {
	case "", "ecdsa":
		key, err = ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	case "rsa":
		key, err = rsa.GenerateKey(rand.Reader, 2048)
	default:
		return CSRResult{}, protocol.Fail(protocol.CodeBadArgs, "key_type must be ecdsa or rsa")
	}
	if err != nil {
		return CSRResult{}, err
	}
	subject := pkix.Name{CommonName: names[0]}
	for _, f := range []struct {
		v  string
		to *[]string
	}{{a.Country, &subject.Country}, {a.State, &subject.Province}, {a.Locality, &subject.Locality}, {a.Organization, &subject.Organization}} {
		if f.v != "" {
			*f.to = []string{f.v}
		}
	}
	der, err := x509.CreateCertificateRequest(rand.Reader, &x509.CertificateRequest{Subject: subject, DNSNames: names}, key)
	if err != nil {
		return CSRResult{}, err
	}
	id, err := m.Store.SaveRequestKey(a.Username, key)
	if err != nil {
		return CSRResult{}, err
	}
	return CSRResult{CSR: string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE REQUEST", Bytes: der})), KeyID: id}, nil
}

// HostInfo describes the hostname certificate installed now.
func (m *Manager) HostInfo(context.Context) (HostInfo, error) {
	crt, err1 := os.ReadFile(m.HostCert)
	key, err2 := os.ReadFile(m.HostKey)
	if err1 != nil || err2 != nil {
		return HostInfo{}, nil
	}
	pair, err := m.pairFrom("", string(key), string(crt))
	if err != nil {
		// Present but unusable (expired, say): describe the leaf anyway.
		chain, perr := ParseChain(string(crt))
		if perr != nil {
			return HostInfo{Installed: true}, nil
		}
		leaf, _ := x509.ParseCertificate(chain[0])
		info := Info{Names: leafNames(leaf), Issuer: printable(leaf.Issuer.CommonName), Serial: hex.EncodeToString(leaf.SerialNumber.Bytes()), NotBefore: leaf.NotBefore.Unix(), NotAfter: leaf.NotAfter.Unix()}
		return HostInfo{Installed: true, Cert: &info}, nil
	}
	return HostInfo{Installed: true, Cert: &pair.Info}, nil
}

// installHost puts the pair in place of the hostname certificate, tests the
// panel's server with it, reloads, and checks it is served. If anything
// fails the old files come back.
func (m *Manager) installHost(ctx context.Context, p Pair) error {
	m.hostMu.Lock()
	defer m.hostMu.Unlock()
	oldCrt, errC := os.ReadFile(m.HostCert)
	oldKey, errK := os.ReadFile(m.HostKey)
	had := errC == nil && errK == nil
	key, err := KeyPEM(p.Key)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(m.HostCert), 0o700); err != nil {
		return err
	}
	restore := func() {
		if had {
			_ = WriteAtomic(m.HostKey, oldKey, 0o600)
			_ = WriteAtomic(m.HostCert, oldCrt, 0o644)
		}
	}
	if err := WriteAtomic(m.HostKey, key, 0o600); err != nil {
		return err
	}
	if err := WriteAtomic(m.HostCert, ChainPEM(p.Chain), 0o644); err != nil {
		restore()
		return err
	}
	fail := func(why string) error {
		restore()
		if had {
			m.reloadHost(context.WithoutCancel(ctx))
		}
		return protocol.Fail(CodeHostTLS, why+"; the previous certificate is back")
	}
	if _, err := os.Stat(m.PanelNginx); err == nil {
		code, out, err := m.Run.Run(ctx, "nginx", "-t", "-q", "-c", m.PanelNginx)
		if err != nil || code != 0 {
			return fail("the panel's nginx refused it: " + strings.TrimSpace(out))
		}
	}
	if err := m.reloadHost(ctx); err != nil {
		return fail(err.Error())
	}
	pctx, cancel := context.WithTimeout(ctx, m.ProbeFor)
	defer cancel()
	var last error
	for {
		if last = m.ProbeHost(pctx, p.Names[0], p.Serial); last == nil {
			break
		}
		select {
		case <-pctx.Done():
			return fail("the panel did not serve it: " + last.Error())
		case <-time.After(300 * time.Millisecond):
		}
	}
	m.Log.Info("hostname certificate installed", "names", strings.Join(p.Names, ","), "serial", p.Serial)
	return nil
}

func (m *Manager) reloadHost(ctx context.Context) error {
	for _, unit := range m.HostUnits {
		if code, _, err := m.Run.Run(ctx, "systemctl", "is-active", "--quiet", unit); err != nil || code != 0 {
			continue
		}
		if err := sysexec.Must(ctx, m.Run, "systemctl", "reload", unit); err != nil {
			return err
		}
	}
	return nil
}

// probeHost asks the panel's Admin port for serverName and checks the
// certificate it presents.
func probeHost(ctx context.Context, serverName, serial string) error {
	d := tls.Dialer{Config: &tls.Config{ServerName: serverName, InsecureSkipVerify: true}} //nolint:gosec // only the serial is compared
	conn, err := d.DialContext(ctx, "tcp", "127.0.0.1:7443")
	if err != nil {
		return err
	}
	defer conn.Close()
	certs := conn.(*tls.Conn).ConnectionState().PeerCertificates
	if len(certs) == 0 {
		return errors.New("no certificate was presented")
	}
	if got := hex.EncodeToString(certs[0].SerialNumber.Bytes()); got != serial {
		return fmt.Errorf("it presents serial %s", got)
	}
	return nil
}

func fetch(ctx context.Context, url string) (int, []byte, error) {
	client := &http.Client{
		Timeout: 10 * time.Second,
		// As the authorities do: a redirect (to https, say) is followed.
		Transport: &http.Transport{TLSClientConfig: &tls.Config{InsecureSkipVerify: true}, DisableKeepAlives: true}, //nolint:gosec // only the body is compared
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return 0, nil, err
	}
	req.Header.Set("User-Agent", "lpa (LPanel domain check)")
	resp, err := client.Do(req)
	if err != nil {
		return 0, nil, err
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(io.LimitReader(resp.Body, 4096))
	return resp.StatusCode, body, nil
}

func localIPs() []string {
	addrs, _ := net.InterfaceAddrs()
	var out []string
	for _, a := range addrs {
		if n, ok := a.(*net.IPNet); ok {
			out = append(out, n.IP.String())
		}
	}
	return out
}

func randomToken() string {
	b := make([]byte, 24)
	_, _ = rand.Read(b)
	return base64.RawURLEncoding.EncodeToString(b)
}
