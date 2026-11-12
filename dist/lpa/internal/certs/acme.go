package certs

import (
	"context"

	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"time"

	"golang.org/x/crypto/acme"
)

// The certificate authorities LPanel knows. Their directories come from
// lpa.json, never from an operation, so the panel cannot send the agent
// anywhere else.
const (
	LetsEncrypt = "letsencrypt"
	ZeroSSL     = "zerossl"
)

// ValidDirectory says whether u is an https URL the agent may use as a
// directory.
func ValidDirectory(u string) bool {
	p, err := url.Parse(u)
	return err == nil && p.Scheme == "https" && p.Host != "" && p.User == nil
}

// EAB is an external account binding: ZeroSSL's account credentials, from
// its dashboard (Developer → EAB credentials).
type EAB struct {
	KID  string `json:"kid"`
	HMAC string `json:"hmac"`
}

// AuthorityError is an authority's refusal. Validation is true when it
// could not check a domain (another authority would see the same).
type AuthorityError struct {
	Validation bool
	Msg        string
}

func (e *AuthorityError) Error() string { return e.Msg }

// ACME gets certificates from the authorities in Directories.
type ACME struct {
	Directories map[string]string // name → directory URL
	// State is where the account key for each directory is kept, root's
	// alone (/var/lib/lpa/acme).
	State string
	// Roots, when set, is a PEM file of extra roots to trust for the
	// directories (a test authority such as Pebble).
	Roots     string
	UserAgent string
}

// account is the account file for one directory.
type account struct {
	Directory string `json:"directory"`
	Key       string `json:"key"`
	URI       string `json:"uri"`
}

// Issue proves control of names with HTTP-01, through the answers present
// puts where the web server serves them, and returns the chain issued for
// csr, leaf first.
func (a *ACME) Issue(ctx context.Context, ca string, eab *EAB, names []string, csr []byte, present func(token, keyAuth string) error) ([][]byte, error) {
	c, err := a.client(ctx, ca, eab)
	if err != nil {
		return nil, err
	}
	order, err := c.AuthorizeOrder(ctx, acme.DomainIDs(names...))
	if err != nil {
		return nil, explain(err)
	}
	for _, u := range order.AuthzURLs {
		z, err := c.GetAuthorization(ctx, u)
		if err != nil {
			return nil, explain(err)
		}
		if z.Status == acme.StatusValid {
			continue
		}
		var chal *acme.Challenge
		for _, ch := range z.Challenges {
			if ch.Type == "http-01" {
				chal = ch
			}
		}
		if chal == nil {
			return nil, &AuthorityError{Msg: "it offered no HTTP-01 check for " + z.Identifier.Value}
		}
		keyAuth, err := c.HTTP01ChallengeResponse(chal.Token)
		if err != nil {
			return nil, err
		}
		if err := present(chal.Token, keyAuth); err != nil {
			return nil, err
		}
		if _, err := c.Accept(ctx, chal); err != nil {
			return nil, explain(err)
		}
		if _, err := c.WaitAuthorization(ctx, z.URI); err != nil {
			e := explain(err)
			return nil, &AuthorityError{Validation: true, Msg: z.Identifier.Value + " did not pass its check: " + e.Error()}
		}
	}
	orderURI := order.URI
	if order, err = c.WaitOrder(ctx, orderURI); err != nil {
		return nil, explain(err)
	}
	chain, _, err := c.CreateOrderCert(ctx, order.FinalizeURL, csr, true)
	if err == nil {
		return chain, nil
	}
	// An authority that finalizes in the background may answer without the
	// order's URL, which CreateOrderCert needs to wait: wait on the one known.
	done, werr := c.WaitOrder(ctx, orderURI)
	if werr != nil || done.Status != acme.StatusValid || done.CertURL == "" {
		return nil, explain(err)
	}
	if chain, err = c.FetchCert(ctx, done.CertURL, true); err != nil {
		return nil, explain(err)
	}
	return chain, nil
}

