package ftp

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"

	"lpa/internal/protocol"
)

const testHash = "$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234"

type fakeRun struct {
	calls  []string
	who    string
	active bool
	// failStart makes "systemctl restart" leave Pure-FTPd stopped.
	failStart bool
}

func (f *fakeRun) Run(_ context.Context, tool string, args ...string) (int, string, error) {
	call := tool + " " + strings.Join(args, " ")
	f.calls = append(f.calls, call)
	switch {
	case tool == "pure-ftpwho":
		return 0, f.who, nil
	case call == "systemctl is-active --quiet pure-ftpd":
		if f.active {
			return 0, "", nil
		}
		return 3, "", nil
	case call == "systemctl restart pure-ftpd":
		f.active = !f.failStart
	case tool == "pure-pw":
		_ = os.WriteFile(args[1], []byte("pdb"), 0o600)
	}
	return 0, "", nil
}

func (f *fakeRun) count(prefix string) int {
	n := 0
	for _, c := range f.calls {
		if strings.HasPrefix(c, prefix) {
			n++
		}
	}
	return n
}

type fixture struct {
	m       *Manager
	run     *fakeRun
	homes   string
	signals []int
}

func newFixture(t *testing.T) *fixture {
	t.Helper()
	if runtime.GOOS == "windows" {
		t.Skip("Windows paths have a colon, which Pure-FTPd's file cannot hold")
	}
	base := t.TempDir()
	f := &fixture{run: &fakeRun{active: true}, homes: filepath.Join(base, "home")}
	f.m = &Manager{
		Enabled: true, Dir: filepath.Join(base, "etc", "lpanel"), Conf: filepath.Join(base, "etc", "pure-ftpd.conf"),
		CertFile: filepath.Join(base, "pki", "pure-ftpd.pem"), HostCert: filepath.Join(base, "tls", "panel.crt"),
		HostKey: filepath.Join(base, "tls", "panel.key"), Binary: filepath.Join(base, "pure-ftpd"), Run: f.run,
		Owner: func(name string) (int, int, string, error) {
			if name != "shop" && name != "blog" {
				return 0, 0, "", errors.New("not an account")
			}
			return 1500, 1500, filepath.Join(f.homes, name), nil
		},
		ChownIn: func(*os.Root, string, int, int) error { return nil },
		Signal:  func(pid int) error { f.signals = append(f.signals, pid); return nil },
	}
	f.m.Defaults()
	for _, h := range []string{"shop", "blog"} {
		if err := os.MkdirAll(filepath.Join(f.homes, h, "public_html"), 0o755); err != nil {
			t.Fatal(err)
		}
	}
	return f
}

func (f *fixture) read(t *testing.T, path string) string {
	t.Helper()
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	return string(data)
}

func code(err error) string {
	var pe *protocol.Error
	if errors.As(err, &pe) {
		return pe.Code
	}
	return ""
}

func TestApplyWritesLoginsShutInTheirFolders(t *testing.T) {
	f := newFixture(t)
	res, err := f.m.Apply(context.Background(), ApplyArgs{Username: "shop", Users: []User{
		{Login: "zed@shop.test", Hash: testHash, Folder: "/public_html/new/deep/"},
		{Login: "amy@shop.test", Hash: testHash, Folder: ""},
	}})
	if err != nil {
		t.Fatal(err)
	}
	if res.Users != 2 {
		t.Fatalf("users = %d", res.Users)
	}
	home := filepath.Join(f.homes, "shop")
	want := "amy@shop.test:" + testHash + ":1500:1500::" + home + "/./::::::::::::\n" +
		"zed@shop.test:" + testHash + ":1500:1500::" + home + "/public_html/new/deep/./::::::::::::\n"
	if got := f.read(t, filepath.Join(f.m.Dir, "users.d", "shop")); got != want {
		t.Fatalf("users.d/shop:\n%s\nwant:\n%s", got, want)
	}
	if got := f.read(t, filepath.Join(f.m.Dir, "passwd")); got != want {
		t.Fatalf("passwd:\n%s", got)
	}
	if info, err := os.Stat(filepath.Join(home, "public_html", "new", "deep")); err != nil || !info.IsDir() {
		t.Fatalf("the folder was not made: %v", err)
	}
	if f.run.count("pure-pw mkdb "+filepath.Join(f.m.Dir, "pureftpd.pdb")+" -f ") != 1 {
		t.Fatalf("calls: %v", f.run.calls)
	}
}

