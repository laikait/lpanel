package backup

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"sync"
	"testing"

	"lpa/internal/protocol"
)

// fakeRestic plays restic and mariadb-dump.
type fakeRestic struct {
	mu       sync.Mutex
	calls    []Command
	answer   map[string]func(c Command) (string, string, int)
	metaSeen map[string]string
	block    chan struct{}
}

func (f *fakeRestic) Run(ctx context.Context, c Command) (string, string, int, error) {
	f.mu.Lock()
	f.calls = append(f.calls, c)
	f.mu.Unlock()
	if strings.HasSuffix(c.Path, "mariadb-dump") {
		db := c.Args[len(c.Args)-1]
		fmt.Fprintf(c.Stdout, "-- dump of %s\n", db)
		return "", "", 0, nil
	}
	sub := subcommand(c.Args)
	if sub == "backup" && f.block != nil {
		<-f.block
	}
	if sub == "backup" {
		// What the snapshot would hold: the description in the stage.
		stage := c.Args[len(c.Args)-1]
		meta, _ := os.ReadFile(filepath.Join(stage, "lpanel.json"))
		dumps, _ := os.ReadDir(filepath.Join(stage, "databases"))
		names := []string{}
		for _, d := range dumps {
			names = append(names, d.Name())
		}
		f.mu.Lock()
		f.metaSeen[filepath.Base(stage)] = string(meta) + " " + strings.Join(names, ",")
		f.mu.Unlock()
	}
	if fn, ok := f.answer[sub]; ok {
		out, errOut, code := fn(c)
		return out, errOut, code, nil
	}
	switch sub {
	case "backup":
		return `{"message_type":"status","percent_done":0.5}` + "\n" +
			`{"message_type":"summary","snapshot_id":"abc123","total_files_processed":12,"total_bytes_processed":3400,"data_added":1200}` + "\n", "", 0, nil
	case "forget":
		return `[{"keep":[{}],"remove":[{},{}]}]`, "", 0, nil
	case "snapshots":
		return `[]`, "", 0, nil
	}
	return "", "", 0, nil
}

func subcommand(args []string) string {
	for i := 0; i < len(args); i++ {
		if args[i] == "-o" {
			i++
			continue
		}
		return args[i]
	}
	return ""
}

func (f *fakeRestic) subcommands() []string {
	out := []string{}
	for _, c := range f.calls {
		if strings.HasSuffix(c.Path, "mariadb-dump") {
			out = append(out, "dump:"+c.Args[len(c.Args)-1])
		} else {
			out = append(out, subcommand(c.Args))
		}
	}
	return out
}

func (f *fakeRestic) find(sub string) *Command {
	for i := range f.calls {
		if subcommand(f.calls[i].Args) == sub && !strings.HasSuffix(f.calls[i].Path, "mariadb-dump") {
			return &f.calls[i]
		}
	}
	return nil
}

func setup(t *testing.T) (*Manager, *fakeRestic, string) {
	if runtime.GOOS == "windows" {
		t.Skip("file modes and paths")
	}
	dir := t.TempDir()
	restic := filepath.Join(dir, "restic")
	os.WriteFile(restic, []byte("#!/bin/sh\n"), 0o755)
	homes := filepath.Join(dir, "home")
	f := &fakeRestic{answer: map[string]func(Command) (string, string, int){}, metaSeen: map[string]string{}}
	m := &Manager{
		Dir: filepath.Join(dir, "etc"), StageDir: filepath.Join(dir, "stage"), CacheDir: filepath.Join(dir, "cache"),
		Restic: restic, Dump: "/usr/bin/mariadb-dump", Cmd: f,
		Owner: func(name string) (int, int, string, error) {
			if name == "gone" {
				return 0, 0, "", protocol.Fail("no_such_account", "there is no user named gone")
			}
			return 1001, 1001, filepath.Join(homes, name), nil
		},
		Databases: func(ctx context.Context, username string) ([]string, error) {
			// The agent only dumps the account's own, whatever it is told.
			return []string{username + "_wp", "other_shop", username + "_shop"}, nil
		},
	}
	m.Defaults()
	return m, f, dir
}

func code(err error) string {
	var e *protocol.Error
	if errors.As(err, &e) {
		return e.Code
	}
	return ""
}

func local(dir string) DestinationArgs {
	return DestinationArgs{Destination: Destination{ID: "d1", Type: Local, Path: filepath.Join(dir, "backups")}}
}

