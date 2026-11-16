package mail

import (
	"context"
	"crypto/x509"
	"encoding/base64"
	"encoding/pem"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"lpa/internal/protocol"
)

const testHash = "$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234"

type fakeRun struct{ calls []string }

func (f *fakeRun) Run(_ context.Context, tool string, args ...string) (int, string, error) {
	f.calls = append(f.calls, tool+" "+strings.Join(args, " "))
	if tool == "sievec" && len(args) == 2 {
		_ = os.WriteFile(args[1], []byte("compiled"), 0o600)
	}
	return 0, "", nil
}

type fixture struct {
	m     *Manager
	run   *fakeRun
	homes string
	etc   string
}

func newFixture(t *testing.T) *fixture {
	t.Helper()
	base := t.TempDir()
	f := &fixture{run: &fakeRun{}, homes: filepath.Join(base, "home"), etc: filepath.Join(base, "etc")}
	f.m = &Manager{
		Enabled:    true,
		EximDir:    filepath.Join(f.etc, "exim", "lpanel"),
		DovecotDir: filepath.Join(f.etc, "dovecot", "lpanel"),
		TLSDir:     filepath.Join(f.etc, "pki", "mail"),
		HostCert:   filepath.Join(f.etc, "tls", "panel.crt"),
		HostKey:    filepath.Join(f.etc, "tls", "panel.key"),
		Run:        f.run,
		Owner: func(name string) (int, int, string, error) {
			if err := checkAccount(name); err != nil {
				return 0, 0, "", err
			}
			home := filepath.Join(f.homes, name)
			if err := os.MkdirAll(home, 0o711); err != nil {
				return 0, 0, "", err
			}
			return 1001, 1001, home, nil
		},
		Group:   func(string) (int, error) { return 990, nil },
		Chown:   func(string, int, int) error { return nil },
		ChownIn: func(*os.Root, string, int, int) error { return nil },
		KeyBits: 1024,
	}
	f.m.Defaults()
	return f
}

func (f *fixture) read(t *testing.T, parts ...string) string {
	t.Helper()
	data, err := os.ReadFile(filepath.Join(parts...))
	if err != nil {
		t.Fatalf("read %s: %v", filepath.Join(parts...), err)
	}
	return string(data)
}

func shop() ApplyArgs {
	return ApplyArgs{Username: "shop1", Domains: []Domain{{
		Name: "example.com", Local: true, Catchall: Discard,
		Mailboxes: []Mailbox{
			{Local: "info", Hash: testHash, QuotaMB: 1024},
			{Local: "bob.smith", Hash: testHash, Autoresponder: &Autoresponder{Subject: "Away", Body: "Back Monday.", FromName: "Bob", Days: 1, Start: "2026-11-01", End: "2026-11-30"}},
		},
		Forwarders: []Forwarder{{Local: "sales", To: []string{"info@example.com", "Someone@Gmail.COM"}}, {Local: "info", To: []string{"backup@example.net"}}},
	}}}
}