func TestApplyRefusesBadInput(t *testing.T) {
	f := newFixture(t)
	for name, u := range map[string]User{
		"no domain":       {Login: "bob", Hash: testHash},
		"colon":           {Login: "bob:x@shop.test", Hash: testHash},
		"upper":           {Login: "Bob@shop.test", Hash: testHash},
		"plain password":  {Login: "bob@shop.test", Hash: "secret"},
		"sha512":          {Login: "bob@shop.test", Hash: "$6$salt$abc"},
		"dotdot":          {Login: "bob@shop.test", Hash: testHash, Folder: "public_html/../../etc"},
		"colon in folder": {Login: "bob@shop.test", Hash: testHash, Folder: "a:b"},
		"newline":         {Login: "bob@shop.test", Hash: testHash, Folder: "a\nb"},
	} {
		_, err := f.m.Apply(context.Background(), ApplyArgs{Username: "shop", Users: []User{u}})
		if code(err) != protocol.CodeBadArgs {
			t.Errorf("%s: err = %v", name, err)
		}
	}
	_, err := f.m.Apply(context.Background(), ApplyArgs{Username: "shop", Users: []User{
		{Login: "bob@shop.test", Hash: testHash}, {Login: "bob@shop.test", Hash: testHash},
	}})
	if code(err) != protocol.CodeBadArgs {
		t.Errorf("twice: %v", err)
	}
	if _, err := f.m.Apply(context.Background(), ApplyArgs{Username: "Shop"}); code(err) != protocol.CodeBadArgs {
		t.Errorf("username: %v", err)
	}
}

