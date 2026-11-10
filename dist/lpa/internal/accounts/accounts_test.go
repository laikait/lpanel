package accounts

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"

	"lpa/internal/protocol"
	"lpa/internal/quota"
)

// fakeSystem plays useradd, usermod, userdel and pkill against passwd, group
// and shadow files in a temporary folder.
type fakeSystem struct {
	t      *testing.T
	files  Files
	calls  []string
	nextID int
	fail   map[string]bool
}

func (f *fakeSystem) Run(_ context.Context, tool string, args ...string) (int, string, error) {
	f.calls = append(f.calls, tool+" "+strings.Join(args, " "))
	if f.fail[tool] {
		return 1, tool + ": failed on purpose", nil
	}
	name := args[len(args)-1]
	switch tool {
	case "useradd":
		opt := map[string]string{}
		for i := 0; i+1 < len(args)-1; i++ {
			if strings.HasPrefix(args[i], "--") {
				opt[args[i]] = args[i+1]
			}
		}
		f.nextID++
		id := 1000 + f.nextID
		f.appendLine(f.files.Passwd, fmt.Sprintf("%s:x:%d:%d:%s:%s:%s", name, id, id, opt["--comment"], opt["--home-dir"], opt["--shell"]))
		f.appendLine(f.files.Group, fmt.Sprintf("%s:x:%d:", name, id))
		f.appendLine(f.files.Shadow, name+":!!:20000:0:99999:7:::")
		if err := os.MkdirAll(opt["--home-dir"], 0o700); err != nil {
			f.t.Fatal(err)
		}
	case "usermod":
		switch args[0] {
		case "--expiredate":
			f.editField(f.files.Shadow, name, 7, args[1])
		case "--shell":
			f.editField(f.files.Passwd, name, 6, args[1])
		}
	case "userdel":
		e, _ := f.files.lookup(name)
		for _, p := range []string{f.files.Passwd, f.files.Group, f.files.Shadow} {
			f.removeLine(p, name)
		}
		if args[0] == "--remove" && e != nil {
			os.RemoveAll(e.Home)
		}
	case "pkill":
		return 1, "", nil // no processes
	}
	return 0, "", nil
}

func (f *fakeSystem) appendLine(path, line string) {
	file, err := os.OpenFile(path, os.O_APPEND|os.O_WRONLY, 0o600)
	if err != nil {
		f.t.Fatal(err)
	}
	defer file.Close()
	fmt.Fprintln(file, line)
}

func (f *fakeSystem) rewrite(path string, fn func(fields []string) []string) {
	data, _ := os.ReadFile(path)
	var out []string
	for _, line := range strings.Split(strings.TrimRight(string(data), "\n"), "\n") {
		if line == "" {
			continue
		}
		if fields := fn(strings.Split(line, ":")); fields != nil {
			out = append(out, strings.Join(fields, ":"))
		}
	}
	os.WriteFile(path, []byte(strings.Join(out, "\n")+"\n"), 0o600)
}

func (f *fakeSystem) editField(path, name string, i int, value string) {
	f.rewrite(path, func(fields []string) []string {
		if fields[0] == name {
			fields[i] = value
		}
		return fields
	})
}

func (f *fakeSystem) removeLine(path, name string) {
	f.rewrite(path, func(fields []string) []string {
		if fields[0] == name {
			return nil
		}
		return fields
	})
}

type fakeQuota struct {
	set  map[int]quota.Limits
	fail bool
}

func (q *fakeQuota) Set(_ string, uid int, l quota.Limits) error {
	if q.fail {
		return quota.ErrNotEnabled
	}
	q.set[uid] = l
	return nil
}

func (q *fakeQuota) Get(_ string, uid int) (quota.Usage, error) {
	l := q.set[uid]
	return quota.Usage{UsedBytes: 4096, UsedInodes: 3, LimitKiB: l.BlocksKiB, InodeLimit: l.Inodes}, nil
}

