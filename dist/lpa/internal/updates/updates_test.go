package updates

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"lpa/internal/protocol"
)

// fakeRun answers each command by the longest key its command line starts with.
type fakeRun struct {
	answers map[string]answer
	calls   []string
}

type answer struct {
	code int
	out  string
}

func (f *fakeRun) Run(_ context.Context, tool string, args ...string) (int, string, error) {
	line := strings.TrimSpace(tool + " " + strings.Join(args, " "))
	f.calls = append(f.calls, line)
	best, found := "", false
	for k := range f.answers {
		if strings.HasPrefix(line, k) && len(k) >= len(best) {
			best, found = k, true
		}
	}
	if !found {
		return 1, "unexpected: " + line, nil
	}
	a := f.answers[best]
	return a.code, a.out, nil
}

func (f *fakeRun) ran(prefix string) []string {
	var out []string
	for _, c := range f.calls {
		if strings.HasPrefix(c, prefix) {
			out = append(out, c)
		}
	}
	return out
}

const installedBefore = "openssl-libs|x86_64|1:3.0.7-24.el9\n" +
	"php83-php-common|x86_64|0:8.3.14-1.el9.remi\n" +
	"php83-php-fpm|x86_64|0:8.3.14-1.el9.remi\n" +
	"httpd|x86_64|0:2.4.62-1.el9\n" +
	"python3.11|x86_64|0:3.11.9-1.el9\n" +
	"kernel-core|x86_64|0:5.14.0-503.el9\n"

func newManager(t *testing.T, run *fakeRun) *Manager {
	t.Helper()
	dir := t.TempDir()
	os.WriteFile(filepath.Join(dir, "osrelease"), []byte("5.14.0-503.el9.x86_64\n"), 0o644)
	m := &Manager{Run: run, OSRelease: filepath.Join(dir, "osrelease"), State: filepath.Join(dir, "state"), PanelDir: filepath.Join(dir, "panel")}
	m.Defaults()
	return m
}

