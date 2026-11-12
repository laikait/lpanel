package web

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func stack(t *testing.T, server string) (*Manager, *fakeRun, *probeLog, string) {
	t.Helper()
	m, run, probes, root := setup(t)
	m.Server = server
	if server == "nginx" {
		m.WebGroup = "nginx"
	}
	return m, run, probes, root
}

func mustContain(t *testing.T, what, text string, wants ...string) {
	t.Helper()
	for _, want := range wants {
		if !strings.Contains(text, want) {
			t.Errorf("%s lacks %q:\n%s", what, want, text)
		}
	}
}

func TestNginxAloneServesEverySiteWithItsFeatures(t *testing.T) {
	m, run, probes, root := stack(t, "nginx")
	if _, err := m.Apply(context.Background(), featured()); err != nil {
		t.Fatal(err)
	}
	site := read(t, m.nginxFile("shop1"))
	docroot := filepath.Join(root, "home", "shop1") + "/public_html"
	staff := m.htpasswdFile("shop1", "shop.example.test", "")
	admin := m.htpasswdFile("shop1", "shop.example.test", "admin/area")
	mustContain(t, "the nginx file", site,
		"server_name shop.example.test www.shop.example.test *.shop.example.test;",
		`root "`+docroot+`";`,
		"disable_symlinks if_not_owner from=$document_root;",
		"add_header X-LPanel-Revision \"",
		// The www. choice first, then redirects longest first, then hotlink.
		"if ($host = \"shop.example.test\") {\n        return 301 $scheme://www.shop.example.test$request_uri;",
		`rewrite "^/old/deeper(/.*)?$" "https://elsewhere.test/x?a=1$1" redirect;`+"\n    "+`rewrite "^/old(/.*)?$" "https://shop.example.test/new$1" permanent;`,
		"valid_referers none shop.example.test www.shop.example.test *.shop.example.test friend.test;",
		`if ($uri ~* "\.(jpg|png)$")`,
		`if ($lpanel_hotlink = "xy") {`,
		"error_page 404 /errors/404.html;\n    error_page 500 /errors/500.html;",
		// The whole site, and a folder with its own logins and its own PHP.
		`auth_basic "Staff only";`+"\n    "+`auth_basic_user_file "`+staff+`";`,
		"location ^~ /admin/area/ {\n        auth_basic \"Admin\";\n        auth_basic_user_file \""+admin+"\";",
		"        location ~ \\.php$ {",
		"fastcgi_pass unix:"+m.socket("83", "shop1")+";",
		"location ~ /\\.(?!well-known/) {",
		"try_files $uri $uri/ @app;",
		"rewrite ^ /index.php last;",
	)
	if strings.Contains(site, "proxy_pass") {
		t.Error("nginx alone passes nothing to Apache")
	}
	// Its logins are with nginx, readable by nginx's group.
	if _, err := os.Stat(admin); err != nil || !strings.HasPrefix(admin, m.NginxHtpasswd) {
		t.Fatalf("logins: %s %v", admin, err)
	}
	if _, err := os.Stat(m.siteFile("shop1")); !os.IsNotExist(err) {
		t.Error("an Apache file was written")
	}
	mustContain(t, "the pool", read(t, m.poolFile("83", "shop1")), "listen.group = nginx")
	mustContain(t, "the include", read(t, m.NginxInclude), "log_format lpanel '$host $remote_addr", "listen 80 default_server;", "include "+m.NginxSites+"/*.conf;", `geo $lpanel_dollar { default "$"; }`)
	if run.ran("nginx -t -q -c ") != 1 || run.ran("systemctl reload-or-restart nginx") != 1 || run.ran("httpd") != 0 || run.ran("systemctl reload-or-restart httpd") != 0 {
		t.Fatalf("%v", run.calls)
	}
	if len(probes.asked) != 2 {
		t.Fatalf("probes: %v", probes.asked)
	}
}

