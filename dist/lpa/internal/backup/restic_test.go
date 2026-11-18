package backup

import (
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

// TestWithTheRealRestic runs a destination, two runs and the snapshot list
// through the real restic. It runs when LPA_RESTIC=1 (and restic is on the
// PATH); tests/e2e covers the whole agent on AlmaLinux.
func TestWithTheRealRestic(t *testing.T) {
	path, err := exec.LookPath("restic")
	if os.Getenv("LPA_RESTIC") != "1" || err != nil {
		t.Skip("LPA_RESTIC=1 and restic are needed")
	}
	dir := t.TempDir()
	home := filepath.Join(dir, "home", "shop")
	os.MkdirAll(filepath.Join(home, "public_html"), 0o755)
	os.MkdirAll(filepath.Join(home, "tmp"), 0o755)
	os.WriteFile(filepath.Join(home, "public_html", "index.html"), []byte("hello"), 0o644)
	os.WriteFile(filepath.Join(home, "tmp", "session"), []byte("skip me"), 0o600)
	m := &Manager{
		Dir: filepath.Join(dir, "etc"), StageDir: filepath.Join(dir, "stage"), CacheDir: filepath.Join(dir, "cache"),
		Restic: path,
		Owner:  func(string) (int, int, string, error) { return 1001, 1001, home, nil },
		Databases: func(context.Context, string) ([]string, error) {
			return nil, nil
		},
	}
	m.Defaults()
	ctx := context.Background()
	d := DestinationArgs{Destination: Destination{ID: "d1", Type: Local, Path: filepath.Join(dir, "repo")}}
	out, err := m.SetDestination(ctx, d)
	if err != nil || !out.Created {
		t.Fatalf("%+v %v", out, err)
	}
	if again, err := m.SetDestination(ctx, d); err != nil || again.Created {
		t.Fatalf("again: %+v %v", again, err)
	}
	for i := 0; i < 2; i++ {
		run, err := m.Run(ctx, RunArgs{Destination: "d1", Accounts: []RunAccount{{Username: "shop", Meta: []byte(`{"n":1}`)}}, Keep: Retention{Daily: 1}})
		if err != nil || run.OK != 1 || run.Accounts[0].Snapshot == "" || run.Accounts[0].Files < 2 {
			t.Fatalf("run %d: %+v %v", i, run, err)
		}
		if i == 1 && run.Accounts[0].Forgotten != 1 {
			t.Errorf("the older snapshot was not forgotten: %+v", run.Accounts[0])
		}
	}
	list, err := m.Snapshots(ctx, SnapshotArgs{Destination: "d1", Username: "shop"})
	if err != nil || len(list) != 1 || list[0].Username != "shop" || list[0].Files < 2 {
		t.Fatalf("%+v %v", list, err)
	}
	// What the snapshot holds: the site, the description, not tmp/.
	ls, _, exit, err := m.restic(ctx, Destination{ID: "d1", Type: Local, Path: filepath.Join(dir, "repo")}, nil, "ls", "--no-lock", list[0].ID)
	if err != nil || exit != 0 {
		t.Fatal(exit, err)
	}
	for _, want := range []string{"public_html/index.html", "shop/lpanel.json"} {
		if !strings.Contains(ls, want) {
			t.Errorf("snapshot lacks %s:\n%s", want, ls)
		}
	}
	if strings.Contains(ls, "tmp/session") {
		t.Error("tmp/ was backed up")
	}
	// Browsing, restoring, the description and a download, for real.
	files, err := m.Files(ctx, FilesArgs{Destination: "d1", Snapshot: list[0].Short, Username: "shop"})
	if err != nil || len(files.Entries) != 1 || files.Entries[0].Name != "public_html" {
		t.Fatalf("files: %+v %v", files, err)
	}
	os.WriteFile(filepath.Join(home, "public_html", "index.html"), []byte("broken"), 0o644)
	if _, err := m.Restore(ctx, RestoreArgs{Destination: "d1", Snapshot: list[0].ID, Username: "shop", Paths: []string{"public_html"}}); err != nil {
		t.Fatal(err)
	}
	if got, _ := os.ReadFile(filepath.Join(home, "public_html", "index.html")); string(got) != "hello" {
		t.Errorf("restored: %q", got)
	}
	into, err := m.Restore(ctx, RestoreArgs{Destination: "d1", Snapshot: list[0].ID, Username: "shop", Paths: []string{"public_html/index.html"}, Folder: true})
	if err != nil {
		t.Fatal(err)
	}
	if got, _ := os.ReadFile(filepath.Join(home, into.Folder, "public_html", "index.html")); string(got) != "hello" {
		t.Errorf("restored into %s: %q", into.Folder, got)
	}
	meta, err := m.Meta(ctx, MetaArgs{Destination: "d1", Snapshot: list[0].ID})
	if err != nil || string(meta.Meta) != `{"n":1}` {
		t.Errorf("meta %s %v", meta.Meta, err)
	}
	dl, err := m.Download(ctx, DownloadArgs{Destination: "d1", Snapshot: list[0].ID, Username: "shop"})
	if err != nil || dl.Bytes < 100 {
		t.Fatalf("download %+v %v", dl, err)
	}
	if out, err := exec.Command("tar", "-tzf", filepath.Join(home, dl.File)).CombinedOutput(); err != nil || !strings.Contains(string(out), "public_html/index.html") || !strings.Contains(string(out), "lpanel.json") {
		t.Errorf("archive: %s %v", out, err)
	}

	// Another password does not open it.
	os.WriteFile(m.passFile("d1"), []byte("not the password\n"), 0o600)
	if _, err := m.SetDestination(ctx, d); code(err) != CodeWrongPassword {
		t.Errorf("wrong password: %v", err)
	}
}