func TestCheckListsUpdatesWithTheirErrataAndPHP(t *testing.T) {
	run := &fakeRun{answers: map[string]answer{
		"dnf -q --refresh repoquery --upgrades": {0, "Last metadata expiration check: now\n" +
			"openssl-libs|x86_64|1:3.0.7-27.el9|baseos\n" +
			"php83-php-common|x86_64|8.3.15-1.el9.remi|remi-safe\n" +
			"php83-php-fpm|x86_64|8.3.15-1.el9.remi|remi-safe\n" +
			"httpd|x86_64|2.4.62-2.el9|appstream\n" +
			"httpd|x86_64|2.4.62-2.el9|appstream\n" +
			"garbage line\n"},
		"rpm -qa": {0, installedBefore},
		"dnf -q updateinfo list --updates": {0, "ALSA-2026:0101 Moderate/Sec.  openssl-libs-1:3.0.7-26.el9.x86_64\n" +
			"ALSA-2026:0202 Important/Sec. openssl-libs-1:3.0.7-27.el9.x86_64\n" +
			"ALBA-2026:0303 bugfix        httpd-2.4.62-2.el9.x86_64\n"},
		"dnf -q needs-restarting -s":                      {0, "httpd.service\nsomething odd\ndbus-broker.service\n"},
		"dnf -q needs-restarting -r":                      {1, "Core libraries or services have been updated since boot-up:\n  * kernel\n  * glibc\n\nReboot is required to fully utilize these updates.\n"},
		"systemctl is-active --quiet lpanel-reboot.timer": {3, ""},
	}}
	m := newManager(t, run)
	c, err := m.Check(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if len(c.Packages) != 4 {
		t.Fatalf("packages: %+v", c.Packages)
	}
	ssl := c.Packages[1]
	if ssl.Name != "openssl-libs" || ssl.From != "1:3.0.7-24.el9" || ssl.To != "1:3.0.7-27.el9" || ssl.Advisory != "ALSA-2026:0202" || ssl.Severity != "Important" || !ssl.Security() {
		t.Fatalf("openssl: %+v", ssl)
	}
	if h := c.Packages[0]; h.Name != "httpd" || h.Kind != "bugfix" || h.Security() || h.From != "2.4.62-1.el9" {
		t.Fatalf("httpd: %+v", h)
	}
	if c.Security != 1 {
		t.Fatalf("security = %d", c.Security)
	}
	if len(c.PHP) != 1 || c.PHP[0] != (PHPUpdate{Version: "83", From: "8.3.14", To: "8.3.15"}) {
		t.Fatalf("php: %+v", c.PHP)
	}
	if strings.Join(c.Restart, ",") != "dbus-broker.service,httpd.service" {
		t.Fatalf("restart: %v", c.Restart)
	}
	if !c.Reboot.Known || !c.Reboot.Needed || strings.Join(c.Reboot.Why, ",") != "kernel,glibc" || c.Reboot.Scheduled {
		t.Fatalf("reboot: %+v", c.Reboot)
	}
	if c.Kernel != "5.14.0-503.el9.x86_64" || c.Panel.Channel {
		t.Fatalf("kernel %q, panel %+v", c.Kernel, c.Panel)
	}
}

func TestCheckFailsWhenTheRepositoriesCannotBeRead(t *testing.T) {
	run := &fakeRun{answers: map[string]answer{"dnf -q --refresh repoquery": {1, "Error: Failed to download metadata for repo 'baseos'"}}}
	_, err := newManager(t, run).Check(context.Background())
	var pe *protocol.Error
	if !errors.As(err, &pe) || pe.Code != CodeDNF || !strings.Contains(pe.Message, "baseos") {
		t.Fatalf("err = %v", err)
	}
}

func TestApplyUpdatesWhatTheScopeSaysAndRestartsWhatRunsOldCode(t *testing.T) {
	after := strings.Replace(installedBefore, "1:3.0.7-24.el9", "1:3.0.7-27.el9", 1) + "kernel-core|x86_64|0:5.14.0-504.el9\n"
	run := &fakeRun{answers: map[string]answer{
		"dnf -y -q":                           {0, ""},
		"dnf -q needs-restarting -s":          {0, "httpd.service\nlpanel-php-fpm.service\ndbus-broker.service\nexim.service\n"},
		"dnf -q needs-restarting -r":          {1, "  * kernel\n"},
		"systemctl try-restart httpd.service": {0, ""},
		"systemctl try-restart exim.service":  {1, "Job failed"},
		"systemd-run --on-active=15":          {0, ""},
		"systemctl is-active":                 {3, ""},
	}}
	calls := 0
	m := newManager(t, run)
	m.Run = runFunc(func(ctx context.Context, tool string, args ...string) (int, string, error) {
		if tool == "rpm" {
			calls++
			if calls == 1 {
				return 0, installedBefore, nil
			}
			return 0, after, nil
		}
		return run.Run(ctx, tool, args...)
	})
	res, err := m.Apply(context.Background(), ApplyArgs{Scope: ScopeSecurity, Exclude: []string{"kernel*"}})
	if err != nil {
		t.Fatal(err)
	}
	if got := run.ran("dnf -y"); len(got) != 1 || got[0] != "dnf -y -q --setopt=install_weak_deps=False --exclude=kernel* upgrade --security" {
		t.Fatalf("dnf: %v", got)
	}
	if len(res.Upgraded) != 2 || res.Upgraded[0].Name != "kernel-core" || res.Upgraded[0].From != "5.14.0-503.el9" || res.Upgraded[1] != (Change{Name: "openssl-libs", Arch: "x86_64", From: "1:3.0.7-24.el9", To: "1:3.0.7-27.el9"}) {
		t.Fatalf("upgraded: %+v", res.Upgraded)
	}
	if strings.Join(res.Restarted, ",") != "httpd.service" || strings.Join(res.RestartFailed, ",") != "exim.service" ||
		strings.Join(res.Soon, ",") != "lpanel-php-fpm.service" || strings.Join(res.Pending, ",") != "dbus-broker.service" {
		t.Fatalf("restarts: %+v", res)
	}
	if !res.Reboot.Needed {
		t.Fatalf("reboot: %+v", res.Reboot)
	}
	if got := run.ran("systemd-run"); len(got) != 1 || !strings.HasSuffix(got[0], "/usr/bin/systemctl try-restart lpanel-php-fpm.service") {
		t.Fatalf("systemd-run: %v", got)
	}
}

type runFunc func(ctx context.Context, tool string, args ...string) (int, string, error)

func (f runFunc) Run(ctx context.Context, tool string, args ...string) (int, string, error) {
	return f(ctx, tool, args...)
}

func TestApplyScopes(t *testing.T) {
	for scope, want := range map[string]string{
		ScopeAll:      "dnf -y -q --setopt=install_weak_deps=False upgrade",
		ScopePHP:      "dnf -y -q --setopt=install_weak_deps=False upgrade php[5-8][0-9]-*",
		ScopePackages: "dnf -y -q --setopt=install_weak_deps=False upgrade httpd python3.11",
	} {
		run := &fakeRun{answers: map[string]answer{"dnf -y": {0, ""}, "rpm -qa": {0, installedBefore}, "dnf -q needs-restarting": {0, ""}, "systemctl": {3, ""}}}
		a := ApplyArgs{Scope: scope}
		if scope == ScopePackages {
			a.Packages = []string{"httpd", "python3.11"}
		}
		if _, err := newManager(t, run).Apply(context.Background(), a); err != nil {
			t.Fatalf("%s: %v", scope, err)
		}
		if got := run.ran("dnf -y"); len(got) != 1 || got[0] != want {
			t.Fatalf("%s: %v", scope, got)
		}
	}
}

func TestApplyRefusesWhatIsNotAPackageOrNotInstalled(t *testing.T) {
	bad := []ApplyArgs{
		{Scope: "everything"},
		{Scope: ScopePackages},
		{Scope: ScopePackages, Packages: []string{"--setopt=gpgcheck=0"}},
		{Scope: ScopePackages, Packages: []string{"httpd; rm -rf /"}},
		{Scope: ScopePackages, Packages: []string{"https://evil.example/x.rpm"}},
		{Scope: ScopePackages, Packages: []string{"/tmp/x.rpm"}},
		{Scope: ScopeAll, Packages: []string{"httpd"}},
		{Scope: ScopeAll, Exclude: []string{"-x"}},
		{Scope: ScopeAll, Exclude: []string{"a b"}},
		{Scope: ScopePackages, Packages: []string{"nginx"}}, // not installed: dnf would not install it, but say so
	}
	for _, a := range bad {
		run := &fakeRun{answers: map[string]answer{"rpm -qa": {0, installedBefore}, "dnf -y": {0, ""}}}
		_, err := newManager(t, run).Apply(context.Background(), a)
		var pe *protocol.Error
		if !errors.As(err, &pe) || pe.Code != protocol.CodeBadArgs {
			t.Fatalf("%+v: err = %v", a, err)
		}
		if len(run.ran("dnf -y")) != 0 {
			t.Fatalf("%+v: dnf ran", a)
		}
	}
}

func TestApplyFailureSaysWhatDnfSaid(t *testing.T) {
	run := &fakeRun{answers: map[string]answer{"rpm -qa": {0, installedBefore}, "dnf -y": {1, "Error: Transaction test error:\n  file /usr/x conflicts"}}}
	_, err := newManager(t, run).Apply(context.Background(), ApplyArgs{Scope: ScopeAll})
	var pe *protocol.Error
	if !errors.As(err, &pe) || pe.Code != CodeDNF || !strings.Contains(pe.Message, "conflicts") {
		t.Fatalf("err = %v", err)
	}
}

func TestRebootIsAMinuteAwayAndCanBeCalledOff(t *testing.T) {
	run := &fakeRun{answers: map[string]answer{"systemctl is-active": {3, ""}, "systemd-run": {0, ""}, "systemctl stop": {0, ""}, "dnf -q needs-restarting": {0, ""}}}
	m := newManager(t, run)
	if _, err := m.ScheduleReboot(context.Background(), RebootArgs{}); err != nil {
		t.Fatal(err)
	}
	if got := run.ran("systemd-run"); len(got) != 1 || !strings.HasPrefix(got[0], "systemd-run --on-active=60 --unit=lpanel-reboot") || !strings.HasSuffix(got[0], "/usr/bin/systemctl reboot") {
		t.Fatalf("%v", got)
	}
	if _, err := m.ScheduleReboot(context.Background(), RebootArgs{Cancel: true}); err != nil {
		t.Fatal(err)
	}
	if got := run.ran("systemctl stop"); len(got) != 1 || got[0] != "systemctl stop lpanel-reboot.timer" {
		t.Fatalf("%v", got)
	}
	// One already on its way is not scheduled twice.
	run.answers["systemctl is-active"] = answer{0, ""}
	r, _ := m.ScheduleReboot(context.Background(), RebootArgs{})
	if !r.Scheduled || len(run.ran("systemd-run")) != 1 {
		t.Fatalf("%+v %v", r, run.calls)
	}
}

func TestParsers(t *testing.T) {
	if n, a, ok := splitNEVRA("python3.11-libs-3.11.9-1.el9.x86_64"); !ok || n != "python3.11-libs" || a != "x86_64" {
		t.Fatalf("%q %q", n, a)
	}
	if n, a, ok := splitNEVRA("openssl-1:3.0.7-27.el9.noarch"); !ok || n != "openssl" || a != "noarch" {
		t.Fatalf("%q %q", n, a)
	}
	if _, _, ok := splitNEVRA("nonsense"); ok {
		t.Fatal("nonsense parsed")
	}
	if trimVersion("1:8.3.14-1.el9.remi") != "8.3.14" {
		t.Fatal(trimVersion("1:8.3.14-1.el9.remi"))
	}
	if !hasName(map[string][]string{"python3.11.x86_64": {"x"}}, "python3.11") || hasName(map[string][]string{"python3.11.x86_64": {"x"}}, "python3") {
		t.Fatal("hasName")
	}
}

func TestVersions(t *testing.T) {
	ordered := []string{"0.1.0-dev", "0.1.0-rc.1", "0.1.0-rc.2", "0.1.0-rc.10", "0.1.0", "0.1.1-e2e", "0.1.1", "0.2.0", "1.0.0", "10.0.0"}
	for i := range ordered {
		for j := range ordered {
			want := sign(i - j)
			if got := CompareVersions(ordered[i], ordered[j]); got != want {
				t.Fatalf("%s vs %s = %d, want %d", ordered[i], ordered[j], got, want)
			}
		}
	}
	for _, bad := range []string{"", "1.2", "v1.2.3", "1.2.3-", "1.2.3-a/b", "1.2.3 ", "../1.2.3"} {
		if ValidVersion(bad) {
			t.Fatalf("%q is valid", bad)
		}
	}
}
