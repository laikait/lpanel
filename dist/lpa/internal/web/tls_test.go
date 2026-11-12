package web

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// withCerts gives m a certificate store in root/pki that holds ids, and
// records what is pruned.
func withCerts(m *Manager, root string, ids ...string) *[]map[string]bool {
	dir := filepath.Join(root, "pki", "shop1")
	os.MkdirAll(dir, 0o700)
	for _, id := range ids {
		os.WriteFile(filepath.Join(dir, id+".crt"), []byte("crt"), 0o600)
		os.WriteFile(filepath.Join(dir, id+".key"), []byte("key"), 0o600)
	}
	m.CertFiles = func(user, id string) (string, string, error) {
		crt, key := filepath.Join(root, "pki", user, id+".crt"), filepath.Join(root, "pki", user, id+".key")
		if _, err := os.Stat(crt); err != nil {
			return "", "", errors.New("certificate " + id + " is not on this server")
		}
		return crt, key, nil
	}
	pruned := &[]map[string]bool{}
	m.CertPrune = func(_ string, keep map[string]bool) { *pruned = append(*pruned, keep) }
	return pruned
}

const certA = "0123456789abcdef"

func secured(force bool) ApplyArgs {
	a := shop("83")
	a.Sites[0].TLS = &SiteTLS{Cert: certA, Force: force}
	return a
}

func TestApacheServesHTTPS(t *testing.T) {
	m, _, probes, root := setup(t)
	var tlsAsked []string
	m.ProbeTLS = func(_ context.Context, host, rev string) error {
		tlsAsked = append(tlsAsked, host+"@"+rev)
		return nil
	}
	pruned := withCerts(m, root, certA)
	res, err := m.Apply(context.Background(), secured(true))
	if err != nil {
		t.Fatal(err)
	}
	site := read(t, m.siteFile("shop1"))
	crt := filepath.Join(root, "pki", "shop1", certA+".crt")
	// Port 80 answers the checks, then sends everyone to HTTPS.
	mustContain(t, "the Apache file", site,
		"<VirtualHost *:80>\n    ServerName shop.example.test",
		"    RewriteEngine On\n    RewriteRule ^/\\.well-known/acme-challenge/([A-Za-z0-9_-]+)$ "+m.Challenges+"/$1 [END]\n    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]\n</VirtualHost>",
		"<VirtualHost *:443>\n    ServerName shop.example.test\n    ServerAlias www.shop.example.test *.shop.example.test",
		"    SSLEngine on\n    SSLCertificateFile \""+crt+"\"",
	)
	// The site without a certificate is plain HTTP, and answers checks too.
	if strings.Count(site, "<VirtualHost *:443>") != 1 || strings.Count(site, "acme-challenge") != 2 {
		t.Errorf("the Apache file:\n%s", site)
	}
	include := read(t, m.Include)
	mustContain(t, "the include", include, "Listen 443 https", "SSLCertificateFile \""+m.HostCert+"\"", "<Directory \""+m.Challenges+"\">")
	if strings.Contains(include, "SetEnvIf") {
		t.Error("Apache alone trusts X-Forwarded-Proto")
	}
	if st, err := os.Stat(m.Challenges); err != nil || !st.IsDir() {
		t.Error("the challenge folder was not made")
	}
	if len(tlsAsked) != 1 || tlsAsked[0] != "shop.example.test@"+res.Revision || len(probes.asked) != 2 {
		t.Errorf("probes: http %v, https %v", probes.asked, tlsAsked)
	}
	if len(*pruned) != 1 || !(*pruned)[0][certA] {
		t.Errorf("pruned = %v", *pruned)
	}

	// Not forced: port 80 serves the site too.
	if _, err := m.Apply(context.Background(), secured(false)); err != nil {
		t.Fatal(err)
	}
	site = read(t, m.siteFile("shop1"))
	if strings.Contains(site, "R=301") || strings.Count(site, "DocumentRoot") != 3 {
		t.Errorf("unforced Apache file:\n%s", site)
	}
}