func TestApplyWritesEveryFile(t *testing.T) {
	f := newFixture(t)
	res, err := f.m.Apply(context.Background(), shop())
	if err != nil {
		t.Fatal(err)
	}
	if got := f.read(t, f.m.EximDir, "owners", "example.com"); !strings.Contains(got, "\nowner: shop1\n") {
		t.Errorf("owners: %q", got)
	}
	if got := f.read(t, f.m.EximDir, "mailboxes", "example.com"); !strings.Contains(got, "\ninfo: shop1\nbob.smith: shop1\n") {
		t.Errorf("mailboxes: %q", got)
	}
	fwd := f.read(t, f.m.EximDir, "forwards", "example.com")
	for _, want := range []string{"\nsales: info@example.com, Someone@gmail.com\n", "\ninfo: backup@example.net\n", "\n*: :blackhole:\n"} {
		if !strings.Contains(fwd, want) {
			t.Errorf("forwards lacks %q:\n%s", want, fwd)
		}
	}
	if !strings.Contains(f.read(t, f.m.EximDir, "local", "example.com"), "owner: shop1") {
		t.Error("the domain is not marked local")
	}
	home := filepath.Join(f.homes, "shop1")
	passwd := f.read(t, f.m.DovecotDir, "passwd.d", "example.com")
	want := "info:{BLF-CRYPT}" + testHash + ":1001:1001::" + filepath.ToSlash(filepath.Join(home, "mail", "example.com", "info")) + "::userdb_quota_rule=*:storage=1024M\n"
	if !strings.Contains(filepath.ToSlash(passwd), want) {
		t.Errorf("passwd:\n%s\nwant line:\n%s", passwd, want)
	}
	if strings.Contains(passwd, "nologin") {
		t.Error("an active account's mailboxes must log in")
	}
	script := f.read(t, f.m.DovecotDir, "sieve", "example.com", "bob.smith.sieve")
	for _, want := range []string{`require ["vacation", "date", "relational"];`, `currentdate :value "ge" "date" "2026-11-01"`, `:days 1 :subject "Away" :from "\"Bob\" <bob.smith@example.com>" :addresses ["bob.smith@example.com"]`} {
		if !strings.Contains(script, want) {
			t.Errorf("sieve lacks %q:\n%s", want, script)
		}
	}
	if _, err := os.Stat(filepath.Join(f.m.DovecotDir, "sieve", "example.com", "info.sieve")); err == nil {
		t.Error("a mailbox without an autoresponder got a script")
	}
	if len(f.run.calls) != 1 || !strings.HasPrefix(f.run.calls[0], "sievec ") {
		t.Errorf("calls: %v", f.run.calls)
	}
	if info, err := os.Stat(filepath.Join(home, "mail")); err != nil || !info.IsDir() {
		t.Errorf("~/mail was not made: %v", err)
	}

	// The DKIM key: kept by the agent, its public half returned for DNS.
	pub := res.DKIM["example.com"]
	if !strings.HasPrefix(pub, "v=DKIM1; k=rsa; p=") {
		t.Fatalf("dkim: %q", pub)
	}
	der, err := base64.StdEncoding.DecodeString(strings.TrimPrefix(pub, "v=DKIM1; k=rsa; p="))
	if err != nil {
		t.Fatal(err)
	}
	if _, err := x509.ParsePKIXPublicKey(der); err != nil {
		t.Errorf("the published key does not parse: %v", err)
	}
	block, _ := pem.Decode([]byte(f.read(t, f.m.EximDir, "dkim", "example.com")))
	if block == nil || block.Type != "PRIVATE KEY" {
		t.Error("the private key is not PKCS#8 PEM")
	}
}

func TestApplyTwiceChangesNothing(t *testing.T) {
	f := newFixture(t)
	first, err := f.m.Apply(context.Background(), shop())
	if err != nil {
		t.Fatal(err)
	}
	second, err := f.m.Apply(context.Background(), shop())
	if err != nil {
		t.Fatal(err)
	}
	if second.Changed != 0 {
		t.Errorf("the same state again changed %d files", second.Changed)
	}
	if first.DKIM["example.com"] != second.DKIM["example.com"] {
		t.Error("the DKIM key changed")
	}
	if len(f.run.calls) != 1 {
		t.Errorf("an unchanged autoresponder was compiled again: %v", f.run.calls)
	}
}