func setup(t *testing.T) (*Manager, *fakeSystem, *fakeQuota) {
	dir := t.TempDir()
	// passwd separates fields with ':', so a Windows drive letter cannot be in
	// a home path. Without it the path still means the same folder on the
	// current drive.
	dir = strings.TrimPrefix(dir, filepath.VolumeName(dir))
	files := Files{Passwd: filepath.Join(dir, "passwd"), Group: filepath.Join(dir, "group"), Shadow: filepath.Join(dir, "shadow")}
	home := filepath.Join(dir, "home")
	os.MkdirAll(home, 0o755)
	os.WriteFile(files.Passwd, []byte(strings.Join([]string{
		"root:x:0:0:root:/root:/bin/bash",
		"apache:x:48:48:Apache:/usr/share/httpd:/sbin/nologin",
		"alice:x:1000:1000:Alice:" + filepath.Join(home, "alice") + ":/bin/bash", // a person's own login
		"tricky:x:1500:1500:" + Marker + ":/srv/tricky:/bin/bash",                // marked, but lives elsewhere
		"",
	}, "\n")), 0o600)
	os.WriteFile(files.Group, []byte("root:x:0:\napache:x:48:\nalice:x:1000:\nwheel:x:10:\n"), 0o600)
	os.WriteFile(files.Shadow, []byte("root:*:20000:0:99999:7:::\nalice:*:20000:0:99999:7:::\n"), 0o600)
	sys := &fakeSystem{t: t, files: files, fail: map[string]bool{}}
	q := &fakeQuota{set: map[int]quota.Limits{}}
	m := &Manager{
		HomesBase: home, Files: files, Run: sys, Quota: q,
		Log:   slog.New(slog.NewTextHandler(io.Discard, nil)),
		Chown: func(string, int, int) error { return nil },
	}
	return m, sys, q
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

func TestCreateMakesTheUserHomeAndQuota(t *testing.T) {
	m, sys, q := setup(t)
	acct, err := m.Create(context.Background(), CreateArgs{Username: "shop1", Shell: "none", Quota: quota.Limits{BlocksKiB: 1024 * 1024, Inodes: 100000}})
	if err != nil {
		t.Fatal(err)
	}
	home := filepath.Join(m.HomesBase, "shop1")
	if acct.Username != "shop1" || acct.Home != home || acct.UID < MinUID || acct.Shell != "none" || acct.Suspended {
		t.Fatalf("got %+v", acct)
	}
	if !strings.Contains(sys.calls[0], "--comment "+Marker) || !strings.Contains(sys.calls[0], "--shell /sbin/nologin") {
		t.Fatalf("useradd: %s", sys.calls[0])
	}
	if st, err := os.Stat(filepath.Join(home, "public_html")); err != nil || !st.IsDir() {
		t.Fatal("no public_html")
	}
	if runtime.GOOS != "windows" {
		if st, _ := os.Stat(home); st.Mode().Perm() != 0o711 {
			t.Fatalf("home is %o", st.Mode().Perm())
		}
	}
	if q.set[acct.UID].BlocksKiB != 1024*1024 || acct.Usage == nil || acct.Usage.LimitKiB != 1024*1024 {
		t.Fatalf("quota: %+v %+v", q.set, acct.Usage)
	}
}

func TestCreateRefuses(t *testing.T) {
	m, _, _ := setup(t)
	os.MkdirAll(filepath.Join(m.HomesBase, "leftover"), 0o700)
	for name, tc := range map[string]struct {
		args CreateArgs
		want string
	}{
		"an existing user":    {CreateArgs{Username: "root", Shell: "none"}, CodeExists},
		"an existing group":   {CreateArgs{Username: "wheel", Shell: "none"}, CodeExists},
		"an existing home":    {CreateArgs{Username: "leftover", Shell: "none"}, CodeExists},
		"a capital letter":    {CreateArgs{Username: "Shop", Shell: "none"}, protocol.CodeBadArgs},
		"a path":              {CreateArgs{Username: "../etc", Shell: "none"}, protocol.CodeBadArgs},
		"too long":            {CreateArgs{Username: "abcdefghijklmnopq", Shell: "none"}, protocol.CodeBadArgs},
		"a shell not offered": {CreateArgs{Username: "shop2", Shell: "/bin/sh"}, protocol.CodeBadArgs},
	} {
		if _, err := m.Create(context.Background(), tc.args); code(err) != tc.want {
			t.Errorf("%s: got %q, want %q", name, code(err), tc.want)
		}
	}
}

func TestAFailedQuotaUndoesTheAccount(t *testing.T) {
	m, sys, q := setup(t)
	q.fail = true
	_, err := m.Create(context.Background(), CreateArgs{Username: "shop3", Shell: "none", Quota: quota.Limits{BlocksKiB: 1}})
	if code(err) != CodeQuota {
		t.Fatalf("got %v", err)
	}
	if e, _ := m.Files.lookup("shop3"); e != nil {
		t.Fatal("the user is still there")
	}
	if _, err := os.Stat(filepath.Join(m.HomesBase, "shop3")); !os.IsNotExist(err) {
		t.Fatal("the home is still there")
	}
	if last := sys.calls[len(sys.calls)-1]; last != "userdel --remove shop3" {
		t.Fatalf("last call %q", last)
	}
}

func TestSuspendAndUnsuspend(t *testing.T) {
	m, sys, _ := setup(t)
	m.Create(context.Background(), CreateArgs{Username: "shop4", Shell: "bash"})

	acct, err := m.Suspend(context.Background(), NameArgs{Username: "shop4"})
	if err != nil || !acct.Suspended {
		t.Fatalf("suspend: %+v %v", acct, err)
	}
	if !contains(sys.calls, "pkill --signal KILL --uid shop4") {
		t.Fatalf("processes not ended: %v", sys.calls)
	}
	acct, err = m.Unsuspend(context.Background(), NameArgs{Username: "shop4"})
	if err != nil || acct.Suspended || acct.Shell != "bash" {
		t.Fatalf("unsuspend: %+v %v", acct, err)
	}
}

func TestModifyChangesShellAndQuota(t *testing.T) {
	m, _, q := setup(t)
	m.Create(context.Background(), CreateArgs{Username: "shop5", Shell: "none"})
	bash := "bash"
	acct, err := m.Modify(context.Background(), ModifyArgs{Username: "shop5", Shell: &bash, Quota: &quota.Limits{BlocksKiB: 2048}})
	if err != nil || acct.Shell != "bash" || q.set[acct.UID].BlocksKiB != 2048 {
		t.Fatalf("got %+v %v", acct, err)
	}
}

func TestOnlyLPanelAccountsAreTouched(t *testing.T) {
	m, sys, _ := setup(t)
	for _, name := range []string{"root", "apache", "alice", "tricky"} {
		want := CodeNotOurs
		if _, err := m.Suspend(context.Background(), NameArgs{Username: name}); code(err) != want {
			t.Errorf("suspend %s: %q", name, code(err))
		}
		if _, err := m.Terminate(context.Background(), NameArgs{Username: name}); code(err) != want {
			t.Errorf("terminate %s: %q", name, code(err))
		}
		bash := "bash"
		if _, err := m.Modify(context.Background(), ModifyArgs{Username: name, Shell: &bash}); code(err) != want {
			t.Errorf("modify %s: %q", name, code(err))
		}
	}
	if len(sys.calls) != 0 {
		t.Fatalf("tools ran for accounts that are not ours: %v", sys.calls)
	}
}

func TestTerminateRemovesEverythingButFollowsNoLinks(t *testing.T) {
	m, _, _ := setup(t)
	m.Create(context.Background(), CreateArgs{Username: "shop6", Shell: "none"})
	home := filepath.Join(m.HomesBase, "shop6")
	outside := filepath.Join(filepath.Dir(m.HomesBase), "precious")
	os.WriteFile(outside, []byte("keep me"), 0o600)
	linked := os.Symlink(outside, filepath.Join(home, "public_html", "link")) == nil

	res, err := m.Terminate(context.Background(), NameArgs{Username: "shop6"})
	if err != nil || !res.Existed {
		t.Fatalf("got %+v %v", res, err)
	}
	if e, _ := m.Files.lookup("shop6"); e != nil {
		t.Fatal("the user is still there")
	}
	if _, err := os.Stat(home); !os.IsNotExist(err) {
		t.Fatal("the home is still there")
	}
	if linked {
		if data, err := os.ReadFile(outside); err != nil || string(data) != "keep me" {
			t.Fatal("removing the home followed a link out of it")
		}
	}

	again, err := m.Terminate(context.Background(), NameArgs{Username: "shop6"})
	if err != nil || again.Existed {
		t.Fatalf("terminating twice: %+v %v", again, err)
	}
}

func TestInfoListsOnlyLPanelAccounts(t *testing.T) {
	m, _, _ := setup(t)
	m.Create(context.Background(), CreateArgs{Username: "shop7", Shell: "none"})
	m.Create(context.Background(), CreateArgs{Username: "shop8", Shell: "none"})

	all, err := m.Info(InfoArgs{})
	if err != nil || len(all.Accounts) != 2 {
		t.Fatalf("got %+v %v", all, err)
	}
	some, _ := m.Info(InfoArgs{Usernames: []string{"shop8", "alice", "gone"}})
	if len(some.Accounts) != 1 || some.Accounts[0].Username != "shop8" || strings.Join(some.Missing, ",") != "alice,gone" {
		t.Fatalf("got %+v", some)
	}
	if some.Accounts[0].Usage == nil || some.Accounts[0].Usage.UsedBytes != 4096 {
		t.Fatalf("usage: %+v", some.Accounts[0])
	}
}

func contains(list []string, s string) bool {
	for _, v := range list {
		if v == s {
			return true
		}
	}
	return false
}
