package journal

import (
	"errors"
	"os"
	"testing"
	"time"
)

func TestClaimIsExactlyOnce(t *testing.T) {
	j, err := Open(privateDir(t))
	if err != nil {
		t.Fatal(err)
	}

	first, err := j.Claim("0123456789abcdef", "account.create", "aaa")
	if err != nil {
		t.Fatal(err)
	}
	if _, err := j.Claim("0123456789abcdef", "account.create", "aaa"); !errors.Is(err, ErrExists) {
		t.Fatalf("a second claim of one id was allowed: %v", err)
	}
	if err := j.Succeed(first, map[string]int{"uid": 1001}); err != nil {
		t.Fatal(err)
	}
	again, err := j.Claim("0123456789abcdef", "account.create", "aaa")
	if !errors.Is(err, ErrExists) || again.State != Done || string(again.Result) != `{"uid":1001}` {
		t.Fatalf("a retry did not get the stored result: %+v %v", again, err)
	}
}

func TestEntriesLeftRunningBecomeInterrupted(t *testing.T) {
	dir := privateDir(t)
	j, _ := Open(dir)
	if _, err := j.Claim("cut-off-by-a-crash", "account.create", "aaa"); err != nil {
		t.Fatal(err)
	}

	reopened, err := Open(dir)
	if err != nil {
		t.Fatal(err)
	}
	entry, err := reopened.Get("cut-off-by-a-crash")
	if err != nil || entry.State != Interrupted || entry.ErrCode != "interrupted" {
		t.Fatalf("got %+v, %v", entry, err)
	}
}

func TestPruneKeepsRunningAndRecentEntries(t *testing.T) {
	j, _ := Open(privateDir(t))
	now := time.Date(2026, 10, 29, 12, 0, 0, 0, time.UTC)
	j.Now = func() time.Time { return now.Add(-10 * 24 * time.Hour) }
	old, _ := j.Claim("finished-long-ago", "x.y", "a")
	_ = j.Succeed(old, nil)
	_, _ = j.Claim("still-running-op", "x.y", "a")
	j.Now = func() time.Time { return now }
	recent, _ := j.Claim("finished-just-now", "x.y", "a")
	_ = j.Fail(recent, "failed", "no")

	removed, err := j.Prune(7 * 24 * time.Hour)
	if err != nil || removed != 1 {
		t.Fatalf("removed %d, %v", removed, err)
	}
	for _, id := range []string{"still-running-op", "finished-just-now"} {
		if _, err := j.Get(id); err != nil {
			t.Errorf("%s was pruned", id)
		}
	}
}

// privateDir is a temporary folder with the mode the agent's journal folder
// has. Open refuses a wider one, which a root test run would otherwise get.
func privateDir(t *testing.T) string {
	t.Helper()
	dir := t.TempDir()
	if err := os.Chmod(dir, 0o700); err != nil {
		t.Fatal(err)
	}
	return dir
}