func TestNginxTestsTheCandidateAmongEveryOtherAccount(t *testing.T) {
	m, run, _, _ := stack(t, "nginx")
	os.MkdirAll(m.NginxSites, 0o755)
	os.WriteFile(filepath.Join(m.NginxSites, "other.conf"), []byte("# other\n"), 0o644)
	var staged []string
	pools := run.after
	run.after = func(call string) {
		pools(call)
		if conf, ok := strings.CutPrefix(call, "nginx -t -q -c "); ok {
			main := read(t, conf)
			staged, _ = filepath.Glob(filepath.Join(filepath.Dir(conf), "sites", "*.conf"))
			mustContain(t, "the test configuration", main, "events {}", "/mime.types;", "/conf.d/*.conf;")
			if strings.Contains(main, filepath.ToSlash(filepath.Dir(m.NginxInclude))) {
				t.Errorf("the test reads the live conf.d:\n%s", main)
			}
		}
	}
	if _, err := m.Apply(context.Background(), shop("83")); err != nil {
		t.Fatal(err)
	}
	if len(staged) != 2 {
		t.Fatalf("staged %v", staged)
	}

	// With the server's own nginx.conf, the test is that file, whole, and
	// every other file of its conf.d: something broken there stops a change.
	etc := filepath.Dir(filepath.Dir(m.NginxInclude))
	os.WriteFile(filepath.Join(etc, "nginx.conf"), []byte("user nginx;\nevents {}\nhttp {\n    include "+filepath.ToSlash(filepath.Dir(m.NginxInclude))+"/*.conf;\n}\n"), 0o644)
	os.WriteFile(filepath.Join(filepath.Dir(m.NginxInclude), "zz-other.conf"), []byte("# someone else's\n"), 0o644)
	var copied bool
	run.after = func(call string) {
		pools(call)
		if conf, ok := strings.CutPrefix(call, "nginx -t -q -c "); ok {
			mustContain(t, "the test configuration", read(t, conf), "user nginx;", filepath.ToSlash(filepath.Dir(conf))+"/conf.d/*.conf;")
			_, err := os.Stat(filepath.Join(filepath.Dir(conf), "conf.d", "zz-other.conf"))
			copied = err == nil
		}
	}
	b := shop("83")
	b.Sites[1].Docroot = "public_html/journal"
	if _, err := m.Apply(context.Background(), b); err != nil || !copied {
		t.Fatalf("%v, copied %v", err, copied)
	}

	// A refused candidate changes nothing live.
	run.fail["nginx -t"] = "nginx: [emerg] unknown directive"
	before := read(t, m.nginxFile("shop1"))
	a := shop("83")
	a.Sites[1].Docroot = "public_html/news"
	if _, err := m.Apply(context.Background(), a); code(err) != CodeRejected {
		t.Fatalf("%v", err)
	}
	if read(t, m.nginxFile("shop1")) != before {
		t.Fatal("the live file changed")
	}
}