func TestALocalDestinationGetsARepositoryAndAPassword(t *testing.T) {
	m, f, dir := setup(t)
	f.answer["cat"] = func(Command) (string, string, int) { return "", "Fatal: repository does not exist", 10 }
	out, err := m.SetDestination(context.Background(), local(dir))
	if err != nil {
		t.Fatal(err)
	}
	if !out.Created || out.Destination.Path != filepath.Join(dir, "backups") {
		t.Fatalf("%+v", out)
	}
	if got := f.subcommands(); strings.Join(got, " ") != "cat init snapshots" {
		t.Errorf("restic ran %v", got)
	}
	pw, err := m.Password("d1")
	if err != nil || len(pw) != 64 {
		t.Fatalf("password %q %v", pw, err)
	}
	info, _ := os.Stat(m.passFile("d1"))
	if info.Mode().Perm() != 0o600 {
		t.Errorf("password file mode %v", info.Mode())
	}
	if info, err := os.Stat(filepath.Join(dir, "backups")); err != nil || info.Mode().Perm() != 0o700 {
		t.Errorf("the folder: %v %v", info, err)
	}
	env := strings.Join(f.calls[0].Env, " ")
	if !strings.Contains(env, "RESTIC_REPOSITORY="+filepath.Join(dir, "backups")) || !strings.Contains(env, "RESTIC_PASSWORD_FILE="+m.passFile("d1")) {
		t.Errorf("env %s", env)
	}

	// Again: the repository is there; the password is not changed.
	f.answer["cat"] = func(Command) (string, string, int) { return "{}", "", 0 }
	again, err := m.SetDestination(context.Background(), local(dir))
	if err != nil || again.Created {
		t.Fatalf("%+v %v", again, err)
	}
	if pw2, _ := m.Password("d1"); pw2 != pw {
		t.Error("the password changed")
	}
}

func TestAWrongPasswordOrNoAnswerIsNotKept(t *testing.T) {
	m, f, dir := setup(t)
	f.answer["cat"] = func(Command) (string, string, int) { return "", "Fatal: wrong password or no key found", 12 }
	if _, err := m.SetDestination(context.Background(), local(dir)); code(err) != CodeWrongPassword {
		t.Errorf("wrong password: %v", err)
	}
	f.answer["cat"] = func(Command) (string, string, int) {
		return "", "Fatal: unable to open repository: dial tcp: i/o timeout", 1
	}
	if _, err := m.SetDestination(context.Background(), local(dir)); code(err) != CodeUnreachable || !strings.Contains(err.Error(), "i/o timeout") {
		t.Errorf("unreachable: %v", err)
	}
	if _, err := m.load("d1"); code(err) != CodeNoDestination {
		t.Errorf("a destination that did not answer was kept: %v", err)
	}
}

func TestS3KeepsItsSecretsAndNeverShowsThem(t *testing.T) {
	m, f, _ := setup(t)
	f.answer["cat"] = func(Command) (string, string, int) { return "{}", "", 0 }
	d := Destination{ID: "d2", Type: S3, Endpoint: "s3.eu-central-1.wasabisys.com", Bucket: "acme-backups", Prefix: "/server1/", Region: "eu-central-1", AccessKey: "AKIAEXAMPLE", SecretKey: "s3cr3t/Key+1"}
	out, err := m.SetDestination(context.Background(), DestinationArgs{Destination: d})
	if err != nil {
		t.Fatal(err)
	}
	if out.Destination.AccessKey != "" || out.Destination.SecretKey != "" {
		t.Error("secrets in the answer")
	}
	if got := out.Destination.Repository(); got != "s3:https://s3.eu-central-1.wasabisys.com/acme-backups/server1" {
		t.Errorf("repository %s", got)
	}
	env := strings.Join(f.calls[0].Env, " ")
	for _, want := range []string{"AWS_ACCESS_KEY_ID=AKIAEXAMPLE", "AWS_SECRET_ACCESS_KEY=s3cr3t/Key+1", "AWS_DEFAULT_REGION=eu-central-1"} {
		if !strings.Contains(env, want) {
			t.Errorf("env lacks %s", want)
		}
	}
	for _, c := range f.calls {
		if strings.Contains(strings.Join(c.Args, " "), "s3cr3t") {
			t.Error("a secret in restic's arguments")
		}
	}
	// Changed without secrets: the agent's are kept.
	d.AccessKey, d.SecretKey, d.Prefix = "", "", "server2"
	f.calls = nil
	if _, err := m.SetDestination(context.Background(), DestinationArgs{Destination: d}); err != nil {
		t.Fatal(err)
	}
	if env := strings.Join(f.calls[0].Env, " "); !strings.Contains(env, "AWS_SECRET_ACCESS_KEY=s3cr3t/Key+1") {
		t.Errorf("secret not kept: %s", env)
	}
	// A new destination without them is refused.
	if _, err := m.SetDestination(context.Background(), DestinationArgs{Destination: Destination{ID: "d3", Type: B2, Bucket: "acme-b2-bucket"}}); code(err) != protocol.CodeBadArgs {
		t.Errorf("no secrets: %v", err)
	}
}