func TestAFolderThroughALinkOutOfTheHomeIsRefused(t *testing.T) {
	f := newFixture(t)
	outside := t.TempDir()
	if err := os.Symlink(outside, filepath.Join(f.homes, "shop", "out")); err != nil {
		t.Skip("no symlinks here:", err)
	}
	_, err := f.m.Apply(context.Background(), ApplyArgs{Username: "shop", Users: []User{{Login: "bob@shop.test", Hash: testHash, Folder: "out/x"}}})
	if code(err) != protocol.CodeBadArgs {
		t.Fatalf("err = %v", err)
	}
	if _, err := os.Stat(filepath.Join(outside, "x")); err == nil {
		t.Fatal("a folder was made outside the home")
	}
	// A file where a folder is wanted.
	if err := os.WriteFile(filepath.Join(f.homes, "shop", "afile"), []byte("x"), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := f.m.Apply(context.Background(), ApplyArgs{Username: "shop", Users: []User{{Login: "bob@shop.test", Hash: testHash, Folder: "afile"}}}); code(err) != protocol.CodeBadArgs {
		t.Fatalf("file: %v", err)
	}
}

func TestALoginOfAnotherAccountIsRefused(t *testing.T) {
	f := newFixture(t)
	ctx := context.Background()
	if _, err := f.m.Apply(ctx, ApplyArgs{Username: "blog", Users: []User{{Login: "bob@blog.test", Hash: testHash}}}); err != nil {
		t.Fatal(err)
	}
	_, err := f.m.Apply(ctx, ApplyArgs{Username: "shop", Users: []User{{Login: "bob@blog.test", Hash: testHash}}})
	if code(err) != CodeTaken {
		t.Fatalf("err = %v", err)
	}
	// Both accounts' logins are in the joined file.
	if _, err := f.m.Apply(ctx, ApplyArgs{Username: "shop", Users: []User{{Login: "amy@shop.test", Hash: testHash}}}); err != nil {
		t.Fatal(err)
	}
	all := f.read(t, filepath.Join(f.m.Dir, "passwd"))
	if !strings.Contains(all, "bob@blog.test:") || !strings.Contains(all, "amy@shop.test:") {
		t.Fatalf("passwd:\n%s", all)
	}
}

func TestChangesAndSuspensionEndSessions(t *testing.T) {
	f := newFixture(t)
	ctx := context.Background()
	users := []User{{Login: "amy@shop.test", Hash: testHash}, {Login: "bob@shop.test", Hash: testHash, Folder: "public_html"}}
	if _, err := f.m.Apply(ctx, ApplyArgs{Username: "shop", Users: users}); err != nil {
		t.Fatal(err)
	}
	if _, err := f.m.Apply(ctx, ApplyArgs{Username: "blog", Users: []User{{Login: "cat@blog.test", Hash: testHash}}}); err != nil {
		t.Fatal(err)
	}
	f.run.who = "101|amy@shop.test|30|IDLE||203.0.113.9|10.0.0.1|21|0|0|0|0\n" +
		"102|bob@shop.test|5|DL|/x.zip|203.0.113.9|10.0.0.1|21|100|1000|10|50\n" +
		"103|cat@blog.test|5|IDLE||198.51.100.2|10.0.0.1|21|0|0|0|0\n"

	// Unchanged: nothing ends.
	res, err := f.m.Apply(ctx, ApplyArgs{Username: "shop", Users: users})
	if err != nil || res.Ended != 0 || len(f.signals) != 0 {
		t.Fatalf("res = %+v, err = %v, signals = %v", res, err, f.signals)
	}
	// bob's folder changes: bob's session ends, amy's goes on.
	users[1].Folder = "public_html/sub"
	if res, err = f.m.Apply(ctx, ApplyArgs{Username: "shop", Users: users}); err != nil || res.Ended != 1 || f.signals[0] != 102 {
		t.Fatalf("res = %+v, err = %v, signals = %v", res, err, f.signals)
	}
	// Suspended: the file is empty and every session of the account ends.
	f.signals = nil
	if res, err = f.m.Apply(ctx, ApplyArgs{Username: "shop", Users: users, Suspended: true}); err != nil || res.Users != 0 {
		t.Fatalf("res = %+v, err = %v", res, err)
	}
	if got := f.read(t, filepath.Join(f.m.Dir, "users.d", "shop")); got != "" {
		t.Fatalf("suspended file: %q", got)
	}
	if len(f.signals) != 2 || f.signals[0] != 101 || f.signals[1] != 102 {
		t.Fatalf("signals = %v", f.signals)
	}
}

func TestKillOnlyEndsAListedSessionOfTheAccount(t *testing.T) {
	f := newFixture(t)
	ctx := context.Background()
	if _, err := f.m.Apply(ctx, ApplyArgs{Username: "shop", Users: []User{{Login: "amy@shop.test", Hash: testHash}}}); err != nil {
		t.Fatal(err)
	}
	f.run.who = "101|amy@shop.test|30|IDLE||203.0.113.9|10.0.0.1|21|0|0|0|0\n103|cat@blog.test|5|IDLE||198.51.100.2|10.0.0.1|21|0|0|0|0\n"
	if _, err := f.m.Kill(ctx, KillArgs{PID: 4242}); code(err) != CodeNoSession {
		t.Fatalf("unlisted pid: %v", err)
	}
	if _, err := f.m.Kill(ctx, KillArgs{PID: 103, Username: "shop"}); code(err) != CodeNoSession {
		t.Fatalf("another account's: %v", err)
	}
	if _, err := f.m.Kill(ctx, KillArgs{PID: 1}); code(err) != protocol.CodeBadArgs {
		t.Fatalf("pid 1: %v", err)
	}
	if len(f.signals) != 0 {
		t.Fatalf("signalled %v", f.signals)
	}
	res, err := f.m.Kill(ctx, KillArgs{PID: 101, Username: "shop"})
	if err != nil || res["login"] != "amy@shop.test" || len(f.signals) != 1 || f.signals[0] != 101 {
		t.Fatalf("res = %v, err = %v, signals = %v", res, err, f.signals)
	}
	list, err := f.m.Sessions(ctx)
	if err != nil || len(list) != 2 || list[0].Account != "shop" || list[1].Account != "" {
		t.Fatalf("sessions = %+v, %v", list, err)
	}
}

func TestRemoveForTakesTheAccountsLoginsAway(t *testing.T) {
	f := newFixture(t)
	ctx := context.Background()
	for _, a := range []ApplyArgs{
		{Username: "shop", Users: []User{{Login: "amy@shop.test", Hash: testHash}}},
		{Username: "blog", Users: []User{{Login: "cat@blog.test", Hash: testHash}}},
	} {
		if _, err := f.m.Apply(ctx, a); err != nil {
			t.Fatal(err)
		}
	}
	f.run.who = "101|amy@shop.test|30|IDLE||203.0.113.9|10.0.0.1|21|0|0|0|0\n"
	if err := f.m.RemoveFor(ctx, "shop"); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(f.m.Dir, "users.d", "shop")); err == nil {
		t.Fatal("users.d/shop is still there")
	}
	if all := f.read(t, filepath.Join(f.m.Dir, "passwd")); strings.Contains(all, "amy@") || !strings.Contains(all, "cat@") {
		t.Fatalf("passwd:\n%s", all)
	}
	if len(f.signals) != 1 {
		t.Fatalf("signals = %v", f.signals)
	}
	// Again: nothing to do.
	if err := f.m.RemoveFor(ctx, "shop"); err != nil {
		t.Fatal(err)
	}
}

