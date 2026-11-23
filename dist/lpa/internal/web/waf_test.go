package web

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func wafSite(w *WAF) ApplyArgs {
	a := shop("83")
	a.Sites = a.Sites[:1]
	a.Sites[0].WAF = w
	return a
}

func applied(t *testing.T, m *Manager, a ApplyArgs) string {
	t.Helper()
	if _, err := m.Apply(context.Background(), a); err != nil {
		t.Fatalf("apply: %v", err)
	}
	return read(t, filepath.Join(m.Sites, "shop1.conf"))
}

func TestEverySiteSaysItsOwnFirewallMode(t *testing.T) {
	m, _, _, _ := setup(t)

	conf := applied(t, m, wafSite(nil))
	if !strings.Contains(conf, "SecRuleEngine DetectionOnly") || strings.Contains(conf, "SecRuleEngine On") {
		t.Fatalf("no choice is detection only, never the package's blocking default:\n%s", conf)
	}

	conf = applied(t, m, wafSite(&WAF{Mode: WAFBlock, Exclude: []int{942100, 941100, 920350}}))
	for _, want := range []string{
		"<IfModule security2_module>", "SecRuleEngine On\n", "SecRequestBodyLimitAction ProcessPartial",
		"SecRuleRemoveById 920350 941100 942100\n",
	} {
		if !strings.Contains(conf, want) {
			t.Fatalf("missing %q:\n%s", want, conf)
		}
	}

	conf = applied(t, m, wafSite(&WAF{Mode: WAFOff, Exclude: []int{942100}}))
	if !strings.Contains(conf, "SecRuleEngine Off") || strings.Contains(conf, "SecRuleRemoveById") || strings.Contains(conf, "SecRequestBodyLimit") {
		t.Fatalf("off says nothing else:\n%s", conf)
	}
}

func TestManyExcludedRulesAreWrittenTenToALine(t *testing.T) {
	m, _, _, _ := setup(t)
	var ids []int
	for i := 0; i < 25; i++ {
		ids = append(ids, 942000+i)
	}
	conf := applied(t, m, wafSite(&WAF{Mode: WAFBlock, Exclude: ids}))
	if n := strings.Count(conf, "SecRuleRemoveById"); n != 3 {
		t.Fatalf("25 rules in lines of ten: %d lines\n%s", n, conf)
	}
}

func TestTheFirewallSettingIsChecked(t *testing.T) {
	m, run, _, _ := setup(t)
	var many []int
	for i := 1; i <= MaxWAFExclusions+1; i++ {
		many = append(many, i)
	}
	for name, w := range map[string]*WAF{
		"a mode that is not one":      {Mode: "paranoid"},
		"an empty mode":               {},
		"rule zero":                   {Mode: WAFBlock, Exclude: []int{0}},
		"a negative rule":             {Mode: WAFBlock, Exclude: []int{-5}},
		"a rule number far too large": {Mode: WAFBlock, Exclude: []int{100000000}},
		"a rule twice":                {Mode: WAFBlock, Exclude: []int{942100, 942100}},
		"too many rules":              {Mode: WAFBlock, Exclude: many},
	} {
		run.calls = nil
		if got := code(applyErr(m, wafSite(w))); got != "bad_args" {
			t.Errorf("%s: %q", name, got)
		}
		if run.ran("systemctl reload") != 0 {
			t.Errorf("%s: something was reloaded", name)
		}
	}
}

func applyErr(m *Manager, a ApplyArgs) error {
	_, err := m.Apply(context.Background(), a)
	return err
}

func TestNginxAloneWritesNoFirewallLines(t *testing.T) {
	m, _, _, root := setup(t)
	m.Server = "nginx"
	if _, err := m.Apply(context.Background(), wafSite(&WAF{Mode: WAFBlock})); err != nil {
		t.Fatal(err)
	}
	for _, f := range []string{filepath.Join(root, "nginx", "lpanel", "sites", "shop1.conf")} {
		if strings.Contains(read(t, f), "SecRule") {
			t.Fatal("nginx has no ModSecurity")
		}
	}
}

func TestTheMainIncludeReadsTheParanoiaFileFirst(t *testing.T) {
	m, _, _, _ := setup(t)
	applied(t, m, wafSite(nil))
	inc := read(t, m.Include)
	want := "IncludeOptional \"" + filepath.Join(filepath.Dir(m.Sites), "waf.conf") + "\""
	if !strings.Contains(inc, "<IfModule security2_module>") || !strings.Contains(inc, want) {
		t.Fatalf("the include must read waf.conf:\n%s", inc)
	}
}