func TestHTTPSNeedsItsCertificate(t *testing.T) {
	m, run, _, root := setup(t)
	withCerts(m, root)
	_, err := m.Apply(context.Background(), secured(true))
	if code(err) != CodeCertMissing || run.ran("systemctl") != 0 {
		t.Fatalf("err = %v, calls %v", err, run.calls)
	}
	a := secured(true)
	a.Sites[0].TLS.Cert = "../../etc/shadow"
	if _, err := m.Apply(context.Background(), a); code(err) != "bad_args" {
		t.Errorf("a path as a certificate id: %v", err)
	}
}

func TestNginxServesHTTPS(t *testing.T) {
	m, _, _, root := stack(t, "nginx")
	m.ProbeTLS = func(context.Context, string, string) error { return nil }
	withCerts(m, root, certA)
	if _, err := m.Apply(context.Background(), secured(true)); err != nil {
		t.Fatal(err)
	}
	site := read(t, m.nginxFile("shop1"))
	crt := filepath.Join(root, "pki", "shop1", certA+".crt")
	// The checks come before the move to HTTPS and the www. choice.
	mustContain(t, "the nginx file", site,
		"    listen 443 ssl http2;\n    listen [::]:443 ssl http2;\n    ssl_certificate \""+crt+"\";",
		"    if ($uri ~ \"^/\\.well-known/acme-challenge/\") {\n        break;\n    }\n    location ^~ /.well-known/acme-challenge/ {\n        alias "+m.Challenges+"/;",
		"    if ($scheme = \"http\") {\n        return 301 https://$host$request_uri;\n    }",
	)
	if strings.Count(site, "ssl_certificate ") != 1 || strings.Count(site, "location ^~ /.well-known/acme-challenge/") != 2 {
		t.Errorf("the nginx file:\n%s", site)
	}
	if strings.Index(site, "break;") > strings.Index(site, "$scheme = \"http\"") {
		t.Error("the move to HTTPS comes before the checks")
	}
	mustContain(t, "the nginx include", read(t, m.NginxInclude), "listen 443 ssl http2 default_server;", "ssl_reject_handshake on;", "location ^~ /.well-known/acme-challenge/")
}

func TestHybridServesHTTPSInFront(t *testing.T) {
	m, _, _, root := stack(t, "hybrid")
	m.ProbeTLS = func(context.Context, string, string) error { return nil }
	withCerts(m, root, certA)
	a := secured(true)
	a.Suspended = true
	if _, err := m.Apply(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	// nginx terminates HTTPS; Apache behind it has no 443 and no checks.
	apache := read(t, m.siteFile("shop1"))
	if strings.Contains(apache, ":443") || strings.Contains(apache, "SSLEngine") || strings.Contains(apache, "acme-challenge") {
		t.Errorf("Apache behind nginx:\n%s", apache)
	}
	include := read(t, m.Include)
	mustContain(t, "the Apache include", include, "SetEnvIf X-Forwarded-Proto \"^https$\" HTTPS=on")
	if strings.Contains(include, "Listen 443") {
		t.Error("Apache behind nginx listens on 443")
	}
	// A suspended site still answers the checks, before its 503.
	front := read(t, m.nginxFile("shop1"))
	mustContain(t, "the nginx file", front, "ssl_certificate", "break;", "return 503")
	if strings.Index(front, "break;") > strings.Index(front, "return 503") {
		t.Error("the suspension comes before the checks")
	}
}

func TestRemoveForgetsCertificates(t *testing.T) {
	m, _, _, _ := setup(t)
	var removed []string
	m.CertRemove = func(user string) error { removed = append(removed, user); return nil }
	if _, err := m.Apply(context.Background(), shop("83")); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Remove(context.Background(), NameArgs{Username: "shop1"}); err != nil {
		t.Fatal(err)
	}
	if strings.Join(removed, ",") != "shop1" {
		t.Errorf("removed = %v", removed)
	}
}