func TestApplyRefusesWhatMailMayNotHold(t *testing.T) {
	cases := map[string]func(a *ApplyArgs){
		"a tag separator in a local part": func(a *ApplyArgs) { a.Domains[0].Mailboxes[0].Local = "info+x" },
		"an uppercase local part":         func(a *ApplyArgs) { a.Domains[0].Mailboxes[0].Local = "Info" },
		"a password that is not bcrypt":   func(a *ApplyArgs) { a.Domains[0].Mailboxes[0].Hash = "{PLAIN}secret" },
		"a forwarder to a pipe":           func(a *ApplyArgs) { a.Domains[0].Forwarders[0].To = []string{"|/bin/sh -c id"} },
		"a forwarder to a file":           func(a *ApplyArgs) { a.Domains[0].Forwarders[0].To = []string{"/etc/passwd"} },
		"a forwarder to an include":       func(a *ApplyArgs) { a.Domains[0].Forwarders[0].To = []string{":include:/etc/aliases"} },
		"two addresses in one":            func(a *ApplyArgs) { a.Domains[0].Forwarders[0].To = []string{"a@b.com, c@d.com"} },
		"a quoted local part":             func(a *ApplyArgs) { a.Domains[0].Forwarders[0].To = []string{`"a b"@example.com`} },
		"a catch-all to a pipe":           func(a *ApplyArgs) { a.Domains[0].Catchall = "|/bin/true" },
		"a domain that is not one":        func(a *ApplyArgs) { a.Domains[0].Name = ".." },
		"a domain with a slash":           func(a *ApplyArgs) { a.Domains[0].Name = "a/b.com" },
		"a domain twice":                  func(a *ApplyArgs) { a.Domains = append(a.Domains, a.Domains[0]) },
		"a mailbox twice":                 func(a *ApplyArgs) { a.Domains[0].Mailboxes[1].Local = "info" },
		"a quota too large":               func(a *ApplyArgs) { a.Domains[0].Mailboxes[0].QuotaMB = MaxQuotaMB + 1 },
		"a subject with a newline":        func(a *ApplyArgs) { a.Domains[0].Mailboxes[1].Autoresponder.Subject = "a\nb" },
		"an end before the start":         func(a *ApplyArgs) { a.Domains[0].Mailboxes[1].Autoresponder.End = "2026-10-01" },
		"an answer every day forever":     func(a *ApplyArgs) { a.Domains[0].Mailboxes[1].Autoresponder.Days = 0 },
		"a username that is not one":      func(a *ApplyArgs) { a.Username = "../root" },
	}
	for name, change := range cases {
		t.Run(name, func(t *testing.T) {
			f := newFixture(t)
			a := shop()
			change(&a)
			_, err := f.m.Apply(context.Background(), a)
			var pe *protocol.Error
			if !errors.As(err, &pe) || pe.Code != protocol.CodeBadArgs {
				t.Fatalf("got %v, want bad_args", err)
			}
			if _, err := os.Stat(filepath.Join(f.m.EximDir, "owners")); err == nil {
				t.Error("something was written before the check")
			}
		})
	}
}

func TestADomainIsOnlyItsOwnAccounts(t *testing.T) {
	f := newFixture(t)
	if _, err := f.m.Apply(context.Background(), shop()); err != nil {
		t.Fatal(err)
	}
	other := shop()
	other.Username = "evil"
	other.Domains[0].Mailboxes[0].Hash = "$2y$10$" + strings.Repeat("x", 53)
	_, err := f.m.Apply(context.Background(), other)
	var pe *protocol.Error
	if !errors.As(err, &pe) || pe.Code != CodeNotOwner {
		t.Fatalf("got %v, want %s", err, CodeNotOwner)
	}
	if strings.Contains(f.read(t, f.m.DovecotDir, "passwd.d", "example.com"), strings.Repeat("x", 53)) {
		t.Error("another account's password reached the domain")
	}
	// Removing all of evil's domains leaves shop1's alone.
	if _, err := f.m.Apply(context.Background(), ApplyArgs{Username: "evil"}); err != nil {
		t.Fatal(err)
	}
	if err := f.m.RemoveFor(context.Background(), "evil"); err != nil {
		t.Fatal(err)
	}
	if f.m.ownerOf("example.com") != "shop1" {
		t.Error("another account took the domain away")
	}
}