func TestWAFInfoSaysWhetherTheModuleIsThere(t *testing.T) {
	m, run, _, _ := setup(t)
	rules := t.TempDir()
	m.WAFRules = rules
	os.WriteFile(filepath.Join(rules, "REQUEST-901-INITIALIZATION.conf"), []byte("SecComponentSignature \"OWASP_CRS/3.3.5\"\nSecAction \"id:901001,ver:'OWASP_CRS/3.3.5',phase:1\"\n"), 0o644)

	run.out["httpd -M"] = " core_module (static)\n security2_module (shared)\n"
	info, err := m.WAFInfo(context.Background())
	if err != nil || !info.Available || info.Rules != "3.3.5" || info.Paranoia != 1 {
		t.Fatalf("%v %+v", err, info)
	}

	run.out["httpd -M"] = " core_module (static)\n"
	if info, _ = m.WAFInfo(context.Background()); info.Available {
		t.Fatal("no module, no firewall")
	}

	m.Server = "nginx"
	if info, _ = m.WAFInfo(context.Background()); info.Available || info.Server != "nginx" {
		t.Fatalf("nginx alone has none: %+v", info)
	}
}

func TestTheParanoiaLevelIsWrittenTestedAndReloaded(t *testing.T) {
	m, run, _, _ := setup(t)
	ctx := context.Background()
	run.out["httpd -M"] = " security2_module (shared)\n"

	info, err := m.SetWAF(ctx, WAFSettings{Paranoia: 3})
	if err != nil || info.Paranoia != 3 {
		t.Fatalf("%v %+v", err, info)
	}
	file := read(t, m.wafFile())
	if !strings.Contains(file, "tx.blocking_paranoia_level=3") || !strings.Contains(file, "tx.detection_paranoia_level=3") {
		t.Fatalf("%s", file)
	}
	if run.ran("httpd -t") != 1 || run.ran("systemctl reload-or-restart httpd") != 1 {
		t.Fatalf("%v", run.calls)
	}

	// The same again changes nothing and reloads nothing.
	run.calls = nil
	if _, err := m.SetWAF(ctx, WAFSettings{Paranoia: 3}); err != nil || len(run.calls) != 1 {
		t.Fatalf("only the info's module check: %v %v", err, run.calls)
	}

	// Apache refusing the file puts the old one back.
	run.fail["httpd -t"] = "Syntax error"
	if _, err := m.SetWAF(ctx, WAFSettings{Paranoia: 2}); code(err) != "waf_config" {
		t.Fatalf("%v", err)
	}
	if !strings.Contains(read(t, m.wafFile()), "level=3") {
		t.Fatal("the old setting must be back")
	}
	delete(run.fail, "httpd -t")

	for _, bad := range []int{0, 5, -1} {
		if code(func() error { _, err := m.SetWAF(ctx, WAFSettings{Paranoia: bad}); return err }()) != "bad_args" {
			t.Errorf("paranoia %d", bad)
		}
	}

	m.Server = "nginx"
	if _, err := m.SetWAF(ctx, WAFSettings{Paranoia: 1}); code(err) != "waf_unavailable" {
		t.Fatalf("%v", err)
	}
}

// What ModSecurity writes into a site's error log, as AlmaLinux 9's
// mod_security 2.9 with the Core Rule Set 3.3 does it.
const wafBlocked = `[Mon Nov 23 12:09:41.687304 2026] [:error] [pid 150:tid 238] [client 198.51.100.9:32778] [client 198.51.100.9] ModSecurity: Warning. detected XSS using libinjection. [file "/etc/httpd/modsecurity.d/activated_rules/REQUEST-941-APPLICATION-ATTACK-XSS.conf"] [line "56"] [id "941100"] [msg "XSS Attack Detected via libinjection"] [data "Matched Data: XSS data found within ARGS:q: <script>alert(1)</script>"] [severity "CRITICAL"] [ver "OWASP_CRS/3.3.5"] [hostname "shop.example.test"] [uri "/search"] [unique_id "AAA"]
[Mon Nov 23 12:09:41.687780 2026] [:error] [pid 150:tid 238] [client 198.51.100.9:32778] [client 198.51.100.9] ModSecurity: Warning. Pattern match "(?i)<script[^>]*>" at ARGS:q. [file "/etc/httpd/modsecurity.d/activated_rules/REQUEST-941-APPLICATION-ATTACK-XSS.conf"] [line "83"] [id "941110"] [msg "XSS Filter - Category 1: Script Tag Vector"] [severity "CRITICAL"] [ver "OWASP_CRS/3.3.5"] [hostname "shop.example.test"] [uri "/search"] [unique_id "AAA"]
[Mon Nov 23 12:09:41.690931 2026] [:error] [pid 150:tid 238] [client 198.51.100.9:32778] [client 198.51.100.9] ModSecurity: Access denied with code 403 (phase 2). Operator GE matched 5 at TX:anomaly_score. [file "/etc/httpd/modsecurity.d/activated_rules/REQUEST-949-BLOCKING-EVALUATION.conf"] [line "153"] [id "949110"] [msg "Inbound Anomaly Score Exceeded (Total Score: 15)"] [severity "CRITICAL"] [ver "OWASP_CRS/3.3.5"] [hostname "shop.example.test"] [uri "/search"] [unique_id "AAA"]
`

