package web

import (
	"context"
	"strings"
	"testing"
)

// mailShop is shop() with webmail and mail clients' setup on its main site.
func mailShop() ApplyArgs {
	a := shop("83")
	a.WebmailURL = "https://panel.example.test:7444/webmail/"
	a.Sites[0].Aliases = append(a.Sites[0].Aliases, "mail.shop.example.test", "autoconfig.shop.example.test", "webmail.shop.example.test")
	a.Sites[0].MailSetup = true
	a.Sites[0].Webmail = []string{"webmail.shop.example.test"}
	return a
}

func TestMailSetupAndWebmailOnEveryStack(t *testing.T) {
	for _, server := range []string{"apache", "nginx", "hybrid"} {
		t.Run(server, func(t *testing.T) {
			m, _, _, _ := stack(t, server)
			a := mailShop()
			a.Sites[0].Canonical = "www"
			a.Sites[0].Redirects = []Redirect{{Path: "/", To: "https://elsewhere.test/", Code: 301}}
			if _, err := m.Apply(context.Background(), a); err != nil {
				t.Fatal(err)
			}
			if server != "nginx" {
				site := read(t, m.siteFile("shop1"))
				mustContain(t, "the Apache file", site,
					"    RewriteCond %{HTTP_HOST} =webmail.shop.example.test [NC]\n    RewriteRule ^ https://panel.example.test:7444/webmail/ [R=302,L]\n",
					"    ProxyPreserveHost On\n",
					`RewriteRule ^/(\.well-known/autoconfig/)?mail/config-v1\.1\.xml$ http://127.0.0.1:7449/mail-setup/autoconfig [P,NC,L]`,
					`RewriteRule ^/autodiscover/autodiscover\.xml$ http://127.0.0.1:7449/mail-setup/autodiscover [P,NC,L]`,
				)
				// Before the owner's own rules: a redirect of the whole site
				// must not take the mail clients away.
				if strings.Index(site, "mail-setup/autodiscover") > strings.Index(site, `Redirect 301 "/"`) {
					t.Error("the owner's redirect comes first")
				}
				// The blog has no mail of its own.
				blog := site[strings.Index(site, "ServerName blog.shop.example.test"):]
				if strings.Contains(blog, "mail-setup") || strings.Contains(blog, "webmail") {
					t.Error("a site without mail setup got the rules")
				}
			}
			if server != "apache" {
				site := read(t, m.nginxFile("shop1"))
				mustContain(t, "the nginx file", site,
					"    if ($host ~* \"^(webmail\\.shop\\.example\\.test)$\") {\n        return 302 https://panel.example.test:7444/webmail/;\n    }\n",
					"    if ($uri ~* \"^/((\\.well-known/autoconfig/)?mail/config-v1\\.1\\.xml|autodiscover/autodiscover\\.xml)$\") {\n        break;\n    }\n",
					"proxy_pass http://127.0.0.1:7449/mail-setup/autoconfig$is_args$args;",
					"proxy_pass http://127.0.0.1:7449/mail-setup/autodiscover$is_args$args;",
				)
				// Ahead of the www. choice and the site's own regex locations.
				at := strings.Index(site, "mail-setup/autodiscover")
				if at > strings.Index(site, "return 301 $scheme://www.") || at > strings.Index(site, "location ~ /\\.") {
					t.Error("the mail locations come after the site's own rules")
				}
			}
		})
	}
}

func TestMailSetupRefusals(t *testing.T) {
	m, _, _, _ := setup(t)
	for name, change := range map[string]func(*ApplyArgs){
		"a webmail name that is not the site's": func(a *ApplyArgs) { a.Sites[0].Webmail = []string{"webmail.other.test"} },
		"a wildcard webmail name":               func(a *ApplyArgs) { a.Sites[0].Webmail = []string{"*.shop.example.test"} },
		"webmail without its address":           func(a *ApplyArgs) { a.WebmailURL = "" },
		"webmail somewhere plain":               func(a *ApplyArgs) { a.WebmailURL = "http://panel.example.test/webmail/" },
		"an address that breaks out":            func(a *ApplyArgs) { a.WebmailURL = "https://x.test/webmail/ [L]" },
	} {
		a := mailShop()
		change(&a)
		if _, err := m.Apply(context.Background(), a); code(err) != "bad_args" {
			t.Errorf("%s: %v", name, err)
		}
	}
}
