// Package certs keeps the TLS certificates of accounts' sites and of the
// server's own hostname, and gets new ones from an ACME certificate
// authority (RFC 8555) with HTTP-01 checks.
//
// Keys are made here and never leave the server. A site's certificate is
// stored under its account as two files named after the certificate's id
// (the first 16 hex digits of the SHA-256 of the leaf):
//
//	/etc/pki/lpanel/sites/<account>/<id>.crt   the chain, leaf first
//	/etc/pki/lpanel/sites/<account>/<id>.key   its key
//
// Both are root's alone; Apache's and nginx's masters read them as root. A
// new certificate is a new pair of files, so nothing served changes until
// web.apply names the new id, and the old pair is pruned after that.
package certs

import (
	"bytes"
	"crypto"
	"crypto/ecdsa"
	"crypto/ed25519"
	"crypto/rsa"
	"crypto/sha256"
	"crypto/x509"
	"encoding/hex"
	"encoding/pem"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"time"

	"lpa/internal/protocol"
)

// Error codes particular to certificates.
const (
	CodeCertInvalid = "cert_invalid" // a certificate or key that cannot be used
	CodeNoKey       = "cert_no_key"  // no key given, and no request made here matches
	CodeIssue       = "cert_issue"   // no authority issued a certificate
	CodeNoNames     = "cert_no_names"
	CodeHostTLS     = "host_tls" // the panel's server did not take the hostname certificate; the old one is back
)