func TestSFTPUsesTheAgentsKey(t *testing.T) {
	m, f, _ := setup(t)
	f.answer["cat"] = func(Command) (string, string, int) { return "{}", "", 0 }
	out, err := m.SetDestination(context.Background(), DestinationArgs{Destination: Destination{ID: "d4", Type: SFTP, Host: "backup.example.net", User: "u123", Port: 23, Path: "/lpanel"}})
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(out.PublicKey, "ssh-ed25519 ") || !strings.HasSuffix(out.PublicKey, " lpanel-backup") {
		t.Errorf("public key %q", out.PublicKey)
	}
	if info, err := os.Stat(m.keyFile()); err != nil || info.Mode().Perm() != 0o600 {
		t.Errorf("private key: %v %v", info, err)
	}
	args := strings.Join(f.calls[0].Args, " ")
	for _, want := range []string{"sftp.command=ssh -i " + m.keyFile() + " -p 23 ", "StrictHostKeyChecking=accept-new", "UserKnownHostsFile=" + m.knownHosts(), "u123@backup.example.net -s sftp"} {
		if !strings.Contains(args, want) {
			t.Errorf("args lack %q: %s", want, args)
		}
	}
	if !strings.Contains(strings.Join(f.calls[0].Env, " "), "RESTIC_REPOSITORY=sftp:u123@backup.example.net:/lpanel") {
		t.Error("repository")
	}
	// The same key for every SFTP destination.
	out2, _ := m.SetDestination(context.Background(), DestinationArgs{Destination: Destination{ID: "d5", Type: SFTP, Host: "other.example.net", User: "x", Path: "/b"}})
	if out2.PublicKey != out.PublicKey {
		t.Error("a second key")
	}
}

func TestDestinationsAreChecked(t *testing.T) {
	m, _, dir := setup(t)
	for name, d := range map[string]Destination{
		"bad id":        {ID: "../x", Type: Local, Path: "/srv/b"},
		"relative":      {ID: "d1", Type: Local, Path: "backups"},
		"root":          {ID: "d1", Type: Local, Path: "/"},
		"dotdot":        {ID: "d1", Type: Local, Path: "/srv/../etc"},
		"in /home":      {ID: "d1", Type: Local, Path: "/home/shop/b"},
		"in /etc":       {ID: "d1", Type: Local, Path: "/etc"},
		"space":         {ID: "d1", Type: Local, Path: dir + "/a b"},
		"sftp host":     {ID: "d1", Type: SFTP, Host: "-oProxyCommand=x", User: "u", Path: "/b"},
		"sftp user":     {ID: "d1", Type: SFTP, Host: "h.example", User: "u -s", Path: "/b"},
		"sftp port":     {ID: "d1", Type: SFTP, Host: "h.example", User: "u", Path: "/b", Port: 70000},
		"s3 bucket":     {ID: "d1", Type: S3, Endpoint: "s3.amazonaws.com", Bucket: "A_B", AccessKey: "a", SecretKey: "b"},
		"s3 scheme":     {ID: "d1", Type: S3, Endpoint: "s3.amazonaws.com", Bucket: "abc", Scheme: "ftp", AccessKey: "a", SecretKey: "b"},
		"s3 secret":     {ID: "d1", Type: S3, Endpoint: "s3.amazonaws.com", Bucket: "abc", AccessKey: "a", SecretKey: "has space"},
		"prefix dotdot": {ID: "d1", Type: S3, Endpoint: "s3.amazonaws.com", Bucket: "abc", Prefix: "a/../b", AccessKey: "a", SecretKey: "b"},
		"type":          {ID: "d1", Type: "ftp"},
	} {
		if _, err := m.SetDestination(context.Background(), DestinationArgs{Destination: d}); code(err) != protocol.CodeBadArgs {
			t.Errorf("%s: %v", name, err)
		}
	}
}

