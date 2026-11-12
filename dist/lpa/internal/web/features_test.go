package web

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"lpa/internal/protocol"
)

const hash = "$2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234"

func featured() ApplyArgs {
	a := shop("83")
	a.Sites[0].Canonical = "www"
	a.Sites[0].Redirects = []Redirect{
		{Path: "/old", To: "https://shop.example.test/new", Code: 301},
		{Path: "/old/deeper", To: "https://elsewhere.test/x?a=1", Code: 302},
	}
	a.Sites[0].ErrorPages = map[string]string{"404": "/errors/404.html", "500": "/errors/500.html"}
	a.Sites[0].Protected = []Protected{
		{Path: "", Realm: "Staff only", Users: []User{{Name: "ann", Hash: hash}}},
		{Path: "admin/area", Realm: "Admin", Users: []User{{Name: "bob", Hash: hash}, {Name: "cy", Hash: hash}}},
	}
	a.Sites[0].Hotlink = &Hotlink{Extensions: []string{"jpg", "png"}, Allow: []string{"friend.test"}}
	a.PHPSettings = map[string]map[string]string{"83": {
		"memory_limit": "256M", "display_errors": "On", "date.timezone": "Asia/Dhaka",
		"error_reporting": "E_ALL & ~E_DEPRECATED",
	}}
	return a
}

func TestFeaturesAreRendered(t *testing.T) {
	m, _, _, root := setup(t)
	if _, err := m.Apply(context.Background(), featured()); err != nil {
		t.Fatal(err)
	}
	site := read(t, m.siteFile("shop1"))
	docroot := filepath.Join(root, "home", "shop1") + "/public_html"
	for _, want := range []string{
		`RewriteCond %{HTTP_HOST} ^shop\.example\.test$ [NC]`,
		`RewriteRule ^ %{REQUEST_SCHEME}://www.shop.example.test%{REQUEST_URI} [R=301,L]`,
		`Redirect 302 "/old/deeper" "https://elsewhere.test/x?a=1"` + "\n    Redirect 301 \"/old\"",
		`RewriteCond %{HTTP_REFERER} !^https?://www\.shop\.example\.test(:[0-9]+)?(/|$) [NC]`,
		`RewriteCond %{HTTP_REFERER} !^https?://([^/.]+\.)+shop\.example\.test(:[0-9]+)?(/|$) [NC]`,
		`RewriteCond %{HTTP_REFERER} !^https?://friend\.test(:[0-9]+)?(/|$) [NC]`,
		`RewriteRule \.(jpg|png)$ - [F,NC]`,
		"ErrorDocument 404 /errors/404.html\n    ErrorDocument 500 /errors/500.html",
		`<Directory "` + docroot + `/admin/area">`,
		`AuthName "Staff only"`,
		"Require valid-user",
	} {
		if !strings.Contains(site, want) {
			t.Errorf("the site file lacks %q:\n%s", want, site)
		}
	}
	// The whole-site password comes after the site's own "Require all
	// granted", or that would undo it.
	granted := strings.Index(site, "Require all granted")
	protected := strings.Index(site, `<Directory "`+docroot+`">`+"\n        AuthType Basic")
	if protected < granted {
		t.Fatalf("the protected block is not after the site's own:\n%s", site)
	}

	if files := htpasswdIn(m.Htpasswd, "shop1"); len(files) != 2 {
		t.Fatalf("login files %v", files)
	}
	logins := read(t, m.htpasswdFile("shop1", "shop.example.test", "admin/area"))
	if logins != "bob:"+hash+"\ncy:"+hash+"\n" {
		t.Fatalf("logins %q", logins)
	}
	if !strings.Contains(site, `AuthUserFile "`+m.htpasswdFile("shop1", "shop.example.test", "admin/area")+`"`) {
		t.Fatal("the site does not point at its login file")
	}

	pool := read(t, m.poolFile("83", "shop1"))
	for _, want := range []string{
		"php_value[memory_limit] = 256M",
		"php_flag[display_errors] = On",
		"php_value[date.timezone] = Asia/Dhaka",
		`php_value[error_reporting] = "E_ALL & ~E_DEPRECATED"`,
	} {
		if !strings.Contains(pool, want) {
			t.Errorf("the pool lacks %q:\n%s", want, pool)
		}
	}
}

