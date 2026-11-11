package web

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"lpa/internal/protocol"
)

// fakeRun records the tools the pipeline runs and answers as told.
type fakeRun struct {
	calls []string
	fail  map[string]string // call prefix → output of a failure
	after func(call string)
}

func (f *fakeRun) Run(_ context.Context, tool string, args ...string) (int, string, error) {
	call := tool + " " + strings.Join(args, " ")
	f.calls = append(f.calls, call)
	for prefix, out := range f.fail {
		if strings.HasPrefix(call, prefix) {
			return 1, out, nil
		}
	}
	if f.after != nil {
		f.after(call)
	}
	return 0, "", nil
}

func (f *fakeRun) ran(prefix string) int {
	n := 0
	for _, c := range f.calls {
		if strings.HasPrefix(c, prefix) {
			n++
		}
	}
	return n
}

type probeLog struct {
	asked []string
	fail  bool
}

func setup(t *testing.T) (*Manager, *fakeRun, *probeLog, string) {
	t.Helper()
	root := t.TempDir()
	for _, v := range []string{"82", "83"} {
		bin := filepath.Join(root, "opt", "php"+v, "root", "usr", "sbin")
		os.MkdirAll(bin, 0o755)
		os.WriteFile(filepath.Join(bin, "php-fpm"), nil, 0o755)
		os.MkdirAll(filepath.Join(root, "etc-opt", "php"+v, "php-fpm.d"), 0o755)
		os.MkdirAll(filepath.Join(root, "var-opt", "php"+v, "run", "php-fpm"), 0o755)
	}
	homes := filepath.Join(root, "home")
	os.MkdirAll(filepath.Join(homes, "shop1"), 0o711)
	run := &fakeRun{fail: map[string]string{}}
	probes := &probeLog{}
	m := &Manager{
		Include:  filepath.Join(root, "httpd", "conf.d", "lpanel.conf"),
		Sites:    filepath.Join(root, "httpd", "lpanel", "sites"),
		Htpasswd: filepath.Join(root, "httpd", "lpanel", "htpasswd"),
		Chown:    func(string, int, int) error { return nil },
		LogDir:   filepath.Join(root, "log"),
		PHPRoot:  filepath.Join(root, "opt"),
		PHPConf:  filepath.Join(root, "etc-opt"),
		PHPRun:   filepath.Join(root, "var-opt"),
		Run:      run,
		Log:      slog.New(slog.NewTextHandler(io.Discard, nil)),
		ProbeFor: 300 * time.Millisecond,
		Owner: func(name string) (int, int, string, error) {
			if name != "shop1" {
				return 0, 0, "", protocol.Fail("not_ours", name+" was not made by LPanel")
			}
			return 1001, 1001, filepath.Join(homes, name), nil
		},
		GroupID: func(string) (int, error) { return 48, nil },
		MakeDirs: func(home string, roots []string, _, _, _ int) ([]string, error) {
			var made []string
			for _, r := range roots {
				if _, err := os.Stat(filepath.Join(home, r)); err != nil {
					made = append(made, filepath.Join(home, r))
				}
				os.MkdirAll(filepath.Join(home, r), 0o755)
			}
			return made, nil
		},
		Probe: func(_ context.Context, host, rev string) error {
			probes.asked = append(probes.asked, host+"@"+rev)
			if probes.fail {
				return errors.New("no answer")
			}
			return nil
		},
	}
	m.Defaults()
	// PHP-FPM opens a socket for each pool it has when it reloads.
	run.after = func(call string) {
		if v, ok := strings.CutPrefix(call, "systemctl reload-or-restart php"); ok {
			v = strings.TrimSuffix(v, "-php-fpm")
			if _, err := os.Stat(m.poolFile(v, "shop1")); err == nil {
				os.WriteFile(m.socket(v, "shop1"), nil, 0o660)
			}
		}
	}
	os.MkdirAll(filepath.Join(root, "httpd", "conf"), 0o755)
	os.MkdirAll(filepath.Join(root, "httpd", "conf.d"), 0o755)
	os.WriteFile(filepath.Join(root, "httpd", "conf", "httpd.conf"), nil, 0o644)
	// Another account is already served; its file must survive everything.
	os.MkdirAll(m.Sites, 0o755)
	os.WriteFile(filepath.Join(m.Sites, "other.conf"), []byte("# other\n"), 0o644)
	return m, run, probes, root
}