func TestHybridPutsNginxInFrontOfApache(t *testing.T) {
	m, run, probes, root := stack(t, "hybrid")
	if _, err := m.Apply(context.Background(), featured()); err != nil {
		t.Fatal(err)
	}
	apache := read(t, m.siteFile("shop1"))
	nginx := read(t, m.nginxFile("shop1"))
	mustContain(t, "the Apache file", apache, "<VirtualHost *:8080>", "X-LPanel-Revision", "SetHandler \"proxy:unix:", "AuthName \"Admin\"")
	if strings.Contains(apache, "CustomLog") {
		t.Error("Apache logs requests nginx has logged already")
	}
	mustContain(t, "the nginx file", nginx,
		"add_header X-LPanel-Front \"",
		"location / {\n        proxy_pass http://127.0.0.1:8080;",
		"location ^~ /admin/area/ {\n        proxy_pass http://127.0.0.1:8080;",
		"location @apache {",
		// The www. choice, redirects and hotlink apply to static files too.
		"return 301 $scheme://www.shop.example.test$request_uri;",
		`rewrite "^/old(/.*)?$"`,
		"valid_referers none",
	)
	// With the whole site protected, nothing of it is served without Apache.
	first := strings.SplitN(nginx, "\nserver {", 3)[1]
	if strings.Contains(first, "try_files $uri @apache") || strings.Contains(nginx, "auth_basic") || strings.Contains(nginx, "fastcgi_pass") {
		t.Errorf("nginx serves something itself:\n%s", nginx)
	}
	// Each file names the same revision.
	rev := strings.TrimSuffix(strings.SplitN(strings.SplitN(apache, "revision ", 2)[1], ".", 2)[0], "")
	if !strings.Contains(nginx, "revision "+rev+".") || !strings.Contains(nginx, `"`+rev+`"`) {
		t.Fatalf("revision %s not in the nginx file", rev)
	}
	mustContain(t, "the Apache include", read(t, m.Include), "<VirtualHost *:8080>", "RemoteIPHeader X-Forwarded-For")
	mustContain(t, "the pool", read(t, m.poolFile("83", "shop1")), "listen.group = apache")
	if !strings.HasPrefix(m.htpasswdFile("shop1", "shop.example.test", ""), m.Htpasswd) {
		t.Error("Apache checks the passwords, so the logins are Apache's")
	}
	// Apache is reloaded before nginx in front of it.
	var order []string
	for _, c := range run.calls {
		if strings.HasPrefix(c, "systemctl reload-or-restart httpd") || strings.HasPrefix(c, "systemctl reload-or-restart nginx") {
			order = append(order, c)
		}
	}
	if len(order) != 2 || !strings.HasSuffix(order[0], "httpd") || run.ran("httpd -t") != 1 || run.ran("nginx -t") != 1 {
		t.Fatalf("%v", run.calls)
	}
	if len(probes.asked) == 0 {
		t.Fatal("no probe")
	}

	// Without a whole-site password, static files are nginx's own.
	a := shop("83")
	if _, err := m.Apply(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	mustContain(t, "the nginx file", read(t, m.nginxFile("shop1")), "try_files $uri @apache;", "root \""+filepath.Join(root, "home", "shop1")+"/public_html\";")
}

func TestSwitchingStacksLeavesNothingOfTheOldOne(t *testing.T) {
	m, run, _, _ := setup(t)
	if _, err := m.Apply(context.Background(), featured()); err != nil {
		t.Fatal(err)
	}
	old := htpasswdIn(m.Htpasswd, "shop1")
	if len(old) != 2 {
		t.Fatalf("%v", old)
	}

	m.Server, m.WebGroup = "nginx", "nginx"
	calls := len(run.calls)
	if _, err := m.Apply(context.Background(), featured()); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(m.siteFile("shop1")); !os.IsNotExist(err) {
		t.Error("the Apache file is still there")
	}
	if left := htpasswdIn(m.Htpasswd, "shop1"); len(left) != 0 {
		t.Errorf("Apache's logins are still there: %v", left)
	}
	if moved := htpasswdIn(m.NginxHtpasswd, "shop1"); len(moved) != 2 {
		t.Errorf("nginx's logins: %v", moved)
	}
	for _, c := range run.calls[calls:] {
		if strings.HasPrefix(c, "httpd") || strings.HasSuffix(c, " httpd") {
			t.Errorf("Apache, which this stack does not run, was touched: %s", c)
		}
	}
	// The pools' sockets now belong to nginx's group, which only a restart
	// applies (a reload keeps the old sockets).
	if run.ran("systemctl restart php83-php-fpm") != 1 {
		t.Errorf("PHP 8.3 was not restarted: %v", run.calls[calls:])
	}
	before := run.ran("systemctl restart php83-php-fpm")
	a := featured()
	a.Sites[0].Docroot = "public_html/shop"
	if _, err := m.Apply(context.Background(), a); err != nil || run.ran("systemctl restart php83-php-fpm") != before {
		t.Errorf("an ordinary change restarted PHP: %v", err)
	}

	// Removing the account removes it from both, and touches only nginx.
	if _, err := m.Remove(context.Background(), NameArgs{Username: "shop1"}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(m.nginxFile("shop1")); !os.IsNotExist(err) {
		t.Error("the nginx file is still there")
	}
}

func TestRedirectPatternsKeepTheRestOfThePath(t *testing.T) {
	for path, want := range map[string]string{
		"/":           "^/(.*)$",
		"/old":        `^/old(/.*)?$`,
		"/old/":       `^/old/(.*)$`,
		"/a.b+c$d(e)": `^/a\.b\+c\$d\(e\)(/.*)?$`,
	} {
		if got := redirectPattern(path); got != want {
			t.Errorf("%s: %s, not %s", path, got, want)
		}
	}
	if got := nginxLiteral("https://x.test/a$b?c=$1"); got != "https://x.test/a${lpanel_dollar}b?c=${lpanel_dollar}1" {
		t.Error(got)
	}
}

func TestALimitedOrSuspendedSiteSaysWhyInNginxToo(t *testing.T) {
	m, _, _, _ := stack(t, "nginx")
	a := shop("83")
	a.Suspended = true
	if _, err := m.Apply(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	site := read(t, m.nginxFile("shop1"))
	mustContain(t, "the nginx file", site, `return 503 "This site is suspended.";`)
	if strings.Contains(site, "fastcgi_pass") {
		t.Error("a suspended site runs PHP")
	}
}