func TestARunBacksEachAccountUpAndKeepsGoing(t *testing.T) {
	m, f, dir := setup(t)
	f.answer["cat"] = func(Command) (string, string, int) { return "{}", "", 0 }
	if _, err := m.SetDestination(context.Background(), local(dir)); err != nil {
		t.Fatal(err)
	}
	f.calls = nil
	out, err := m.Run(context.Background(), RunArgs{
		Destination: "d1",
		Accounts: []RunAccount{
			{Username: "shop", Meta: json.RawMessage(`{"account":{"username":"shop"}}`)},
			{Username: "gone"},
			{Username: "blog"},
		},
		Keep:  Retention{Daily: 7, Weekly: 4, Monthly: 3},
		Prune: true,
	})
	if err != nil {
		t.Fatal(err)
	}
	if out.OK != 2 || out.Failed != 1 || !out.Pruned {
		t.Fatalf("%+v", out)
	}
	shop := out.Accounts[0]
	if !shop.OK || shop.Snapshot != "abc123" || shop.Files != 12 || shop.Added != 1200 || shop.Databases != 2 || shop.Forgotten != 2 {
		t.Errorf("shop: %+v", shop)
	}
	if out.Accounts[1].OK || !strings.Contains(out.Accounts[1].Error, "no user named gone") {
		t.Errorf("gone: %+v", out.Accounts[1])
	}
	// Only the account's own databases were dumped; its description and
	// dumps were in the snapshot; the stage is gone.
	got := strings.Join(f.subcommands(), " ")
	if got != "dump:shop_wp dump:shop_shop backup forget dump:blog_wp dump:blog_shop backup forget prune" {
		t.Errorf("ran %s", got)
	}
	if f.metaSeen["shop"] != `{"account":{"username":"shop"}} shop_shop.sql,shop_wp.sql` {
		t.Errorf("the stage held %q", f.metaSeen["shop"])
	}
	if f.metaSeen["blog"] != "{} blog_shop.sql,blog_wp.sql" {
		t.Errorf("blog's stage held %q", f.metaSeen["blog"])
	}
	if entries, _ := os.ReadDir(m.StageDir); len(entries) != 0 {
		t.Errorf("the stage was left: %v", entries)
	}
	backup := strings.Join(f.find("backup").Args, " ")
	for _, want := range []string{"--tag lpanel --tag account:shop", "--host lpanel", "--exclude " + filepath.Join(dir, "home", "shop", "tmp"), filepath.Join(dir, "home", "shop") + " " + filepath.Join(m.StageDir, "shop")} {
		if !strings.Contains(backup, want) {
			t.Errorf("backup lacks %q: %s", want, backup)
		}
	}
	forget := strings.Join(f.find("forget").Args, " ")
	if !strings.Contains(forget, "--tag account:shop --group-by host,tags --keep-daily 7 --keep-weekly 4 --keep-monthly 3") {
		t.Errorf("forget: %s", forget)
	}
}

func TestUnreadableFilesAreAWarningAndAFailedBackupIsAnError(t *testing.T) {
	m, f, dir := setup(t)
	f.answer["cat"] = func(Command) (string, string, int) { return "{}", "", 0 }
	m.SetDestination(context.Background(), local(dir))
	f.answer["backup"] = func(Command) (string, string, int) {
		return `{"message_type":"summary","snapshot_id":"def456","total_files_processed":1}`, "error: open /home/x/locked: permission denied", 3
	}
	out, _ := m.Run(context.Background(), RunArgs{Destination: "d1", Accounts: []RunAccount{{Username: "shop"}}, Keep: Retention{Daily: 1}})
	if !out.Accounts[0].OK || !strings.Contains(out.Accounts[0].Warning, "permission denied") {
		t.Errorf("%+v", out.Accounts[0])
	}
	f.answer["backup"] = func(Command) (string, string, int) { return "", "Fatal: unable to save snapshot: disk full", 1 }
	out, _ = m.Run(context.Background(), RunArgs{Destination: "d1", Accounts: []RunAccount{{Username: "shop"}}, Keep: Retention{Daily: 1}})
	if out.Accounts[0].OK || !strings.Contains(out.Accounts[0].Error, "disk full") {
		t.Errorf("%+v", out.Accounts[0])
	}
}