func TestARemovedDomainLosesItsFilesButNotItsMail(t *testing.T) {
	f := newFixture(t)
	if _, err := f.m.Apply(context.Background(), shop()); err != nil {
		t.Fatal(err)
	}
	maildir := filepath.Join(f.homes, "shop1", "mail", "example.com", "info", "Maildir", "cur")
	if err := os.MkdirAll(maildir, 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(maildir, "1.eml"), []byte("hello"), 0o600); err != nil {
		t.Fatal(err)
	}
	res, err := f.m.Apply(context.Background(), ApplyArgs{Username: "shop1"})
	if err != nil {
		t.Fatal(err)
	}
	if res.Changed == 0 {
		t.Error("nothing changed")
	}
	for _, p := range []string{
		filepath.Join(f.m.EximDir, "owners", "example.com"), filepath.Join(f.m.EximDir, "local", "example.com"),
		filepath.Join(f.m.EximDir, "mailboxes", "example.com"), filepath.Join(f.m.EximDir, "forwards", "example.com"),
		filepath.Join(f.m.EximDir, "dkim", "example.com"), filepath.Join(f.m.DovecotDir, "passwd.d", "example.com"),
		filepath.Join(f.m.DovecotDir, "sieve", "example.com"),
	} {
		if _, err := os.Stat(p); err == nil {
			t.Errorf("%s is still there", p)
		}
	}
	if _, err := os.Stat(filepath.Join(maildir, "1.eml")); err != nil {
		t.Error("the mail went with the domain's files")
	}
}

func TestRemoteDomainsAreNotLocal(t *testing.T) {
	f := newFixture(t)
	if _, err := f.m.Apply(context.Background(), shop()); err != nil {
		t.Fatal(err)
	}
	a := shop()
	a.Domains[0].Local = false
	if _, err := f.m.Apply(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(f.m.EximDir, "local", "example.com")); err == nil {
		t.Error("a domain whose mail is elsewhere is still delivered here")
	}
	if f.m.ownerOf("example.com") != "shop1" {
		t.Error("its mailboxes must still log in and send")
	}
}