// client is an ACME client with this server's account at the authority,
// registered on first use. Registering accepts the authority's terms of
// service.
func (a *ACME) client(ctx context.Context, ca string, eab *EAB) (*acme.Client, error) {
	dir := a.Directories[ca]
	if !ValidDirectory(dir) {
		return nil, &AuthorityError{Msg: fmt.Sprintf("no https directory is set for %s in lpa.json", ca)}
	}
	sum := sha256.Sum256([]byte(dir))
	path := filepath.Join(a.State, "account-"+hex.EncodeToString(sum[:8])+".json")

	var acct account
	data, err := os.ReadFile(path)
	switch {
	case errors.Is(err, os.ErrNotExist):
		key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
		if err != nil {
			return nil, err
		}
		text, err := KeyPEM(key)
		if err != nil {
			return nil, err
		}
		acct = account{Directory: dir, Key: string(text)}
		// Saved before registering: a key the authority knows is never lost.
		if err := a.save(path, acct); err != nil {
			return nil, err
		}
	case err != nil:
		return nil, err
	default:
		if err := json.Unmarshal(data, &acct); err != nil || acct.Directory != dir {
			return nil, fmt.Errorf("the account file %s is unreadable", path)
		}
	}
	key, err := ParseKey(acct.Key)
	if err != nil {
		return nil, fmt.Errorf("the account key in %s is unreadable", path)
	}
	hc, err := a.httpClient()
	if err != nil {
		return nil, err
	}
	c := &acme.Client{Key: key, DirectoryURL: dir, UserAgent: a.UserAgent, HTTPClient: hc}
	if acct.URI != "" {
		return c, nil
	}
	reg := &acme.Account{}
	if eab != nil && eab.KID != "" {
		hmac, err := base64.RawURLEncoding.DecodeString(strings.TrimRight(eab.HMAC, "="))
		if err != nil || len(hmac) == 0 {
			return nil, &AuthorityError{Msg: "the external account HMAC key is not base64url"}
		}
		reg.ExternalAccountBinding = &acme.ExternalAccountBinding{KID: eab.KID, Key: hmac}
	}
	if _, err := c.Register(ctx, reg, acme.AcceptTOS); err != nil && !errors.Is(err, acme.ErrAccountAlreadyExists) {
		return nil, &AuthorityError{Msg: "registering with it: " + explain(err).Error()}
	}
	got, err := c.GetReg(ctx, "")
	if err != nil {
		return nil, &AuthorityError{Msg: "reading its account: " + explain(err).Error()}
	}
	acct.URI = got.URI
	return c, a.save(path, acct)
}

func (a *ACME) httpClient() (*http.Client, error) {
	tr := http.DefaultTransport.(*http.Transport).Clone()
	if a.Roots != "" {
		pool, err := x509.SystemCertPool()
		if err != nil || pool == nil {
			pool = x509.NewCertPool()
		}
		pem, err := os.ReadFile(a.Roots)
		if err != nil {
			return nil, fmt.Errorf("acme roots: %w", err)
		}
		if !pool.AppendCertsFromPEM(pem) {
			return nil, fmt.Errorf("acme roots: %s holds no certificate", a.Roots)
		}
		tr.TLSClientConfig = &tls.Config{RootCAs: pool, MinVersion: tls.VersionTLS12}
	}
	return &http.Client{Transport: tr, Timeout: 60 * time.Second}, nil
}

func (a *ACME) save(path string, acct account) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return err
	}
	data, err := json.MarshalIndent(acct, "", "  ")
	if err != nil {
		return err
	}
	return WriteAtomic(path, append(data, '\n'), 0o600)
}

// explain is an authority's error as one line a person can act on.
func explain(err error) error {
	var ae *acme.Error
	var ze *acme.AuthorizationError
	var oe *acme.OrderError
	switch {
	case errors.As(err, &ze):
		var parts []string
		for _, e := range ze.Errors {
			parts = append(parts, explain(e).Error())
		}
		if len(parts) == 0 {
			return &AuthorityError{Validation: true, Msg: "its check failed"}
		}
		return &AuthorityError{Validation: true, Msg: strings.Join(parts, "; ")}
	case errors.As(err, &ae):
		msg := ae.Detail
		if strings.HasSuffix(ae.ProblemType, ":rateLimited") {
			msg = "its rate limit was reached (" + ae.Detail + ")"
		} else if msg == "" {
			msg = fmt.Sprintf("it answered %d %s", ae.StatusCode, ae.ProblemType)
		}
		validation := false
		for _, t := range []string{":unauthorized", ":connection", ":dns", ":incorrectResponse", ":rejectedIdentifier", ":caa"} {
			validation = validation || strings.HasSuffix(ae.ProblemType, t)
		}
		return &AuthorityError{Validation: validation, Msg: clip(msg)}
	case errors.As(err, &oe):
		return &AuthorityError{Msg: fmt.Sprintf("the order became %s", oe.Status)}
	case errors.Is(err, context.DeadlineExceeded):
		return &AuthorityError{Msg: "it did not answer in time"}
	}
	return &AuthorityError{Msg: clip(err.Error())}
}

// clip keeps an authority's message printable and short.
func clip(s string) string {
	s = printable(strings.Join(strings.Fields(s), " "))
	if len(s) > 300 {
		s = s[:300] + "..."
	}
	return s
}
