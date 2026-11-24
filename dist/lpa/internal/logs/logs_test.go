package logs

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"lpa/internal/protocol"
)

type fakeRun struct {
	calls    []string
	journal  string
	phpOut   string
	phpCode  int
	goaccess func(args []string) (int, string)
}

func (f *fakeRun) Run(_ context.Context, tool string, args ...string) (int, string, error) {
	f.calls = append(f.calls, tool+" "+strings.Join(args, " "))
	switch tool {
	case "journalctl":
		return 0, f.journal, nil
	case "runuser":
		return f.phpCode, f.phpOut, nil
	case "goaccess":
		if f.goaccess != nil {
			code, out := f.goaccess(args)
			return code, out, nil
		}
	}
	return 1, "unexpected " + tool, nil
}

func setup(t *testing.T) (*Manager, *fakeRun, string) {
	t.Helper()
	dir := t.TempDir()
	access := filepath.Join(dir, "nginx")
	errs := filepath.Join(dir, "httpd")
	for _, d := range []string{access, errs} {
		if err := os.MkdirAll(d, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	run := &fakeRun{}
	m := &Manager{
		Run:       run,
		AccessDir: func() string { return access },
		ErrorDirs: func() []string { return []string{errs} },
		Home:      func(u string) string { return filepath.Join(dir, "home", u) },
		State:     filepath.Join(dir, "state"),
		Files:     []file{{"maillog", "Mail", "mail", filepath.Join(dir, "maillog")}, {"secure", "SSH", "system", filepath.Join(dir, "absent")}},
		Now:       func() time.Time { return time.Date(2026, 11, 24, 12, 0, 0, 0, time.UTC) },
	}
	return m, run, dir
}

func code(err error) string {
	if e, ok := err.(*protocol.Error); ok {
		return e.Code
	}
	if err == nil {
		return ""
	}
	return "other: " + err.Error()
}

func TestSourcesListWhatExistsAndNothingElse(t *testing.T) {
	m, _, dir := setup(t)
	if err := os.WriteFile(filepath.Join(dir, "maillog"), []byte("x\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	list, err := m.Sources(context.Background(), "")
	if err != nil {
		t.Fatal(err)
	}
	names := map[string]bool{}
	for _, s := range list {
		names[s.Name] = true
	}
	if !names["journal:httpd"] || !names["file:maillog"] || names["file:secure"] {
		t.Fatalf("sources: %v", names)
	}

	// An account sees its own three, never the system's.
	if err := os.WriteFile(filepath.Join(m.AccessDir(), "ana.access.log"), []byte("x\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	mine, err := m.Sources(context.Background(), "ana")
	if err != nil {
		t.Fatal(err)
	}
	var got []string
	for _, s := range mine {
		got = append(got, s.Name)
	}
	if strings.Join(got, " ") != "web.access web.error php.error" {
		// The error log of a site that has had no error yet is not listed; PHP's is read as the account.
		if strings.Join(got, " ") != "web.access php.error" {
			t.Fatalf("account sources: %v", got)
		}
	}
	if _, err := m.Sources(context.Background(), "../etc"); code(err) != protocol.CodeBadArgs {
		t.Fatalf("a bad account name: %v", err)
	}
}

func TestReadGivesTheLatestLinesFilteredAndCleaned(t *testing.T) {
	m, _, dir := setup(t)
	var b strings.Builder
	for i := 1; i <= 50; i++ {
		fmt.Fprintf(&b, "line %d %s\n", i, map[bool]string{true: "ERROR", false: "ok"}[i%10 == 0])
	}
	b.WriteString("evil \x1b[31mred\x00 line\n")
	if err := os.WriteFile(filepath.Join(dir, "maillog"), []byte(b.String()), 0o644); err != nil {
		t.Fatal(err)
	}

	r, err := m.Read(context.Background(), ReadArgs{Source: "file:maillog", Lines: 3})
	if err != nil {
		t.Fatal(err)
	}
	if len(r.Lines) != 3 || r.Lines[0] != "line 49 ok" || strings.ContainsAny(r.Lines[2], "\x1b\x00") {
		t.Fatalf("lines: %q", r.Lines)
	}

	r, err = m.Read(context.Background(), ReadArgs{Source: "file:maillog", Lines: 10, Filter: "error"})
	if err != nil {
		t.Fatal(err)
	}
	if len(r.Lines) != 5 || r.Lines[0] != "line 10 ERROR" || r.Lines[4] != "line 50 ERROR" {
		t.Fatalf("filtered: %q", r.Lines)
	}
}

func TestReadRefusesWhatIsNotInTheList(t *testing.T) {
	m, run, _ := setup(t)
	ctx := context.Background()
	for _, a := range []ReadArgs{
		{Source: "file:../../etc/shadow"},
		{Source: "file:shadow"},
		{Source: "/etc/shadow"},
		{Source: "journal:sshd.service; reboot"},
		{Source: "journal:../x"},
		{Source: "web.access"},                    // an account's, with no account
		{Source: "web.access", User: "../../etc"}, // not an account name
		{Source: "file:maillog", User: "ana"},     // a system log with an account
		{Source: "journal:httpd", User: "ana"},    // an account has no journal
		{Source: "file:maillog", Lines: 5000},     // too many
		{Source: "file:maillog", Filter: strings.Repeat("x", 200)},
		{Source: "file:maillog", Filter: "a\nb"},
	} {
		if _, err := m.Read(ctx, a); err == nil {
			t.Errorf("%+v was accepted", a)
		}
	}
	if len(run.calls) != 0 {
		t.Fatalf("something was run: %v", run.calls)
	}
}

func TestTheJournalIsAskedForAKnownUnitOnly(t *testing.T) {
	m, run, _ := setup(t)
	run.journal = "2026-11-24T10:00:00+0000 httpd[1]: started\n2026-11-24T10:00:01+0000 httpd[1]: ERROR boom\n"
	r, err := m.Read(context.Background(), ReadArgs{Source: "journal:httpd", Filter: "boom"})
	if err != nil {
		t.Fatal(err)
	}
	if len(r.Lines) != 1 || !strings.Contains(r.Lines[0], "boom") {
		t.Fatalf("lines: %q", r.Lines)
	}
	if got := run.calls[0]; !strings.HasPrefix(got, "journalctl -u httpd.service -n 5000 ") {
		t.Fatalf("ran %q", got)
	}
}

func TestAnAccountsPHPLogIsReadAsTheAccount(t *testing.T) {
	m, run, _ := setup(t)
	run.phpOut = "PHP Warning: x\nPHP Fatal: y\n"
	r, err := m.Read(context.Background(), ReadArgs{Source: "php.error", User: "ana"})
	if err != nil {
		t.Fatal(err)
	}
	if len(r.Lines) != 2 {
		t.Fatalf("lines: %q", r.Lines)
	}
	call := run.calls[0]
	if !strings.HasPrefix(call, "runuser -u ana -- /usr/bin/tail -c ") || !strings.HasSuffix(call, filepath.Join("home", "ana", "logs", "php-error.log")) {
		t.Fatalf("ran %q: the account's file must be read with the account's rights", call)
	}

	run.phpCode, run.phpOut = 1, "tail: cannot open"
	if _, err := m.Read(context.Background(), ReadArgs{Source: "php.error", User: "ana"}); code(err) != CodeMissing {
		t.Fatalf("a missing log: %v", err)
	}
}

func TestAnAccountsSiteLogsAreItsOwn(t *testing.T) {
	m, _, _ := setup(t)
	for _, u := range []string{"ana", "bob"} {
		if err := os.WriteFile(filepath.Join(m.AccessDir(), u+".access.log"), []byte("visit of "+u+"\n"), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	r, err := m.Read(context.Background(), ReadArgs{Source: "web.access", User: "ana"})
	if err != nil {
		t.Fatal(err)
	}
	if len(r.Lines) != 1 || r.Lines[0] != "visit of ana" {
		t.Fatalf("lines: %q", r.Lines)
	}
}

// ---- statistics ------------------------------------------------------------

const logLine = `shop.example.com 203.0.113.5 - - [%s] "GET /index.php HTTP/1.1" 200 5120 300 "-" "Mozilla/5.0"` + "\n"

func writeAccess(t *testing.T, m *Manager, user string, days ...int) {
	t.Helper()
	var b strings.Builder
	for _, d := range days {
		at := m.Now().AddDate(0, 0, -d)
		fmt.Fprintf(&b, logLine, at.Format("02/Jan/2006:15:04:05 +0000"))
	}
	if err := os.WriteFile(filepath.Join(m.AccessDir(), user+".access.log"), []byte(b.String()), 0o644); err != nil {
		t.Fatal(err)
	}
}

const goaccessJSON = `{
 "general": {"total_requests": 3, "valid_requests": 3, "failed_requests": 0, "unique_visitors": 2, "bandwidth": 15360},
 "visitors": {"data": [
   {"data": "20261124", "hits": {"count": 2, "percent": 66.6}, "visitors": {"count": 1}, "bytes": {"count": 10240}},
   {"data": "20261123", "hits": {"count": 1, "percent": 33.3}, "visitors": {"count": 1}, "bytes": {"count": 5120}}]},
 "requests": {"data": [{"data": "/index.php", "hits": {"count": 3}, "visitors": {"count": 2}, "bytes": {"count": 15360}}]},
 "status_codes": {"data": [{"data": "2xx Success", "hits": {"count": 3}, "visitors": {"count": 2}}]},
 "browsers": {"data": [{"data": "Firefox", "hits": 3, "visitors": 2}]},
 "referring_sites": {"data": []}
}`

func goaccessWrites(seen *[]string, lines *int) func([]string) (int, string) {
	return func(args []string) (int, string) {
		*seen = args
		var in, out string
		for i, a := range args {
			if a == "-f" {
				in = args[i+1]
			}
			if a == "-o" {
				out = args[i+1]
			}
		}
		data, err := os.ReadFile(in)
		if err != nil {
			return 1, "no input"
		}
		*lines = strings.Count(string(data), "\n")
		if err := os.WriteFile(out, []byte(goaccessJSON), 0o600); err != nil {
			return 1, err.Error()
		}
		return 0, ""
	}
}

func TestReportKeepsTheDaysAskedForAndParsesGoAccess(t *testing.T) {
	m, run, _ := setup(t)
	writeAccess(t, m, "ana", 0, 0, 1, 5, 40) // today x2, yesterday, 5 and 40 days ago
	var args []string
	var lines int
	run.goaccess = goaccessWrites(&args, &lines)

	rep, err := m.Report(context.Background(), ReportArgs{User: "ana", Days: 7})
	if err != nil {
		t.Fatal(err)
	}
	if lines != 4 {
		t.Fatalf("GoAccess was given %d lines, want the 4 of the last 7 days", lines)
	}
	if rep.Requests != 3 || rep.Visitors != 2 || rep.Bandwidth != 15360 || rep.Scope != "ana" || rep.Days != 7 {
		t.Fatalf("report: %+v", rep)
	}
	if len(rep.Daily) != 2 || rep.Daily[0].Date != "2026-11-23" || rep.Daily[1].Hits != 2 {
		t.Fatalf("daily: %+v", rep.Daily)
	}
	if len(rep.Pages) != 1 || rep.Pages[0].Name != "/index.php" || rep.Browsers[0].Hits != 3 {
		t.Fatalf("lists: %+v %+v", rep.Pages, rep.Browsers)
	}
	joined := strings.Join(args, " ")
	for _, want := range []string{"--log-format=%v %h %^ %e [%d:%t %^]", "--date-format=%d/%b/%Y", "--time-format=%T", "--no-global-config"} {
		if !strings.Contains(joined, want) {
			t.Errorf("GoAccess was run without %q: %v", want, args)
		}
	}
}

func TestReportIsKeptAndRefreshed(t *testing.T) {
	m, run, _ := setup(t)
	writeAccess(t, m, "ana", 0)
	var args []string
	var lines int
	calls := 0
	inner := goaccessWrites(&args, &lines)
	run.goaccess = func(a []string) (int, string) { calls++; return inner(a) }

	for i := 0; i < 3; i++ {
		if _, err := m.Report(context.Background(), ReportArgs{User: "ana"}); err != nil {
			t.Fatal(err)
		}
	}
	if calls != 1 {
		t.Fatalf("GoAccess ran %d times for three asks", calls)
	}
	if _, err := m.Report(context.Background(), ReportArgs{User: "ana", Refresh: true}); err != nil || calls != 2 {
		t.Fatalf("refresh: %d, %v", calls, err)
	}
	// The kept report is for that account and those days only.
	if _, err := m.Report(context.Background(), ReportArgs{User: "ana", Days: 7}); err != nil || calls != 3 {
		t.Fatalf("other days: %d, %v", calls, err)
	}
}

func TestReportWithNoVisitsDoesNotRunGoAccess(t *testing.T) {
	m, run, _ := setup(t)
	run.goaccess = func([]string) (int, string) { t.Fatal("GoAccess was run for nothing"); return 1, "" }
	rep, err := m.Report(context.Background(), ReportArgs{User: "ana"})
	if err != nil {
		t.Fatal(err)
	}
	if rep.Requests != 0 || rep.Daily != nil {
		t.Fatalf("report: %+v", rep)
	}
}

func TestReportRefusesBadArgumentsAndReportsFailures(t *testing.T) {
	m, run, _ := setup(t)
	for _, a := range []ReportArgs{{User: "../x"}, {User: "ana", Days: 400}, {Days: -1}} {
		if _, err := m.Report(context.Background(), a); code(err) != protocol.CodeBadArgs {
			t.Errorf("%+v: %v", a, err)
		}
	}
	writeAccess(t, m, "ana", 0)
	run.goaccess = func([]string) (int, string) { return 1, "unknown option --x" }
	if _, err := m.Report(context.Background(), ReportArgs{User: "ana"}); code(err) != CodeGoAccess {
		t.Fatalf("a failing GoAccess: %v", err)
	}
}

func TestReportForAllReadsEveryAccountsLogAndIgnoresOtherFiles(t *testing.T) {
	m, run, _ := setup(t)
	writeAccess(t, m, "ana", 0)
	writeAccess(t, m, "bob", 0, 0)
	// Not an account's log: a name that does not fit, or a link.
	if err := os.WriteFile(filepath.Join(m.AccessDir(), "x.access.log"), []byte(fmt.Sprintf(logLine, m.Now().Format("02/Jan/2006:15:04:05 +0000"))), 0o644); err != nil {
		t.Fatal(err)
	}
	var args []string
	var lines int
	run.goaccess = goaccessWrites(&args, &lines)
	rep, err := m.Report(context.Background(), ReportArgs{})
	if err != nil {
		t.Fatal(err)
	}
	if lines != 3 || rep.Scope != "all" {
		t.Fatalf("lines %d scope %s", lines, rep.Scope)
	}
	data, _ := json.Marshal(rep)
	if !strings.Contains(string(data), `"scope":"all"`) {
		t.Fatalf("json: %s", data)
	}
}