func TestASuspendedAccountReceivesButCannotLogIn(t *testing.T) {
	f := newFixture(t)
	a := shop()
	a.Suspended = true
	if _, err := f.m.Apply(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	passwd := f.read(t, f.m.DovecotDir, "passwd.d", "example.com")
	for _, line := range strings.Split(strings.TrimSpace(passwd), "\n")[1:] {
		if !strings.Contains(line, " nologin ") && !strings.Contains(line, ":nologin ") {
			t.Errorf("a mailbox may still log in: %q", line)
		}
	}
	if !strings.Contains(f.read(t, f.m.EximDir, "mailboxes", "example.com"), "info: shop1") {
		t.Error("a suspended account's mail must still arrive")
	}
	// Exim's logins go through Dovecot's auth, which does not apply
	// nologin; Exim refuses a suspended account's mail from this line.
	if !strings.Contains(f.read(t, f.m.EximDir, "owners", "example.com"), "\nsuspended: yes\n") {
		t.Error("Exim is not told the account is suspended")
	}
}

func TestAutoresponderTextStaysInsideItsStrings(t *testing.T) {
	d := Domain{Name: "example.com"}
	m := Mailbox{Local: "info", Autoresponder: &Autoresponder{
		Subject: `Out "now" \ ok`, Body: "Bye\";\nredirect \"evil@example.net\";\r\n", FromName: `Evil" <x@y.z>`, Days: 3,
	}}
	script := string(sieveScript(d, m))
	if !strings.Contains(script, `:subject "Out \"now\" \\ ok"`) {
		t.Errorf("subject not escaped:\n%s", script)
	}
	if !strings.Contains(script, `"Bye\";`+"\r\n"+`redirect \"evil@example.net\";`+"\r\n"+`";`) {
		t.Errorf("body not escaped:\n%s", script)
	}
	if !strings.Contains(script, `:from "\"Evil x@y.z\" <info@example.com>"`) {
		t.Errorf("name not cleaned:\n%s", script)
	}
	if strings.Contains(script, "if allof") {
		t.Error("an autoresponder without dates is bounded")
	}
	// Outside its strings, the script holds only the agent's words.
	outside := ""
	inside := false
	for i := 0; i < len(script); i++ {
		c := script[i]
		if c == '\\' && inside {
			i++
			continue
		}
		if c == '"' {
			inside = !inside
			continue
		}
		if !inside {
			outside += string(c)
		}
	}
	if strings.Contains(outside, "redirect") {
		t.Errorf("text escaped its string:\n%s", outside)
	}
}

func TestPurgeDeletesOnlyMailNoLongerSetUp(t *testing.T) {
	f := newFixture(t)
	if _, err := f.m.Apply(context.Background(), shop()); err != nil {
		t.Fatal(err)
	}
	home := filepath.Join(f.homes, "shop1")
	box := filepath.Join(home, "mail", "example.com", "info", "Maildir")
	if err := os.MkdirAll(box, 0o700); err != nil {
		t.Fatal(err)
	}
	_, err := f.m.Purge(context.Background(), PurgeArgs{Username: "shop1", Domain: "example.com", Local: "info"})
	var pe *protocol.Error
	if !errors.As(err, &pe) || pe.Code != CodeInUse {
		t.Fatalf("got %v, want %s", err, CodeInUse)
	}
	if _, err := f.m.Purge(context.Background(), PurgeArgs{Username: "shop1", Domain: "example.com"}); !errors.As(err, &pe) || pe.Code != CodeInUse {
		t.Fatalf("purging a domain still set up: %v", err)
	}
	a := shop()
	a.Domains[0].Mailboxes = a.Domains[0].Mailboxes[1:]
	if _, err := f.m.Apply(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	res, err := f.m.Purge(context.Background(), PurgeArgs{Username: "shop1", Domain: "example.com", Local: "info"})
	if err != nil || !res["removed"] {
		t.Fatalf("purge: %v %v", res, err)
	}
	if _, err := os.Stat(box); err == nil {
		t.Error("the mail is still there")
	}
	if _, err := f.m.Purge(context.Background(), PurgeArgs{Username: "shop1", Domain: "../../etc", Local: "x"}); !errors.As(err, &pe) || pe.Code != protocol.CodeBadArgs {
		t.Errorf("a path for a domain: %v", err)
	}
}

func TestPurgeDoesNotFollowALinkOutOfTheHome(t *testing.T) {
	f := newFixture(t)
	outside := filepath.Join(t.TempDir(), "precious")
	if err := os.MkdirAll(filepath.Join(outside, "example.com", "info"), 0o700); err != nil {
		t.Fatal(err)
	}
	home := filepath.Join(f.homes, "shop1")
	if err := os.MkdirAll(home, 0o711); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(outside, filepath.Join(home, "mail")); err != nil {
		t.Skip("links cannot be made here:", err)
	}
	_, _ = f.m.Purge(context.Background(), PurgeArgs{Username: "shop1", Domain: "example.com", Local: "info"})
	if _, err := os.Stat(filepath.Join(outside, "example.com", "info")); err != nil {
		t.Error("a link in the home led the purge outside it")
	}
}

func TestUsageCountsEachMailbox(t *testing.T) {
	f := newFixture(t)
	home := filepath.Join(f.homes, "shop1")
	for path, size := range map[string]int{
		"mail/example.com/info/Maildir/cur/1": 100, "mail/example.com/info/Maildir/new/2": 50,
		"mail/example.org/bob/Maildir/cur/3": 7, "mail/stray": 1000,
	} {
		p := filepath.Join(home, filepath.FromSlash(path))
		if err := os.MkdirAll(filepath.Dir(p), 0o700); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(p, make([]byte, size), 0o600); err != nil {
			t.Fatal(err)
		}
	}
	u, err := f.m.Usage(context.Background(), UsageArgs{Username: "shop1"})
	if err != nil {
		t.Fatal(err)
	}
	if u.Mailboxes["info@example.com"] != 150 || u.Mailboxes["bob@example.org"] != 7 || len(u.Mailboxes) != 2 {
		t.Errorf("usage: %v", u.Mailboxes)
	}
	none, err := f.m.Usage(context.Background(), UsageArgs{Username: "empty"})
	if err != nil || len(none.Mailboxes) != 0 {
		t.Errorf("an account without mail: %v %v", none, err)
	}
}

func TestRemoveForTakesOnlyTheAccountsDomains(t *testing.T) {
	f := newFixture(t)
	if _, err := f.m.Apply(context.Background(), shop()); err != nil {
		t.Fatal(err)
	}
	other := ApplyArgs{Username: "other", Domains: []Domain{{Name: "other.org", Local: true}}}
	if _, err := f.m.Apply(context.Background(), other); err != nil {
		t.Fatal(err)
	}
	if err := f.m.RemoveFor(context.Background(), "shop1"); err != nil {
		t.Fatal(err)
	}
	if f.m.ownerOf("example.com") != "" {
		t.Error("shop1's domain is still set up")
	}
	if f.m.ownerOf("other.org") != "other" {
		t.Error("another account's domain went too")
	}
}

func TestOffMeansOff(t *testing.T) {
	f := newFixture(t)
	f.m.Enabled = false
	_, err := f.m.Apply(context.Background(), shop())
	var pe *protocol.Error
	if !errors.As(err, &pe) || pe.Code != CodeOff {
		t.Fatalf("got %v", err)
	}
	if err := f.m.RemoveFor(context.Background(), "shop1"); err != nil {
		t.Errorf("terminating must still work without mail: %v", err)
	}
}

func TestHostTLSCopiesTheCertificateOnceAndReloads(t *testing.T) {
	f := newFixture(t)
	if err := os.MkdirAll(filepath.Dir(f.m.HostCert), 0o700); err != nil {
		t.Fatal(err)
	}
	_ = os.WriteFile(f.m.HostCert, []byte("CERT"), 0o644)
	_ = os.WriteFile(f.m.HostKey, []byte("KEY"), 0o600)
	if err := f.m.HostTLS(context.Background()); err != nil {
		t.Fatal(err)
	}
	if f.read(t, f.m.TLSDir, "host.key") != "KEY" || f.read(t, f.m.TLSDir, "host.crt") != "CERT" {
		t.Error("not copied")
	}
	reloads := func() int {
		n := 0
		for _, c := range f.run.calls {
			if strings.HasPrefix(c, "systemctl reload-or-restart") {
				n++
			}
		}
		return n
	}
	if reloads() != 2 {
		t.Errorf("calls: %v", f.run.calls)
	}
	if err := f.m.HostTLS(context.Background()); err != nil {
		t.Fatal(err)
	}
	if reloads() != 2 {
		t.Error("an unchanged certificate reloaded the servers")
	}
}

func TestSettingsWriteTheRelayHostInEximsForm(t *testing.T) {
	f := newFixture(t)
	if _, err := f.m.Settings(context.Background(), SettingsArgs{Smarthost: "Relay.Example.net:587"}); err != nil {
		t.Fatal(err)
	}
	if got := f.read(t, f.m.EximDir, "smarthost"); got != "relay.example.net::587" {
		t.Errorf("smarthost: %q", got)
	}
	if _, err := f.m.Settings(context.Background(), SettingsArgs{Smarthost: "10.0.0.5"}); err != nil {
		t.Fatal(err)
	}
	if got := f.read(t, f.m.EximDir, "smarthost"); got != "10.0.0.5::25" {
		t.Errorf("smarthost: %q", got)
	}
	for _, bad := range []string{"a b", "host:0", "host:x", "${run{/bin/sh}}", "x::25", "[::1]:25"} {
		if _, err := f.m.Settings(context.Background(), SettingsArgs{Smarthost: bad}); err == nil {
			t.Errorf("%q was taken", bad)
		}
	}
	if _, err := f.m.Settings(context.Background(), SettingsArgs{}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(f.m.EximDir, "smarthost")); err == nil {
		t.Error("the relay host is still set")
	}
}
