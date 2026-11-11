package web

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func logLine(day string, sent, received int) string {
	return fmt.Sprintf("shop.example.test 203.0.113.9 - - [%s:10:00:00 +0000] \"GET /a\\\"b HTTP/1.1\" 200 %d %d \"-\" \"curl/8\"\n", day, sent, received)
}

func appendLog(t *testing.T, path, text string) {
	t.Helper()
	f, err := os.OpenFile(path, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o644)
	if err != nil {
		t.Fatal(err)
	}
	f.WriteString(text)
	f.Close()
}

func TestParseLogLine(t *testing.T) {
	day, n, ok := parseLogLine(logLine("11/Nov/2026", 1200, 300))
	if !ok || day != "2026-11-11" || n != 1500 {
		t.Fatalf("%s %d %v", day, n, ok)
	}
	for _, bad := range []string{"", "garbage\n", "x [11/Foo/2026:10:00:00 +0000] \"GET /\" 200 1 1\n", "x [11/Nov/2026:10:00:00 +0000] \"GET / 200 1 1\n", "x [11/Nov/2026:10:00:00 +0000] \"GET /\" 200 -5 1\n"} {
		if _, _, ok := parseLogLine(bad); ok {
			t.Errorf("read %q", bad)
		}
	}
}

func TestTrafficCountsEachByteOnceAcrossCallsAndRotation(t *testing.T) {
	m, _, _, root := setup(t)
	m.TrafficState = filepath.Join(root, "state", "traffic.json")
	ctx := context.Background()
	os.MkdirAll(m.LogDir, 0o755)
	log := filepath.Join(m.LogDir, "shop1.access.log")

	appendLog(t, log, logLine("10/Nov/2026", 1000, 100)+logLine("11/Nov/2026", 500, 0))
	// A line still being written is not counted yet.
	appendLog(t, log, "shop.example.test 203.0.113.9 - - [11/Nov/2026:10:00:00 +0000] \"GET")

	tr, err := m.Traffic(ctx)
	if err != nil || tr.Accounts["shop1"]["2026-11-10"] != 1100 || tr.Accounts["shop1"]["2026-11-11"] != 500 {
		t.Fatalf("%+v %v", tr, err)
	}
	// Asking again counts nothing twice.
	if tr, _ = m.Traffic(ctx); tr.Accounts["shop1"]["2026-11-11"] != 500 {
		t.Fatalf("%+v", tr)
	}

	if fileKey(log, mustStat(t, log)) == log {
		t.Skip("a renamed file is known by its inode, which only Linux has; the rest runs there")
	}

	// The partial line is finished, then the log is rotated (renamed, as
	// logrotate does) and Apache writes to a new one and, briefly, the old.
	appendLog(t, log, " / HTTP/1.1\" 200 70 30 \"-\" \"-\"\n")
	if err := os.Rename(log, log+".1"); err != nil {
		t.Fatal(err)
	}
	appendLog(t, log+".1", logLine("11/Nov/2026", 5, 5))
	appendLog(t, log, logLine("11/Nov/2026", 1, 1))

	tr, err = m.Traffic(ctx)
	if err != nil || tr.Accounts["shop1"]["2026-11-11"] != 500+100+10+2 || tr.Accounts["shop1"]["2026-11-10"] != 1100 {
		t.Fatalf("%+v %v", tr, err)
	}

	// Removing the account forgets its totals and its logs.
	if err := m.forgetTraffic("shop1"); err != nil {
		t.Fatal(err)
	}
	if tr, _ = m.Traffic(ctx); len(tr.Accounts) != 0 {
		t.Fatalf("%+v", tr)
	}
	if _, err := os.Stat(log + ".1"); !os.IsNotExist(err) {
		t.Fatal("the rotated log is still there")
	}
}

func TestTrafficIgnoresFilesThatAreNotAccountsLogs(t *testing.T) {
	m, _, _, root := setup(t)
	m.TrafficState = filepath.Join(root, "state", "traffic.json")
	os.MkdirAll(m.LogDir, 0o755)
	appendLog(t, filepath.Join(m.LogDir, "Bad-Name.access.log"), logLine("11/Nov/2026", 9, 9))
	appendLog(t, filepath.Join(m.LogDir, "shop1.access.log.2.gz"), logLine("11/Nov/2026", 9, 9))

	tr, err := m.Traffic(context.Background())
	if err != nil || len(tr.Accounts) != 0 {
		t.Fatalf("%+v %v", tr, err)
	}
}

func mustStat(t *testing.T, p string) os.FileInfo {
	t.Helper()
	fi, err := os.Stat(p)
	if err != nil {
		t.Fatal(err)
	}
	return fi
}

// An account over its bandwidth keeps its pools and settings; its sites say why.
func TestALimitedAccountsSitesSaySo(t *testing.T) {
	m, _, _, _ := setup(t)
	a := shop("83")
	a.Limited = true
	if _, err := m.Apply(context.Background(), a); err != nil {
		t.Fatal(err)
	}
	conf, _ := os.ReadFile(m.siteFile("shop1"))
	if !strings.Contains(string(conf), "used its bandwidth for this month") || strings.Contains(string(conf), "DocumentRoot") {
		t.Fatalf("%s", conf)
	}
	if _, err := os.Stat(m.poolFile("83", "shop1")); err != nil {
		t.Fatal("the pool went")
	}
}