func shop(php string) ApplyArgs {
	return ApplyArgs{
		Username: "shop1",
		Sites: []Site{
			{Name: "shop.example.test", Aliases: []string{"www.shop.example.test", "*.shop.example.test"}, Docroot: "public_html", PHP: php},
			{Name: "blog.shop.example.test", Docroot: "public_html/blog", PHP: php},
		},
	}
}

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

func read(t *testing.T, p string) string {
	t.Helper()
	b, err := os.ReadFile(p)
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

func TestRefusals(t *testing.T) {
	m, run, _, _ := setup(t)
	site := func(name, root, php string, aliases ...string) ApplyArgs {
		return ApplyArgs{Username: "shop1", Sites: []Site{{Name: name, Aliases: aliases, Docroot: root, PHP: php}}}
	}
	for name, tc := range map[string]struct {
		args ApplyArgs
		code string
	}{
		"a bad username":            {ApplyArgs{Username: "Root", Sites: shop("83").Sites}, protocol.CodeBadArgs},
		"no sites":                  {ApplyArgs{Username: "shop1"}, protocol.CodeBadArgs},
		"a name that is not a host": {site("localhost", "public_html", "83"), protocol.CodeBadArgs},
		"a wildcard site name":      {site("*.example.test", "public_html", "83"), protocol.CodeBadArgs},
		"a name with a newline":     {site("a.test\nInclude /etc/shadow", "public_html", "83"), protocol.CodeBadArgs},
		"a name twice":              {site("a.test", "public_html", "83", "a.test"), protocol.CodeBadArgs},
		"a root outside the home":   {site("a.test", "../other/public_html", "83"), protocol.CodeBadArgs},
		"an absolute root":          {site("a.test", "/etc", "83"), protocol.CodeBadArgs},
		"a hidden root":             {site("a.test", ".ssh", "83"), protocol.CodeBadArgs},
		"a root in tmp":             {site("a.test", "tmp/site", "83"), protocol.CodeBadArgs},
		"a root beside public_html": {site("a.test", "site", "83"), protocol.CodeBadArgs},
		"a root with a quote":       {site("a.test", `public_html/"x`, "83"), protocol.CodeBadArgs},
		"a bad PHP version":         {site("a.test", "public_html", "8.3"), protocol.CodeBadArgs},
		"a PHP version not here":    {site("a.test", "public_html", "74"), CodePHPMissing},
		"someone else's user":       {ApplyArgs{Username: "root1", Sites: shop("83").Sites}, "not_ours"},
	} {
		if _, err := m.Apply(context.Background(), tc.args); code(err) != tc.code {
			t.Errorf("%s: got %q, want %q", name, code(err), tc.code)
		}
	}
	if len(run.calls) != 0 {
		t.Fatalf("a refused request ran %v", run.calls)
	}
}

func TestApplyTestsSwapsReloadsAndProbes(t *testing.T) {
	m, run, probes, root := setup(t)
	res, err := m.Apply(context.Background(), shop("83"))
	if err != nil {
		t.Fatal(err)
	}
	if !res.Changed || res.Sites != 2 || strings.Join(res.PHP, ",") != "83" || len(res.Revision) != 16 {
		t.Fatalf("result %+v", res)
	}

	site := read(t, m.siteFile("shop1"))
	home := filepath.Join(root, "home", "shop1")
	for _, want := range []string{
		"ServerName shop.example.test\n",
		"ServerAlias www.shop.example.test *.shop.example.test\n",
		`DocumentRoot "` + home + `/public_html"`,
		`DocumentRoot "` + home + `/public_html/blog"`,
		`Header always set X-LPanel-Revision "` + res.Revision + `"`,
		"proxy:unix:" + m.socket("83", "shop1") + "|fcgi://lpanel-shop1-83",
		"Options -Indexes +SymLinksIfOwnerMatch",
	} {
		if !strings.Contains(site, want) {
			t.Errorf("the site file lacks %q:\n%s", want, site)
		}
	}
	pool := read(t, m.poolFile("83", "shop1"))
	for _, want := range []string{"[lpanel-shop1]", "user = shop1", "listen.group = apache", "pm.max_children = 5", "session.save_path] = " + home + "/tmp"} {
		if !strings.Contains(pool, want) {
			t.Errorf("the pool lacks %q:\n%s", want, pool)
		}
	}
	if !strings.Contains(read(t, m.Include), "IncludeOptional ${LPANEL_SITES}/*.conf") {
		t.Error("no include file")
	}

	// Tested before swapping, PHP reloaded before Apache, then probed.
	order := []string{"php-fpm83 -t -y", "httpd -t -C Define LPANEL_SITES", "systemctl reload-or-restart php83-php-fpm", "systemctl reload-or-restart httpd"}
	at := 0
	for _, c := range run.calls {
		if at < len(order) && strings.HasPrefix(c, order[at]) {
			at++
		}
	}
	if at != len(order) {
		t.Fatalf("calls out of order: %v", run.calls)
	}
	if len(probes.asked) != 2 || probes.asked[0] != "shop.example.test@"+res.Revision {
		t.Fatalf("probes %v", probes.asked)
	}
	if read(t, filepath.Join(m.Sites, "other.conf")) != "# other\n" {
		t.Fatal("another account's file changed")
	}
	if left, _ := filepath.Glob(filepath.Join(filepath.Dir(m.Sites), ".stage-*")); len(left) != 0 {
		t.Fatalf("staging left behind: %v", left)
	}

	// The same state again changes nothing and runs nothing.
	run.calls = nil
	again, err := m.Apply(context.Background(), shop("83"))
	if err != nil || again.Changed || again.Revision != res.Revision || len(run.calls) != 0 {
		t.Fatalf("second apply: %+v %v %v", again, err, run.calls)
	}
}

func TestARejectedCandidateChangesNothingLive(t *testing.T) {
	m, run, _, _ := setup(t)
	if _, err := m.Apply(context.Background(), shop("83")); err != nil {
		t.Fatal(err)
	}
	before := read(t, m.siteFile("shop1"))

	run.calls = nil
	run.fail["httpd -t"] = "AH00526: Syntax error on line 3"
	changed := shop("83")
	changed.Sites[1].Docroot = "public_html/new"
	_, err := m.Apply(context.Background(), changed)
	if code(err) != CodeRejected || !strings.Contains(err.Error(), "AH00526") {
		t.Fatalf("got %v", err)
	}
	if read(t, m.siteFile("shop1")) != before {
		t.Fatal("the live file changed")
	}
	if run.ran("systemctl reload") != 0 {
		t.Fatalf("reloaded after a failed test: %v", run.calls)
	}

	run.fail = map[string]string{"php-fpm82 -t": "ERROR: [pool lpanel-shop1] cannot get uid"}
	if _, err := m.Apply(context.Background(), shop("82")); code(err) != CodeRejected {
		t.Fatalf("a refused pool: %v", err)
	}
	if _, err := os.Stat(m.poolFile("82", "shop1")); !os.IsNotExist(err) {
		t.Fatal("a refused pool went live")
	}
}

func TestAFailedProbePutsTheOldFilesBack(t *testing.T) {
	m, run, probes, _ := setup(t)
	if _, err := m.Apply(context.Background(), shop("83")); err != nil {
		t.Fatal(err)
	}
	before := read(t, m.siteFile("shop1"))
	beforePool := read(t, m.poolFile("83", "shop1"))

	run.calls = nil
	probes.fail = true
	moved := shop("82")
	_, err := m.Apply(context.Background(), moved)
	if code(err) != CodeProbe {
		t.Fatalf("got %v", err)
	}
	if read(t, m.siteFile("shop1")) != before || read(t, m.poolFile("83", "shop1")) != beforePool {
		t.Fatal("the old files are not back")
	}
	if _, err := os.Stat(m.poolFile("82", "shop1")); !os.IsNotExist(err) {
		t.Fatal("the new pool stayed")
	}
	if run.ran("systemctl reload-or-restart httpd") != 2 {
		t.Fatalf("Apache was not reloaded again with the old files: %v", run.calls)
	}
}

func TestMovingToAnotherPHPVersionStopsAnEmptyOne(t *testing.T) {
	m, run, _, _ := setup(t)
	if _, err := m.Apply(context.Background(), shop("83")); err != nil {
		t.Fatal(err)
	}
	run.calls = nil
	res, err := m.Apply(context.Background(), shop("82"))
	if err != nil || strings.Join(res.PHP, ",") != "82" {
		t.Fatalf("%+v %v", res, err)
	}
	if _, err := os.Stat(m.poolFile("83", "shop1")); !os.IsNotExist(err) {
		t.Fatal("the PHP 8.3 pool stayed")
	}
	if run.ran("systemctl stop php83-php-fpm") != 1 || run.ran("systemctl reload-or-restart php82-php-fpm") != 1 {
		t.Fatalf("calls %v", run.calls)
	}
}

func TestSuspendedSitesAnswer503AndRunNoPHP(t *testing.T) {
	m, _, _, _ := setup(t)
	if _, err := m.Apply(context.Background(), shop("83")); err != nil {
		t.Fatal(err)
	}
	s := shop("83")
	s.Suspended = true
	res, err := m.Apply(context.Background(), s)
	if err != nil || len(res.PHP) != 0 {
		t.Fatalf("%+v %v", res, err)
	}
	site := read(t, m.siteFile("shop1"))
	if !strings.Contains(site, "RewriteRule ^ - [R=503,L]") || strings.Contains(site, "proxy:unix") || strings.Contains(site, "DocumentRoot") {
		t.Fatalf("suspended site:\n%s", site)
	}
	if _, err := os.Stat(m.poolFile("83", "shop1")); !os.IsNotExist(err) {
		t.Fatal("a suspended account kept its pool")
	}
}

func TestRemoveIsSafeToRepeat(t *testing.T) {
	m, run, _, _ := setup(t)
	if _, err := m.Apply(context.Background(), shop("83")); err != nil {
		t.Fatal(err)
	}
	res, err := m.Remove(context.Background(), NameArgs{Username: "shop1"})
	if err != nil || !res.Changed {
		t.Fatalf("%+v %v", res, err)
	}
	if _, err := os.Stat(m.siteFile("shop1")); !os.IsNotExist(err) {
		t.Fatal("the site file stayed")
	}
	if _, err := os.Stat(m.poolFile("83", "shop1")); !os.IsNotExist(err) {
		t.Fatal("the pool stayed")
	}
	run.calls = nil
	if res, err := m.Remove(context.Background(), NameArgs{Username: "shop1"}); err != nil || res.Changed || len(run.calls) != 0 {
		t.Fatalf("second remove: %+v %v %v", res, err, run.calls)
	}
	if read(t, filepath.Join(m.Sites, "other.conf")) != "# other\n" {
		t.Fatal("another account's file changed")
	}
}

func TestInfoListsTheInstalledVersions(t *testing.T) {
	m, _, _, _ := setup(t)
	if _, err := m.Apply(context.Background(), shop("83")); err != nil {
		t.Fatal(err)
	}
	info, err := m.Info(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if !info.Installed || len(info.PHP) != 2 || info.PHP[1].Version != "83" || info.PHP[1].Pools != 1 || info.Accounts != 2 {
		t.Fatalf("%+v", info)
	}
}

func TestNewFoldersAreLabelledWhenSELinuxIsOn(t *testing.T) {
	m, run, _, root := setup(t)
	m.SELinux = true
	if _, err := m.Apply(context.Background(), shop("83")); err != nil {
		t.Fatal(err)
	}
	home := filepath.Join(root, "home", "shop1")
	want := "restorecon " + filepath.Join(home, "public_html") + " " + filepath.Join(home, "public_html/blog")
	if run.ran(want) != 1 {
		t.Fatalf("calls %v, want %q", run.calls, want)
	}
	run.calls = nil
	if _, err := m.Apply(context.Background(), shop("82")); err != nil || run.ran("restorecon") != 0 {
		t.Fatalf("relabelled folders that were already there: %v %v", run.calls, err)
	}
}

func TestWebOff(t *testing.T) {
	m, _, _, _ := setup(t)
	m.Server = "off"
	if _, err := m.Apply(context.Background(), shop("83")); code(err) != CodeWebOff {
		t.Fatalf("got %v", err)
	}
	if err := m.RemoveFor(context.Background(), "shop1"); err != nil {
		t.Fatalf("terminating with no web server: %v", err)
	}
}

func TestTerminatingWorksWithoutApacheInstalled(t *testing.T) {
	m, _, _, root := setup(t)
	os.RemoveAll(filepath.Join(root, "httpd", "conf.d"))
	if err := m.RemoveFor(context.Background(), "shop1"); err != nil {
		t.Fatalf("got %v", err)
	}
	if _, err := m.Apply(context.Background(), shop("83")); code(err) != CodeWebOff {
		t.Fatalf("applying without Apache: %v", err)
	}
}