var (
	idRule    = regexp.MustCompile(`^[0-9a-f]{16}$`)
	userRule  = regexp.MustCompile(`^[a-z][a-z0-9]{1,15}$`)
	labelRule = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$`)
)

// ValidID says whether id names a stored certificate.
func ValidID(id string) bool { return idRule.MatchString(id) }

// Info describes a certificate.
type Info struct {
	ID        string   `json:"id"`
	Names     []string `json:"names"`
	Issuer    string   `json:"issuer"`
	Serial    string   `json:"serial"`
	NotBefore int64    `json:"not_before"`
	NotAfter  int64    `json:"not_after"`
	KeyType   string   `json:"key_type"`
	// SelfSigned certificates are installed, with a warning in the panel:
	// browsers will not trust them.
	SelfSigned bool `json:"self_signed"`
}

// Pair is a certificate checked against its key.
type Pair struct {
	Info
	Key   crypto.Signer
	Chain [][]byte
}

func invalid(format string, a ...any) error {
	return protocol.Fail(CodeCertInvalid, fmt.Sprintf(format, a...))
}

// CheckHost accepts a lowercase domain name with at least two labels, and no
// wildcard: HTTP-01 cannot prove one.
func CheckHost(name string) error {
	if len(name) > 253 || strings.Count(name, ".") < 1 {
		return protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf("%q is not a domain name", name))
	}
	for _, l := range strings.Split(name, ".") {
		if !labelRule.MatchString(l) {
			return protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf("%q is not a domain name", name))
		}
	}
	return nil
}

// ParseKey reads a private key in PEM: PKCS#8, PKCS#1 (RSA) or SEC 1 (EC).
func ParseKey(text string) (crypto.Signer, error) {
	rest := []byte(text)
	for {
		var block *pem.Block
		block, rest = pem.Decode(rest)
		if block == nil {
			return nil, invalid("the private key is not in PEM form")
		}
		var k any
		var err error
		switch block.Type {
		case "PRIVATE KEY":
			k, err = x509.ParsePKCS8PrivateKey(block.Bytes)
		case "RSA PRIVATE KEY":
			k, err = x509.ParsePKCS1PrivateKey(block.Bytes)
		case "EC PRIVATE KEY":
			k, err = x509.ParseECPrivateKey(block.Bytes)
		case "ENCRYPTED PRIVATE KEY":
			return nil, invalid("the private key is encrypted; give it without a passphrase")
		default:
			continue
		}
		if err != nil {
			return nil, invalid("the private key could not be read")
		}
		s, ok := k.(crypto.Signer)
		if !ok {
			return nil, invalid("the private key is of a kind LPanel does not use")
		}
		if r, ok := s.(*rsa.PrivateKey); ok && r.N.BitLen() < 2048 {
			return nil, invalid("the RSA key has %d bits; at least 2048 are needed", r.N.BitLen())
		}
		return s, nil
	}
}

// ParseChain reads certificates in PEM, leaf first.
func ParseChain(text string) ([][]byte, error) {
	var chain [][]byte
	rest := []byte(text)
	for {
		var block *pem.Block
		block, rest = pem.Decode(rest)
		if block == nil {
			break
		}
		if block.Type != "CERTIFICATE" {
			continue
		}
		if _, err := x509.ParseCertificate(block.Bytes); err != nil {
			return nil, invalid("a certificate in the chain could not be read")
		}
		chain = append(chain, block.Bytes)
	}
	if len(chain) == 0 {
		return nil, invalid("no certificate was found (it must be in PEM form: -----BEGIN CERTIFICATE-----)")
	}
	if len(chain) > 10 {
		return nil, invalid("the chain has %d certificates; at most 10", len(chain))
	}
	return chain, nil
}

// Check makes a pair of key and chain: the leaf must be for the key and
// valid at now, and the rest of the chain must be its issuers in order.
func Check(key crypto.Signer, chain [][]byte, now time.Time) (Pair, error) {
	if len(chain) == 0 {
		return Pair{}, invalid("the chain is empty")
	}
	certs := make([]*x509.Certificate, len(chain))
	for i, der := range chain {
		c, err := x509.ParseCertificate(der)
		if err != nil {
			return Pair{}, invalid("a certificate in the chain could not be read")
		}
		certs[i] = c
	}
	leaf := certs[0]
	if !samePublic(leaf.PublicKey, key.Public()) {
		return Pair{}, invalid("the certificate is not for this private key")
	}
	if now.Add(5 * time.Minute).Before(leaf.NotBefore) {
		return Pair{}, invalid("the certificate is not valid until %s", leaf.NotBefore.UTC().Format(time.RFC3339))
	}
	if !now.Before(leaf.NotAfter) {
		return Pair{}, invalid("the certificate expired on %s", leaf.NotAfter.UTC().Format(time.RFC3339))
	}
	for i := 1; i < len(certs); i++ {
		if err := certs[i-1].CheckSignatureFrom(certs[i]); err != nil {
			return Pair{}, invalid("certificate %d of the chain did not issue the one before it; give the chain in order, leaf first", i+1)
		}
	}
	names := leafNames(leaf)
	if len(names) == 0 {
		return Pair{}, invalid("the certificate names no domain")
	}
	sum := sha256.Sum256(leaf.Raw)
	issuer := leaf.Issuer.CommonName
	if len(leaf.Issuer.Organization) > 0 && leaf.Issuer.Organization[0] != issuer {
		issuer = strings.TrimSpace(leaf.Issuer.Organization[0] + " " + issuer)
	}
	self := bytes.Equal(leaf.RawIssuer, leaf.RawSubject) && leaf.CheckSignature(leaf.SignatureAlgorithm, leaf.RawTBSCertificate, leaf.Signature) == nil
	return Pair{
		Info: Info{
			ID:         hex.EncodeToString(sum[:])[:16],
			Names:      names,
			Issuer:     printable(issuer),
			Serial:     hex.EncodeToString(leaf.SerialNumber.Bytes()),
			NotBefore:  leaf.NotBefore.Unix(),
			NotAfter:   leaf.NotAfter.Unix(),
			KeyType:    keyType(key),
			SelfSigned: self,
		},
		Key:   key,
		Chain: chain,
	}, nil
}

// Covers says whether the certificate's names cover host (one wildcard
// level counts).
func (i Info) Covers(host string) bool {
	for _, n := range i.Names {
		if n == host {
			return true
		}
		if strings.HasPrefix(n, "*.") {
			if dot := strings.IndexByte(host, '.'); dot > 0 && host[dot+1:] == n[2:] {
				return true
			}
		}
	}
	return false
}

func leafNames(leaf *x509.Certificate) []string {
	seen := map[string]bool{}
	var out []string
	add := func(n string) {
		n = strings.ToLower(strings.TrimSuffix(n, "."))
		if n != "" && !seen[n] && len(n) <= 253 && strings.Trim(n, "abcdefghijklmnopqrstuvwxyz0123456789.-*") == "" {
			seen[n] = true
			out = append(out, n)
		}
	}
	for _, n := range leaf.DNSNames {
		add(n)
	}
	if len(out) == 0 && strings.Contains(leaf.Subject.CommonName, ".") {
		add(leaf.Subject.CommonName)
	}
	sort.Strings(out)
	return out
}

func samePublic(a, b crypto.PublicKey) bool {
	type equaler interface{ Equal(crypto.PublicKey) bool }
	e, ok := a.(equaler)
	return ok && e.Equal(b)
}

func keyType(k crypto.Signer) string {
	switch p := k.Public().(type) {
	case *ecdsa.PublicKey:
		return fmt.Sprintf("ECDSA P-%d", p.Curve.Params().BitSize)
	case *rsa.PublicKey:
		return fmt.Sprintf("RSA %d", p.N.BitLen())
	case ed25519.PublicKey:
		return "Ed25519"
	}
	return "unknown"
}

// printable keeps text from a certificate to plain, short ASCII.
func printable(s string) string {
	b := []byte(s)
	for i, c := range b {
		if c < 0x20 || c > 0x7e {
			b[i] = '?'
		}
	}
	if len(b) > 200 {
		b = b[:200]
	}
	return string(b)
}

// KeyPEM is a key as PKCS#8 PEM.
func KeyPEM(k crypto.Signer) ([]byte, error) {
	der, err := x509.MarshalPKCS8PrivateKey(k)
	if err != nil {
		return nil, err
	}
	return pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: der}), nil
}

// ChainPEM is a chain as PEM, leaf first.
func ChainPEM(chain [][]byte) []byte {
	var b bytes.Buffer
	for _, c := range chain {
		_ = pem.Encode(&b, &pem.Block{Type: "CERTIFICATE", Bytes: c})
	}
	return b.Bytes()
}

// ---------------------------------------------------------------------------

// Store keeps sites' certificates, one folder per account.
type Store struct {
	Dir string // /etc/pki/lpanel/sites
	Now func() time.Time
}

func (s *Store) now() time.Time {
	if s.Now != nil {
		return s.Now()
	}
	return time.Now()
}

func (s *Store) userDir(user string) (string, error) {
	if !userRule.MatchString(user) {
		return "", protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf("username %q is not 2–16 lowercase letters and digits", user))
	}
	return filepath.Join(s.Dir, user), nil
}

func (s *Store) ensure(user string) (string, error) {
	dir, err := s.userDir(user)
	if err != nil {
		return "", err
	}
	for _, d := range []string{filepath.Dir(s.Dir), s.Dir, dir} {
		if err := os.MkdirAll(d, 0o700); err != nil {
			return "", err
		}
		if st, err := os.Lstat(d); err != nil || !st.IsDir() {
			return "", fmt.Errorf("%s is not a plain folder", d)
		}
	}
	return dir, os.Chmod(dir, 0o700)
}

// Save stores a checked pair under the account. Saving one already there
// changes nothing.
func (s *Store) Save(user string, p Pair) error {
	dir, err := s.ensure(user)
	if err != nil {
		return err
	}
	key, err := KeyPEM(p.Key)
	if err != nil {
		return err
	}
	if err := WriteAtomic(filepath.Join(dir, p.ID+".key"), key, 0o600); err != nil {
		return err
	}
	return WriteAtomic(filepath.Join(dir, p.ID+".crt"), ChainPEM(p.Chain), 0o600)
}

// Files are the paths of a stored certificate and its key; an error when
// either is missing.
func (s *Store) Files(user, id string) (crt, key string, err error) {
	dir, err := s.userDir(user)
	if err != nil {
		return "", "", err
	}
	if !ValidID(id) {
		return "", "", protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf("certificate id %q is not 16 hex digits", id))
	}
	crt, key = filepath.Join(dir, id+".crt"), filepath.Join(dir, id+".key")
	for _, p := range []string{crt, key} {
		if st, err := os.Lstat(p); err != nil || !st.Mode().IsRegular() {
			return "", "", fmt.Errorf("certificate %s of %s is not on this server", id, user)
		}
	}
	return crt, key, nil
}

// Prune removes the account's certificates not in keep, once they are a day
// old: one issued a moment ago may not be named by web.apply yet. Keys of
// requests (CSRs) are kept 90 days, for the certificate to come back.
func (s *Store) Prune(user string, keep map[string]bool) {
	dir, err := s.userDir(user)
	if err != nil {
		return
	}
	entries, _ := os.ReadDir(dir)
	for _, e := range entries {
		name := e.Name()
		info, err := e.Info()
		if err != nil || !info.Mode().IsRegular() {
			continue
		}
		age := s.now().Sub(info.ModTime())
		id := strings.TrimSuffix(strings.TrimSuffix(name, ".crt"), ".key")
		switch {
		case strings.HasPrefix(name, "csr-"):
			if age > 90*24*time.Hour {
				os.Remove(filepath.Join(dir, name))
			}
		case ValidID(id) && !keep[id] && age > 24*time.Hour:
			os.Remove(filepath.Join(dir, name))
		}
	}
}

// RemoveUser removes every certificate and key of the account.
func (s *Store) RemoveUser(user string) error {
	dir, err := s.userDir(user)
	if err != nil {
		return err
	}
	return os.RemoveAll(dir)
}

// SaveRequestKey keeps the key of a certificate request (CSR), for the
// certificate the owner brings back later.
func (s *Store) SaveRequestKey(user string, key crypto.Signer) (string, error) {
	dir, err := s.ensure(user)
	if err != nil {
		return "", err
	}
	der, err := x509.MarshalPKIXPublicKey(key.Public())
	if err != nil {
		return "", err
	}
	sum := sha256.Sum256(der)
	id := hex.EncodeToString(sum[:])[:16]
	text, err := KeyPEM(key)
	if err != nil {
		return "", err
	}
	return id, WriteAtomic(filepath.Join(dir, "csr-"+id+".key"), text, 0o600)
}

// RequestKey finds the key of a request made here for the public key pub.
func (s *Store) RequestKey(user string, pub crypto.PublicKey) (crypto.Signer, error) {
	dir, err := s.userDir(user)
	if err != nil {
		return nil, err
	}
	der, err := x509.MarshalPKIXPublicKey(pub)
	if err != nil {
		return nil, protocol.Fail(CodeNoKey, "no key was given, and the certificate's key is of a kind LPanel does not make")
	}
	sum := sha256.Sum256(der)
	text, err := os.ReadFile(filepath.Join(dir, "csr-"+hex.EncodeToString(sum[:])[:16]+".key"))
	if errors.Is(err, os.ErrNotExist) {
		return nil, protocol.Fail(CodeNoKey, "no key was given, and no certificate request made here has this certificate's key; paste the private key too")
	}
	if err != nil {
		return nil, err
	}
	return ParseKey(string(text))
}

// WriteAtomic writes a file through a temporary one in the same folder.
func WriteAtomic(path string, content []byte, mode os.FileMode) error {
	tmp, err := os.CreateTemp(filepath.Dir(path), ".lpa-")
	if err != nil {
		return err
	}
	name := tmp.Name()
	_, werr := tmp.Write(content)
	if cerr := tmp.Close(); werr == nil {
		werr = cerr
	}
	if werr == nil {
		werr = os.Chmod(name, mode)
	}
	if werr == nil {
		werr = os.Rename(name, path)
	}
	if werr != nil {
		os.Remove(name)
	}
	return werr
}
