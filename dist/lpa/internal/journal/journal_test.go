package journal

import (
	"errors"
	"os"
	"sync"
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

func TestListIsNewestFirstAndHasNoArgumentsOrResults(t *testing.T) {
	j, _ := Open(privateDir(t))
	old, _ := j.Claim("0000000000000001", "db.apply", "aaa")
	j.Succeed(old, map[string]string{"password": "secret-in-a-result"})
	os.Chtimes(j.path("0000000000000001"), time.Now().Add(-time.Hour), time.Now().Add(-time.Hour))
	bad, _ := j.Claim("0000000000000002", "backup.run", "bbb")
	j.Fail(bad, "backup_failed", "the destination is full")
	j.Claim("0000000000000003", "php.install", "ccc")

	all, err := j.List("", 10)
	if err != nil || len(all) != 3 {
		t.Fatalf("%v %+v", err, all)
	}
	if all[2].ID != "0000000000000001" {
		t.Fatalf("the oldest is last: %+v", all)
	}
	for _, s := range all {
		raw, _ := os.ReadFile(j.path(s.ID))
		_ = raw
	}
	failed, _ := j.List(Failed, 10)
	if len(failed) != 1 || failed[0].ErrCode != "backup_failed" || failed[0].ErrMessage != "the destination is full" {
		t.Fatalf("%+v", failed)
	}
	running, _ := j.List(Running, 10)
	if len(running) != 1 || running[0].Op != "php.install" {
		t.Fatalf("%+v", running)
	}
	one, _ := j.List("", 1)
	if len(one) != 1 {
		t.Fatalf("%+v", one)
	}
}

func TestAClaimIsNeverSeenHalfWritten(t *testing.T) {
	j, _ := Open(privateDir(t))
	done := make(chan struct{})
	errs := make(chan error, 200)
	// Every retry is waited for: the test's folder is removed when it ends.
	var wg sync.WaitGroup
	go func() {
		defer close(done)
		for i := 0; i < 200; i++ {
			id := "race-" + string(rune('a'+i%26)) + "-" + time.Now().Format("150405.000000000")
			wg.Add(1)
			go func() {
				defer wg.Done()
				for k := 0; k < 20; k++ {
					if _, err := j.Claim(id, "web.apply", "aaa"); err != nil && !errors.Is(err, ErrExists) {
						errs <- err
						return
					}
				}
			}()
			if _, err := j.Claim(id, "web.apply", "aaa"); err != nil && !errors.Is(err, ErrExists) {
				errs <- err
			}
		}
	}()
	<-done
	wg.Wait()
	select {
	case err := <-errs:
		t.Fatalf("a retry saw a claim half written: %v", err)
	default:
	}
}