const wafDetected = `[Mon Nov 23 12:10:00.000001 2026] [:error] [pid 150:tid 240] [client 2001:db8::7:5000] [client 2001:db8::7] ModSecurity: Warning. Pattern match "union select" at ARGS:id. [file "x.conf"] [line "1"] [id "942100"] [msg "SQL Injection Attack \"Detected\""] [severity "CRITICAL"] [hostname "shop.example.test"] [uri "/item"] [unique_id "BBB"]
[Mon Nov 23 12:10:00.000900 2026] [:error] [pid 150:tid 240] [client 2001:db8::7:5000] [client 2001:db8::7] ModSecurity: Warning. Operator GE matched 5 at TX:anomaly_score. [file "x.conf"] [line "153"] [id "949110"] [msg "Inbound Anomaly Score Exceeded (Total Score: 10)"] [hostname "shop.example.test"] [uri "/item"] [unique_id "BBB"]
[Mon Nov 23 12:10:01.000000 2026] [core:notice] [pid 1] AH00094: Command line: 'httpd'
[Mon Nov 23 12:10:02.000000 2026] [php:error] [pid 2] [client 203.0.113.5:1] script not found
`

func TestWAFEventsAreOnePerRequest(t *testing.T) {
	m, _, _, _ := setup(t)
	os.MkdirAll(m.LogDir, 0o755)
	log := filepath.Join(m.LogDir, "shop1.error.log")
	os.WriteFile(log, []byte("[old] ModSecurity: Warning. [id \"1\"] [unique_id \"OLD\"]\n"), 0o644)
	ctx := context.Background()

	first, err := m.WAFEvents(ctx, WAFEventsArgs{})
	if err != nil || len(first.Events) != 0 {
		t.Fatalf("history is not reported: %v %+v", err, first.Events)
	}

	f, _ := os.OpenFile(log, os.O_APPEND|os.O_WRONLY, 0o644)
	f.WriteString(wafBlocked + wafDetected)
	f.Close()

	got, err := m.WAFEvents(ctx, WAFEventsArgs{Cursors: first.Cursors})
	if err != nil || len(got.Events) != 2 {
		t.Fatalf("%v %+v", err, got.Events)
	}
	a, b := got.Events[0], got.Events[1]
	if a.IP != "198.51.100.9" || a.Host != "shop.example.test" || a.URI != "/search" || !a.Blocked || a.Score != 15 || a.Account != "shop1" {
		t.Fatalf("%+v", a)
	}
	if len(a.Rules) != 2 || a.Rules[0].ID != 941100 || a.Rules[1].ID != 941110 {
		t.Fatalf("the summary rule is the score, not a rule: %+v", a.Rules)
	}
	if b.IP != "2001:db8::7" || b.Blocked || b.Score != 10 || len(b.Rules) != 1 || b.Rules[0].Msg != `SQL Injection Attack "Detected"` {
		t.Fatalf("detection only: %+v", b)
	}
	if !strings.HasPrefix(a.Time, "2026-11-23T") {
		t.Fatalf("time: %q", a.Time)
	}

	// Read once.
	again, _ := m.WAFEvents(ctx, WAFEventsArgs{Cursors: got.Cursors})
	if len(again.Events) != 0 {
		t.Fatalf("%+v", again.Events)
	}

	// A replaced log is read from its top.
	os.WriteFile(log, []byte(strings.Replace(wafDetected, "BBB", "CCC", -1)), 0o644)
	next, _ := m.WAFEvents(ctx, WAFEventsArgs{Cursors: again.Cursors})
	if len(next.Events) != 1 {
		t.Fatalf("%+v", next.Events)
	}
}
