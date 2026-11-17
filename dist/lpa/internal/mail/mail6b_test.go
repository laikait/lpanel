package mail

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// replyRun answers each command with the output its reply function gives.
type replyRun struct {
	calls []string
	reply func(cmd string) (int, string)
}

func (r *replyRun) Run(_ context.Context, tool string, args ...string) (int, string, error) {
	cmd := tool + " " + strings.Join(args, " ")
	r.calls = append(r.calls, cmd)
	if tool == "sievec" && len(args) == 2 {
		_ = os.WriteFile(args[1], []byte("compiled"), 0o600)
	}
	if r.reply != nil {
		code, out := r.reply(cmd)
		return code, out, nil
	}
	return 0, "", nil
}

func TestSpamFiltersAndResponderShareOneScript(t *testing.T) {
	f := newFixture(t)
	a := shop()
	a.Spam = Spam{Junk: 60, Delete: 150, Allow: []string{"Boss@Partner.example", "@friends.example"}, Block: []string{"@spam.example"}}
	a.Domains[0].Mailboxes[1].Filters = []Filter{
		{Field: "subject", Match: "contains", Value: `Invoice "Q4"`, Action: FilterFolder, Target: "Work.Billing", Stop: true},
		{Field: "from", Match: "begins", Value: "news*", Action: FilterDiscard},
		{Field: "to", Match: "is", Value: "list@example.org", Action: FilterForward, Target: "Archive@Example.net"},
	}
	if _, err := f.m.Apply(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	s := f.read(t, f.m.DovecotDir, "sieve", "example.com", "bob.smith.sieve")
	order := []string{
		`address :domain :is ["from", "sender"] ["spam.example"]`,
		`if not anyof (address :all :is ["from", "sender"] ["boss@partner.example"], address :domain :is ["from", "sender"] ["friends.example"]) {`,
		`if header :value "ge" :comparator "i;ascii-numeric" "X-LPanel-Spam-Score" "150" {`,
		`if header :value "ge" :comparator "i;ascii-numeric" "X-LPanel-Spam-Score" "60" {`,
		`fileinto :create "Junk";`,
		`if header :contains "subject" "Invoice \"Q4\"" {`,
		`fileinto :create "Work.Billing";`,
		`if header :matches "from" "news\\**" {`,
		`if address :all :is ["to", "cc"] "list@example.org" {`,
		`redirect :copy "Archive@example.net";`,
		`vacation :days 1`,
	}
	at := 0
	for _, want := range order {
		i := strings.Index(s[at:], want)
		if i < 0 {
			t.Fatalf("missing, or out of order: %s\n%s", want, s)
		}
		at += i + len(want)
	}
	// Every mailbox files spam, so info@ has a script too (without filters).
	info := f.read(t, f.m.DovecotDir, "sieve", "example.com", "info.sieve")
	if !strings.Contains(info, `"Junk"`) || strings.Contains(info, "vacation :days") || strings.Contains(info, "redirect") {
		t.Errorf("info's script:\n%s", info)
	}

	// Without spam settings, filters or an autoresponder, no script stays.
	a.Spam = Spam{}
	a.Domains[0].Mailboxes[1].Filters = nil
	a.Domains[0].Mailboxes[1].Autoresponder = nil
	if _, err := f.m.Apply(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(f.m.DovecotDir, "sieve", "example.com")); err == nil {
		t.Error("scripts are left with nothing to do")
	}
}

func TestBadFiltersAndSendersAreRefused(t *testing.T) {
	f := newFixture(t)
	bad := []Filter{
		{Field: "body", Match: "contains", Value: "x", Action: FilterDiscard},
		{Field: "from", Match: "regex", Value: "x", Action: FilterDiscard},
		{Field: "from", Match: "is", Value: " ", Action: FilterDiscard},
		{Field: "from", Match: "is", Value: "a\nb", Action: FilterDiscard},
		{Field: "from", Match: "is", Value: "x", Action: "reject"},
		{Field: "from", Match: "is", Value: "x", Action: FilterForward, Target: "|/bin/sh"},
		{Field: "from", Match: "is", Value: "x", Action: FilterFolder, Target: "../etc"},
		{Field: "from", Match: "is", Value: "x", Action: FilterFolder, Target: "a/b"},
		{Field: "from", Match: "is", Value: "x", Action: FilterFolder, Target: ".hidden"},
		{Field: "from", Match: "is", Value: "x", Action: FilterFolder, Target: `say "hi"`},
		{Field: "from", Match: "is", Value: "x", Action: FilterFolder, Target: ""},
	}
	for _, b := range bad {
		a := shop()
		a.Domains[0].Mailboxes[0].Filters = []Filter{b}
		if _, err := f.m.Apply(context.Background(), a); err == nil {
			t.Errorf("filter %+v was taken", b)
		}
	}
	for _, s := range []Spam{
		{Junk: -1}, {Junk: 60, Delete: 50}, {Junk: 2000},
		{Allow: []string{"not an address"}}, {Block: []string{"@"}}, {Block: []string{"a@x.example", "A@X.example"}},
	} {
		a := shop()
		a.Spam = s
		if _, err := f.m.Apply(context.Background(), a); err == nil {
			t.Errorf("spam settings %+v were taken", s)
		}
	}
	a := shop()
	a.Limit = -5
	if _, err := f.m.Apply(context.Background(), a); err == nil {
		t.Error("a negative limit was taken")
	}
}

func TestTheAccountFileCarriesTheLimit(t *testing.T) {
	f := newFixture(t)
	a := shop()
	a.Limit = 250
	if _, err := f.m.Apply(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	if got := f.read(t, f.m.EximDir, "accounts", "shop1"); !strings.Contains(got, "\nlimit: 250\n") {
		t.Errorf("accounts/shop1: %q", got)
	}
	if err := f.m.RemoveFor(context.Background(), "shop1"); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(f.m.EximDir, "accounts", "shop1")); err == nil {
		t.Error("a terminated account's settings stay")
	}
}

func TestRelayLoginIsWrittenReadyToSend(t *testing.T) {
	f := newFixture(t)
	ctx := context.Background()
	if _, err := f.m.Settings(ctx, SettingsArgs{Smarthost: "relay.example.net:587", User: "api:key", Password: `p^a"s\s`}); err != nil {
		t.Fatal(err)
	}
	if got := f.read(t, f.m.EximDir, "smarthost_auth"); got != `^api:key^p^^a"s\s` {
		t.Errorf("smarthost_auth: %q", got)
	}
	// The password is kept when only the host changes.
	if _, err := f.m.Settings(ctx, SettingsArgs{Smarthost: "relay2.example.net:2525", User: "api:key", KeepPassword: true}); err != nil {
		t.Fatal(err)
	}
	if login, _ := readRelayLogin(filepath.Join(f.m.EximDir, "smarthost_login")); login[1] != `p^a"s\s` {
		t.Errorf("kept password: %q", login[1])
	}
	for _, bad := range []SettingsArgs{
		{Smarthost: "relay.example.net", User: "u", Password: ""},
		{Smarthost: "relay.example.net", User: "u", Password: "line\nbreak"},
		{Smarthost: "relay.example.net", User: "ü", Password: "x"},
	} {
		if _, err := f.m.Settings(ctx, bad); err == nil {
			t.Errorf("%+v was taken", bad)
		}
	}
	if _, err := f.m.Settings(ctx, SettingsArgs{Smarthost: "relay.example.net"}); err != nil {
		t.Fatal(err)
	}
	for _, name := range []string{"smarthost_auth", "smarthost_login"} {
		if _, err := os.Stat(filepath.Join(f.m.EximDir, name)); err == nil {
			t.Errorf("%s stays without a user", name)
		}
	}
	if _, err := f.m.Settings(ctx, SettingsArgs{Smarthost: "relay.example.net", User: "u", KeepPassword: true}); err == nil {
		t.Error("a password that is not there was kept")
	}
}

func TestFilteringStartsServicesBeforeEximUsesThem(t *testing.T) {
	f := newFixture(t)
	run := &replyRun{}
	f.m.Run = run
	f.m.RspamdDir = filepath.Join(f.etc, "rspamd", "local.d")
	ctx := context.Background()
	if _, err := f.m.Filtering(ctx, FilteringArgs{Virus: true}); err == nil {
		t.Error("virus scanning without spam filtering was taken")
	}
	if _, err := f.m.Filtering(ctx, FilteringArgs{Spam: true, Virus: true, Reject: 120}); err != nil {
		t.Fatal(err)
	}
	if got := f.read(t, f.m.RspamdDir, "actions.conf"); !strings.Contains(got, "reject = 12.0;") {
		t.Errorf("actions.conf: %s", got)
	}
	if got := f.read(t, f.m.RspamdDir, "antivirus.conf"); !strings.Contains(got, `servers = "/run/clamd.scan/clamd.sock"`) {
		t.Errorf("antivirus.conf: %s", got)
	}
	if !exists(filepath.Join(f.m.EximDir, "filter")) {
		t.Error("Exim is not told to filter")
	}
	joined := strings.Join(run.calls, "\n")
	if !strings.Contains(joined, "systemctl enable --now --quiet clamav-freshclam clamd@scan") || !strings.Contains(joined, "systemctl enable --now --quiet rspamd") {
		t.Errorf("calls:\n%s", joined)
	}
	info := Info{}
	f.m.filteringInfo(ctx, &info)
	if !info.Filtering.Spam || !info.Filtering.Virus || info.Filtering.Reject != 120 {
		t.Errorf("info: %+v", info.Filtering)
	}

	run.calls = nil
	if _, err := f.m.Filtering(ctx, FilteringArgs{}); err != nil {
		t.Fatal(err)
	}
	if exists(filepath.Join(f.m.EximDir, "filter")) {
		t.Error("Exim still filters")
	}
	if got := f.read(t, f.m.RspamdDir, "antivirus.conf"); strings.Contains(got, "clamav {") {
		t.Error("ClamAV is still configured")
	}
	if !strings.Contains(strings.Join(run.calls, "\n"), "systemctl disable --now --quiet rspamd") {
		t.Errorf("calls: %v", run.calls)
	}
	if _, err := f.m.Filtering(ctx, FilteringArgs{Spam: true, Reject: 10}); err == nil {
		t.Error("a rejection score of 1 was taken")
	}
}

const queueOut = ` 0m   1.2K 1tXyZa-00000000ABC-ABCD <info@example.com>
          someone@gmail.com
          other@yahoo.com

25h   2.1M 1tXyZb-00000000ACD-ABCD <> *** frozen ***
       D  done@example.org
          bounce@nowhere.example

`

func TestQueueIsParsedAndSearched(t *testing.T) {
	f := newFixture(t)
	f.m.Run = &replyRun{reply: func(cmd string) (int, string) { return 0, queueOut }}
	list, err := f.m.Queue(context.Background(), QueueArgs{})
	if err != nil {
		t.Fatal(err)
	}
	if list.Total != 2 || list.Frozen != 1 || len(list.Messages) != 2 {
		t.Fatalf("list: %+v", list)
	}
	a, b := list.Messages[0], list.Messages[1]
	if a.ID != "1tXyZa-00000000ABC-ABCD" || a.Sender != "info@example.com" || len(a.Recipients) != 2 || a.Size != 1228 {
		t.Errorf("first: %+v", a)
	}
	if !b.Frozen || b.Sender != "" || b.Done != 1 || len(b.Recipients) != 1 || b.Age != 25*3600 {
		t.Errorf("second: %+v", b)
	}
	list, _ = f.m.Queue(context.Background(), QueueArgs{Search: "YAHOO"})
	if len(list.Messages) != 1 || list.Total != 2 {
		t.Errorf("search: %+v", list)
	}
}

func TestQueueActionsCheckIDs(t *testing.T) {
	f := newFixture(t)
	run := &replyRun{}
	f.m.Run = run
	ctx := context.Background()
	for _, ids := range [][]string{nil, {"-qff"}, {"1tXyZa-00000000ABC-ABCD", "--help"}, {"../../etc"}} {
		if _, err := f.m.QueueAct(ctx, QueueActArgs{Action: "remove", IDs: ids}); err == nil {
			t.Errorf("%v was taken", ids)
		}
	}
	if _, err := f.m.QueueAct(ctx, QueueActArgs{Action: "run", IDs: []string{"1tXyZa-00000000ABC-ABCD"}}); err == nil {
		t.Error("an unknown action was taken")
	}
	if _, err := f.m.QueueAct(ctx, QueueActArgs{Action: "remove", IDs: []string{"1tXyZa-00000000ABC-ABCD", "1tXyZa-00000000ABC-ABCD", "1aBcDe-0000Ab-Cd"}}); err != nil {
		t.Fatal(err)
	}
	if _, err := f.m.QueueAct(ctx, QueueActArgs{Action: "deliver", IDs: []string{"1tXyZa-00000000ABC-ABCD"}}); err != nil {
		t.Fatal(err)
	}
	want := []string{"exim -Mrm 1aBcDe-0000Ab-Cd 1tXyZa-00000000ABC-ABCD", "systemd-run --no-block --collect --quiet /usr/sbin/exim -M 1tXyZa-00000000ABC-ABCD"}
	if strings.Join(run.calls, "\n") != strings.Join(want, "\n") {
		t.Errorf("calls:\n%s", strings.Join(run.calls, "\n"))
	}
	if _, err := f.m.QueueMessage(ctx, QueueViewArgs{ID: "x; rm -rf /"}); err == nil {
		t.Error("a bad id was shown")
	}
}

func TestTrackingShowsAnAccountOnlyItsOwnMail(t *testing.T) {
	f := newFixture(t)
	f.m.LogDir = filepath.Join(f.etc, "log")
	if err := os.MkdirAll(f.m.LogDir, 0o755); err != nil {
		t.Fatal(err)
	}
	if _, err := f.m.Apply(context.Background(), shop()); err != nil {
		t.Fatal(err)
	}
	now := time.Now().UTC()
	at := func(minutes int) string {
		return now.Add(time.Duration(minutes) * time.Minute).Format("2006-01-02 15:04:05")
	}
	log := strings.Join([]string{
		at(-50) + " 1tAAAA-00000000AAA-AAAA <= info@example.com H=(client) [10.0.0.9]:50000 P=esmtpsa A=dovecot_plain:info@example.com S=2048 for a@gmail.com b@yahoo.com",
		at(-49) + " 1tAAAA-00000000AAA-AAAA => a@gmail.com F=<info@example.com> R=dnslookup T=remote_smtp S=2100 H=mx.gmail.com [1.2.3.4]:25 C=\"250 OK\"",
		at(-49) + " 1tAAAA-00000000AAA-AAAA == b@yahoo.com R=dnslookup T=remote_smtp defer (-44) H=mx.yahoo.com [5.6.7.8]: SMTP error from remote mail server after RCPT TO:<b@yahoo.com>: 451 try later",
		at(-40) + " 1tBBBB-00000000BBB-BBBB <= x@outside.example H=mx.outside.example [9.9.9.9]:4000 P=esmtps S=900 for info@example.com other@elsewhere.example",
		at(-40) + " 1tBBBB-00000000BBB-BBBB => info <info@example.com> R=lpanel_mailbox T=dovecot_lmtp",
		at(-40) + " 1tBBBB-00000000BBB-BBBB ** other@elsewhere.example R=dnslookup T=remote_smtp: SMTP error from remote mail server after RCPT TO:<other@elsewhere.example>: 550 no such user",
		at(-30) + " 1tCCCC-00000000CCC-CCCC <= z@stranger.example H=x [8.8.8.8]:1 P=esmtp S=10 for q@stranger2.example",
		at(-20) + " H=(spammer) [6.6.6.6]:1234 I=[10.0.0.1]:25 F=<spam@bad.example> rejected RCPT <nobody@example.com>: Unrouteable address",
		at(-10) + " 1tDDDD-00000000DDD-DDDD H=(spammer) [6.6.6.6]:1234 I=[10.0.0.1]:25 F=<spam@bad.example> rejected after DATA: This message was refused as spam for info@example.com, x@other.example",
	}, "\n") + "\n"
	if err := os.WriteFile(filepath.Join(f.m.LogDir, "main.log"), []byte(log), 0o644); err != nil {
		t.Fatal(err)
	}
	all, err := f.m.Track(context.Background(), TrackArgs{})
	if err != nil {
		t.Fatal(err)
	}
	if len(all.Deliveries) != 8 {
		t.Errorf("the administrator sees %d deliveries: %+v", len(all.Deliveries), all.Deliveries)
	}
	own, err := f.m.Track(context.Background(), TrackArgs{Username: "shop1"})
	if err != nil {
		t.Fatal(err)
	}
	got := map[string][]string{}
	for _, d := range own.Deliveries {
		got[d.To] = append(got[d.To], d.Status+" "+d.Detail)
	}
	want := map[string]string{
		"a@gmail.com":        "delivered mx.gmail.com",
		"b@yahoo.com":        "deferred SMTP error from remote mail server after RCPT TO:<b@yahoo.com>: 451 try later",
		"info@example.com":   "rejected This message was refused as spam",
		"nobody@example.com": "rejected Unrouteable address",
	}
	want["info@example.com "] = "delivered mailbox"
	for to, w := range want {
		if !contains(got[strings.TrimSpace(to)], w) {
			t.Errorf("%s: got %q, want %q", to, got[strings.TrimSpace(to)], w)
		}
	}
	// Inbound mail shows only the account's own recipients; strangers' mail
	// not at all.
	for _, hidden := range []string{"other@elsewhere.example", "q@stranger2.example", "x@other.example"} {
		if _, ok := got[hidden]; ok {
			t.Errorf("the account sees %s", hidden)
		}
	}
	if own.Deliveries[0].Status != "rejected" {
		t.Errorf("not newest first: %+v", own.Deliveries[0])
	}
	failed, _ := f.m.Track(context.Background(), TrackArgs{Status: "failed"})
	if len(failed.Deliveries) != 1 || failed.Deliveries[0].To != "other@elsewhere.example" {
		t.Errorf("failed: %+v", failed.Deliveries)
	}
	if _, err := f.m.Track(context.Background(), TrackArgs{Days: 90}); err == nil {
		t.Error("90 days were taken")
	}
}