func TestOneRunAtATime(t *testing.T) {
	m, f, dir := setup(t)
	f.answer["cat"] = func(Command) (string, string, int) { return "{}", "", 0 }
	m.SetDestination(context.Background(), local(dir))
	f.block = make(chan struct{})
	done := make(chan struct{})
	go func() {
		m.Run(context.Background(), RunArgs{Destination: "d1", Accounts: []RunAccount{{Username: "shop"}}, Keep: Retention{Daily: 1}})
		close(done)
	}()
	for i := 0; i < 1000 && !m.Progress().Running; i++ {
		runtime.Gosched()
	}
	p := m.Progress()
	if !p.Running || p.Total != 1 || p.Destination != "d1" {
		t.Errorf("progress %+v", p)
	}
	if _, err := m.Run(context.Background(), RunArgs{Destination: "d1", Accounts: []RunAccount{{Username: "blog"}}, Keep: Retention{Daily: 1}}); code(err) != CodeBusy {
		t.Errorf("a second run: %v", err)
	}
	if _, err := m.SetDestination(context.Background(), local(dir)); code(err) != CodeBusy {
		t.Errorf("a change during a run: %v", err)
	}
	close(f.block)
	<-done
	if m.Progress().Running {
		t.Error("still running")
	}
}

func TestRunArgumentsAreChecked(t *testing.T) {
	m, f, dir := setup(t)
	f.answer["cat"] = func(Command) (string, string, int) { return "{}", "", 0 }
	m.SetDestination(context.Background(), local(dir))
	keep := Retention{Daily: 1}
	for name, a := range map[string]RunArgs{
		"no accounts":  {Destination: "d1", Keep: keep},
		"bad name":     {Destination: "d1", Accounts: []RunAccount{{Username: "../x"}}, Keep: keep},
		"twice":        {Destination: "d1", Accounts: []RunAccount{{Username: "shop"}, {Username: "shop"}}, Keep: keep},
		"bad meta":     {Destination: "d1", Accounts: []RunAccount{{Username: "shop", Meta: json.RawMessage(`{`)}}, Keep: keep},
		"keep nothing": {Destination: "d1", Accounts: []RunAccount{{Username: "shop"}}},
	} {
		if _, err := m.Run(context.Background(), a); code(err) != protocol.CodeBadArgs {
			t.Errorf("%s: %v", name, err)
		}
	}
	if _, err := m.Run(context.Background(), RunArgs{Destination: "d9", Accounts: []RunAccount{{Username: "shop"}}, Keep: keep}); code(err) != CodeNoDestination {
		t.Errorf("no destination: %v", err)
	}
}

func TestSnapshotsNewestFirstWithTheirAccount(t *testing.T) {
	m, f, dir := setup(t)
	f.answer["cat"] = func(Command) (string, string, int) { return "{}", "", 0 }
	m.SetDestination(context.Background(), local(dir))
	f.answer["snapshots"] = func(c Command) (string, string, int) {
		return `[{"id":"aaa","short_id":"aa","time":"2026-11-17T02:00:00Z","tags":["lpanel","account:shop"],"summary":{"total_files_processed":5,"total_bytes_processed":100}},
		         {"id":"bbb","short_id":"bb","time":"2026-11-18T02:00:00Z","tags":["lpanel","account:shop"]}]`, "", 0
	}
	list, err := m.Snapshots(context.Background(), SnapshotArgs{Destination: "d1", Username: "shop"})
	if err != nil {
		t.Fatal(err)
	}
	if len(list) != 2 || list[0].ID != "bbb" || list[1].Username != "shop" || list[1].Files != 5 {
		t.Errorf("%+v", list)
	}
	if args := strings.Join(f.calls[len(f.calls)-1].Args, " "); !strings.Contains(args, "--tag account:shop") || !strings.Contains(args, "--no-lock") {
		t.Errorf("args %s", args)
	}
	if _, err := m.Snapshots(context.Background(), SnapshotArgs{Destination: "d1", Username: "x;y"}); code(err) != protocol.CodeBadArgs {
		t.Errorf("bad name: %v", err)
	}
}

func TestWithoutResticNothingRuns(t *testing.T) {
	m, f, dir := setup(t)
	os.Remove(m.Restic)
	if _, err := m.SetDestination(context.Background(), local(dir)); code(err) != CodeOff {
		t.Errorf("%v", err)
	}
	if len(f.calls) != 0 || m.Progress().Installed {
		t.Error("restic was run, or said to be there")
	}
}

func TestRemovingADestinationForgetsItsPassword(t *testing.T) {
	m, f, dir := setup(t)
	f.answer["cat"] = func(Command) (string, string, int) { return "{}", "", 0 }
	m.SetDestination(context.Background(), local(dir))
	if _, err := m.RemoveDestination(context.Background(), RemoveArgs{ID: "d1"}); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Password("d1"); code(err) != CodeNoDestination {
		t.Errorf("%v", err)
	}
	if _, err := os.Stat(filepath.Join(dir, "backups")); err != nil {
		t.Error("what was backed up was removed")
	}
}