func TestChangingOnlyAPasswordReloadsNothing(t *testing.T) {
	m, run, _, _ := setup(t)
	a := featured()
	if _, err := m.Apply(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	run.calls = nil
	a.Sites[0].Protected[1].Users = a.Sites[0].Protected[1].Users[:1]
	res, err := m.Apply(context.Background(), a)
	if err != nil || !res.Changed {
		t.Fatalf("%+v %v", res, err)
	}
	if run.ran("systemctl") != 0 || run.ran("httpd") != 0 {
		t.Fatalf("a password change reloaded or tested: %v", run.calls)
	}
	if got := read(t, m.htpasswdFile("shop1", "shop.example.test", "admin/area")); got != "bob:"+hash+"\n" {
		t.Fatalf("logins %q", got)
	}

	// Unprotecting removes the file; removing the account removes the rest.
	a.Sites[0].Protected = a.Sites[0].Protected[:1]
	if _, err := m.Apply(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(m.htpasswdFile("shop1", "shop.example.test", "admin/area")); !os.IsNotExist(err) {
		t.Fatal("the login file of an unprotected folder stayed")
	}
	if _, err := m.Remove(context.Background(), NameArgs{Username: "shop1"}); err != nil {
		t.Fatal(err)
	}
	if left := htpasswdIn(m.Htpasswd, "shop1"); len(left) != 0 {
		t.Fatalf("login files left: %v", left)
	}
}

func TestFeatureRefusals(t *testing.T) {
	m, run, _, _ := setup(t)
	user := []User{{Name: "a", Hash: hash}}
	redirect := func(path, to string, code int) []Redirect { return []Redirect{{Path: path, To: to, Code: code}} }
	php := func(k, v string) map[string]map[string]string { return map[string]map[string]string{"83": {k: v}} }
	for name, change := range map[string]func(a *ApplyArgs){
		"a redirect with a space":     func(a *ApplyArgs) { a.Sites[0].Redirects = redirect("/a b", "https://x.test/", 301) },
		"a redirect with a quote":     func(a *ApplyArgs) { a.Sites[0].Redirects = redirect("/a", `https://x.test/"`, 301) },
		"a redirect with a newline":   func(a *ApplyArgs) { a.Sites[0].Redirects = redirect("/a", "https://x.test/\nInclude /etc", 301) },
		"a redirect that is not http": func(a *ApplyArgs) { a.Sites[0].Redirects = redirect("/a", "javascript:alert(1)", 301) },
		"a redirect code of 200":      func(a *ApplyArgs) { a.Sites[0].Redirects = redirect("/a", "https://x.test/", 200) },
		"a path redirected twice": func(a *ApplyArgs) {
			a.Sites[0].Redirects = append(redirect("/a", "https://x.test/", 301), redirect("/a", "https://y.test/", 302)...)
		},
		"an error page for 302":       func(a *ApplyArgs) { a.Sites[0].ErrorPages = map[string]string{"302": "/x.html"} },
		"an error page that is text":  func(a *ApplyArgs) { a.Sites[0].ErrorPages = map[string]string{"404": `"Gone"`} },
		"an error page with ..":       func(a *ApplyArgs) { a.Sites[0].ErrorPages = map[string]string{"404": "/../../etc/passwd"} },
		"a protected folder going up": func(a *ApplyArgs) { a.Sites[0].Protected = []Protected{{Path: "../x", Realm: "R", Users: user}} },
		"a realm with a quote":        func(a *ApplyArgs) { a.Sites[0].Protected = []Protected{{Realm: `R"`, Users: user}} },
		"a login with a colon": func(a *ApplyArgs) {
			a.Sites[0].Protected = []Protected{{Realm: "R", Users: []User{{Name: "a:b", Hash: hash}}}}
		},
		"a password that is not a hash": func(a *ApplyArgs) {
			a.Sites[0].Protected = []Protected{{Realm: "R", Users: []User{{Name: "a", Hash: "secret"}}}}
		},
		"a hotlink kind with a dot":         func(a *ApplyArgs) { a.Sites[0].Hotlink = &Hotlink{Extensions: []string{"j.pg"}} },
		"hotlink protection of nothing":     func(a *ApplyArgs) { a.Sites[0].Hotlink = &Hotlink{} },
		"www. for a site without it":        func(a *ApplyArgs) { a.Sites[1].Canonical = "www" },
		"a php.ini setting not offered":     func(a *ApplyArgs) { a.PHPSettings = php("disable_functions", "") },
		"a php.ini value with a newline":    func(a *ApplyArgs) { a.PHPSettings = php("memory_limit", "256M\nphp_admin_value[x]=1") },
		"a php.ini flag that is not a flag": func(a *ApplyArgs) { a.PHPSettings = php("display_errors", "stderr") },
	} {
		a := shop("83")
		change(&a)
		if _, err := m.Apply(context.Background(), a); code(err) != protocol.CodeBadArgs {
			t.Errorf("%s: got %v", name, err)
		}
	}
	if len(run.calls) != 0 {
		t.Fatalf("a refused request ran %v", run.calls)
	}
}