func TestSettingsWriteTheConfigurationAndRollBack(t *testing.T) {
	f := newFixture(t)
	ctx := context.Background()
	if err := os.MkdirAll(filepath.Dir(f.m.HostCert), 0o700); err != nil {
		t.Fatal(err)
	}
	_ = os.WriteFile(f.m.HostCert, []byte("CERT\n"), 0o600)
	_ = os.WriteFile(f.m.HostKey, []byte("KEY\n"), 0o600)

	s := DefaultSettings()
	s.PassiveIP = "203.0.113.5"
	res, err := f.m.Settings(ctx, s)
	if err != nil || res["changed"] != true {
		t.Fatalf("res = %v, err = %v", res, err)
	}
	conf := f.read(t, f.m.Conf)
	for _, want := range []string{"NoAnonymous                  yes", "UnixAuthentication           no", "PassivePortRange             30000 30100",
		"ForcePassiveIP               203.0.113.5", "TLS                          2", "PureDB                       " + filepath.Join(f.m.Dir, "pureftpd.pdb"),
		"CertFile                     " + f.m.CertFile, "MinUID                       1000", "ChrootEveryone               yes"} {
		if !strings.Contains(conf, want) {
			t.Errorf("conf lacks %q:\n%s", want, conf)
		}
	}
	if got := f.read(t, f.m.CertFile); got != "KEY\nCERT\n" {
		t.Fatalf("cert file = %q", got)
	}
	if f.run.count("systemctl restart pure-ftpd") != 1 {
		t.Fatalf("calls: %v", f.run.calls)
	}
	if f.m.Applied() != s {
		t.Fatalf("applied = %+v", f.m.Applied())
	}
	// The same again: no restart.
	if res, err = f.m.Settings(ctx, s); err != nil || res["changed"] != false || f.run.count("systemctl restart pure-ftpd") != 1 {
		t.Fatalf("again: %v %v %v", res, err, f.run.calls)
	}
	// A setting Pure-FTPd will not start with: the old file comes back.
	f.run.failStart = true
	bad := s
	bad.TLS = "strict"
	if _, err := f.m.Settings(ctx, bad); err == nil {
		t.Fatal("no error")
	}
	if !strings.Contains(f.read(t, f.m.Conf), "TLS                          2") {
		t.Fatal("the old configuration did not come back")
	}
	if f.m.Applied() != s {
		t.Fatalf("applied = %+v", f.m.Applied())
	}
	// Refused values.
	for _, b := range []SettingsArgs{
		{TLS: "off", PassiveFrom: 30000, PassiveTo: 30100, MaxClients: 10, MaxPerIP: 2},
		{TLS: "required", PassiveFrom: 80, PassiveTo: 30100, MaxClients: 10, MaxPerIP: 2},
		{TLS: "required", PassiveFrom: 30100, PassiveTo: 30000, MaxClients: 10, MaxPerIP: 2},
		{TLS: "required", PassiveFrom: 30000, PassiveTo: 30100, PassiveIP: "::1", MaxClients: 10, MaxPerIP: 2},
		{TLS: "required", PassiveFrom: 30000, PassiveTo: 30100, MaxClients: 10, MaxPerIP: 20},
	} {
		if _, err := f.m.Settings(ctx, b); code(err) != protocol.CodeBadArgs {
			t.Errorf("%+v: %v", b, err)
		}
	}
}

func TestHostTLSRestartsOnlyWhenTheCertificateChanged(t *testing.T) {
	f := newFixture(t)
	ctx := context.Background()
	if err := f.m.HostTLS(ctx); err != nil || f.run.count("systemctl restart") != 0 {
		t.Fatalf("no certificate: %v %v", err, f.run.calls)
	}
	_ = os.MkdirAll(filepath.Dir(f.m.HostCert), 0o700)
	_ = os.WriteFile(f.m.HostCert, []byte("CERT1"), 0o600)
	_ = os.WriteFile(f.m.HostKey, []byte("KEY1"), 0o600)
	if err := f.m.HostTLS(ctx); err != nil || f.run.count("systemctl restart pure-ftpd") != 1 {
		t.Fatalf("first: %v %v", err, f.run.calls)
	}
	if err := f.m.HostTLS(ctx); err != nil || f.run.count("systemctl restart pure-ftpd") != 1 {
		t.Fatalf("same: %v %v", err, f.run.calls)
	}
	f.m.Enabled = false
	_ = os.WriteFile(f.m.HostCert, []byte("CERT2"), 0o600)
	if err := f.m.HostTLS(ctx); err != nil || f.run.count("systemctl restart pure-ftpd") != 1 {
		t.Fatalf("off: %v %v", err, f.run.calls)
	}
	if _, err := f.m.Apply(ctx, ApplyArgs{Username: "shop"}); code(err) != CodeOff {
		t.Fatalf("off apply: %v", err)
	}
}
