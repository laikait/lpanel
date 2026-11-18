package backup

import (
	"compress/gzip"
	"context"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// withSnapshot sets the fake up with a destination and one snapshot of
// username, made on a server whose homes were in oldHomes.
func withSnapshot(t *testing.T, username string) (*Manager, *fakeRestic, string, string) {
	m, f, dir := setup(t)
	f.answer["cat"] = func(Command) (string, string, int) { return "{}", "", 0 }
	if _, err := m.SetDestination(context.Background(), local(dir)); err != nil {
		t.Fatal(err)
	}
	oldHome := "/home2/" + username
	f.answer["snapshots"] = func(c Command) (string, string, int) {
		id := c.Args[len(c.Args)-1]
		if id != "0123abcd" {
			return "", "Fatal: no matching ID found for prefix \"" + id + "\"", 1
		}
		return fmt.Sprintf(`[{"id":"0123abcd0123abcd","short_id":"0123abcd","time":"2026-11-18T02:30:00Z","hostname":"lpanel","tags":["lpanel","account:%s"],"paths":["%s","/var/lib/lpa/backup/%s"]}]`, username, oldHome, username), "", 0
	}
	// restic restore: what the snapshot holds, under the target.
	f.answer["restore"] = func(c Command) (string, string, int) {
		target := ""
		for i, a := range c.Args {
			if a == "--target" {
				target = c.Args[i+1]
			}
		}
		put := func(rel, content string) {
			p := filepath.Join(target, oldHome, rel)
			os.MkdirAll(filepath.Dir(p), 0o755)
			os.WriteFile(p, []byte(content), 0o644)
		}
		put("public_html/index.html", "from the backup")
		put("public_html/wp/config.php", "<?php // old")
		put("public_html/evil/x.txt", "a folder in the backup")
		put("mail/shop.test/info/Maildir/cur/1.eml", "Subject: hi")
		os.Symlink("index.html", filepath.Join(target, oldHome, "public_html", "home.html"))
		return "", "", 0
	}
	f.answer["dump"] = func(c Command) (string, string, int) {
		what := c.Args[len(c.Args)-1]
		switch {
		case strings.HasSuffix(what, "/databases/"+username+"_wp.sql"):
			io.WriteString(c.Stdout, "CREATE DATABASE `"+username+"_wp`;\n")
		case strings.HasSuffix(what, "lpanel.json"):
			io.WriteString(c.Stdout, `{"format":1,"account":{"username":"`+username+`"}}`)
		case what == "/" || strings.HasPrefix(what, oldHome):
			io.WriteString(c.Stdout, "a tar archive of "+what)
		default:
			return "", "Fatal: cannot dump file: path " + what + " not found in snapshot", 1
		}
		return "", "", 0
	}
	f.answer["ls"] = func(c Command) (string, string, int) {
		dir := c.Args[len(c.Args)-1]
		lines := []string{`{"time":"2026-11-18T02:30:00Z","paths":["x"],"struct_type":"snapshot"}`}
		node := func(name, typ string, size int) {
			lines = append(lines, fmt.Sprintf(`{"name":%q,"type":%q,"path":%q,"size":%d,"mtime":"2026-11-17T10:00:00Z","struct_type":"node"}`, name, typ, dir+"/"+name, size))
		}
		switch dir {
		case oldHome:
			lines = append(lines, fmt.Sprintf(`{"name":%q,"type":"dir","path":%q,"struct_type":"node"}`, username, oldHome))
			node("public_html", "dir", 0)
			node("tmp", "dir", 0)
			node(".bashrc", "file", 120)
			node("mail", "dir", 0)
		case "/var/lib/lpa/backup/" + username + "/databases":
			node(username+"_wp.sql", "file", 4000)
			node("other_shop.sql", "file", 10)
		}
		return strings.Join(lines, "\n") + "\n", "", 0
	}
	homes := filepath.Join(dir, "home")
	home := filepath.Join(homes, username)
	os.MkdirAll(filepath.Join(home, "public_html"), 0o755)
	return m, f, dir, home
}

func restore(m *Manager, a RestoreArgs) (RestoreResult, error) {
	if a.Destination == "" {
		a.Destination = "d1"
	}
	if a.Snapshot == "" {
		a.Snapshot = "0123abcd"
	}
	return m.Restore(context.Background(), a)
}

func TestARestorePutsFilesBackAndKeepsNewerOnes(t *testing.T) {
	m, _, dir, home := withSnapshot(t, "shop")
	os.WriteFile(filepath.Join(home, "public_html", "index.html"), []byte("changed since"), 0o644)
	os.WriteFile(filepath.Join(home, "public_html", "new.html"), []byte("made since"), 0o644)
	// The owner made public_html/evil a link out of the home: the restore
	// must not write through it.
	outside := filepath.Join(dir, "outside")
	os.MkdirAll(outside, 0o755)
	os.Symlink(outside, filepath.Join(home, "public_html", "evil"))

	out, err := restore(m, RestoreArgs{Username: "shop", Paths: []string{"public_html"}})
	if err != nil {
		t.Fatal(err)
	}
	if got, _ := os.ReadFile(filepath.Join(home, "public_html", "index.html")); string(got) != "from the backup" {
		t.Errorf("index.html: %q", got)
	}
	if got, _ := os.ReadFile(filepath.Join(home, "public_html", "new.html")); string(got) != "made since" {
		t.Error("a file made since the backup was removed")
	}
	if entries, _ := os.ReadDir(outside); len(entries) != 0 {
		t.Errorf("the restore wrote through the owner's link: %v", entries)
	}
	if fi, err := os.Lstat(filepath.Join(home, "public_html", "evil")); err != nil || !fi.IsDir() {
		t.Errorf("the link was not replaced by the folder: %v %v", fi, err)
	}
	if target, err := os.Readlink(filepath.Join(home, "public_html", "home.html")); err != nil || target != "index.html" {
		t.Errorf("the snapshot's own link: %q %v", target, err)
	}
	if out.Files != 4 || out.Folder != "" {
		t.Errorf("%+v", out)
	}
	if os.Geteuid() == 0 {
		fi, _ := os.Stat(filepath.Join(home, "public_html", "wp", "config.php"))
		if uid, gid := owner(fi); uid != 1001 || gid != 1001 {
			t.Errorf("owned by %d:%d", uid, gid)
		}
	}
	// Nothing is left in the staging folder.
	if entries, _ := os.ReadDir(m.RestoreDir); len(entries) != 0 {
		t.Errorf("staging left: %v", entries)
	}
	if mail, err := os.ReadFile(filepath.Join(home, "mail", "shop.test", "info", "Maildir", "cur", "1.eml")); err == nil {
		t.Errorf("a path not asked for was put back: %q", mail)
	}
}

func TestARestoreIntoAFolderLeavesTheFilesAlone(t *testing.T) {
	m, _, _, home := withSnapshot(t, "shop")
	os.WriteFile(filepath.Join(home, "public_html", "index.html"), []byte("changed since"), 0o644)
	out, err := restore(m, RestoreArgs{Username: "shop", Paths: []string{"/public_html/index.html", "mail/shop.test/info"}, Folder: true})
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(out.Folder, "restored-") || out.Files != 2 {
		t.Fatalf("%+v", out)
	}
	if got, _ := os.ReadFile(filepath.Join(home, out.Folder, "public_html", "index.html")); string(got) != "from the backup" {
		t.Errorf("restored copy: %q", got)
	}
	if got, _ := os.ReadFile(filepath.Join(home, "public_html", "index.html")); string(got) != "changed since" {
		t.Errorf("the file itself was changed: %q", got)
	}
	if _, err := os.Stat(filepath.Join(home, out.Folder, "mail", "shop.test", "info", "Maildir", "cur", "1.eml")); err != nil {
		t.Error(err)
	}
}

func TestOnlyTheAccountsOwnSnapshotsAndPaths(t *testing.T) {
	m, _, _, _ := withSnapshot(t, "other")
	if _, err := restore(m, RestoreArgs{Username: "shop", Paths: []string{"public_html"}}); code(err) != CodeNotInSnapshot {
		t.Errorf("another account's snapshot: %v", err)
	}
	for _, p := range []string{"../etc", "public_html/../../x", "a/*.php", "x[1]"} {
		if _, err := restore(m, RestoreArgs{Username: "other", Paths: []string{p}}); code(err) != "bad_args" {
			t.Errorf("%q: %v", p, err)
		}
	}
	if _, err := restore(m, RestoreArgs{Username: "other", Databases: []string{"shop_wp"}}); code(err) != "bad_args" {
		t.Errorf("another account's database: %v", err)
	}
	if _, err := restore(m, RestoreArgs{Username: "other", Snapshot: "99999999", Paths: []string{"x"}}); code(err) != CodeNotInSnapshot {
		t.Errorf("no such snapshot: %v", err)
	}
	if _, err := restore(m, RestoreArgs{Username: "other", Paths: []string{"not/there"}}); code(err) != CodeNotInSnapshot {
		t.Errorf("a path the snapshot lacks: %v", err)
	}
}

func TestADatabaseIsLoadedFromItsDump(t *testing.T) {
	m, f, _, _ := withSnapshot(t, "shop")
	var loaded string
	f.answer["--default-character-set=utf8mb4"] = func(c Command) (string, string, int) {
		b, _ := io.ReadAll(c.Stdin)
		loaded = string(b)
		return "", "", 0
	}
	out, err := restore(m, RestoreArgs{Username: "shop", Databases: []string{"shop_wp"}})
	if err != nil || len(out.Databases) != 1 {
		t.Fatalf("%+v %v", out, err)
	}
	if loaded != "CREATE DATABASE `shop_wp`;\n" {
		t.Errorf("mariadb was given %q", loaded)
	}
	if _, err := restore(m, RestoreArgs{Username: "shop", Databases: []string{"shop_gone"}}); code(err) != CodeNotInSnapshot {
		t.Errorf("a database the snapshot lacks: %v", err)
	}
}

func TestASnapshotsFoldersAndDatabases(t *testing.T) {
	m, _, _, _ := withSnapshot(t, "shop")
	out, err := m.Files(context.Background(), FilesArgs{Destination: "d1", Snapshot: "0123abcd", Username: "shop"})
	if err != nil {
		t.Fatal(err)
	}
	names := []string{}
	for _, e := range out.Entries {
		names = append(names, e.Name)
	}
	if strings.Join(names, ",") != "mail,public_html,.bashrc" {
		t.Errorf("entries %v (folders first, not tmp)", names)
	}
	if strings.Join(out.Databases, ",") != "shop_wp" {
		t.Errorf("databases %v (only the account's)", out.Databases)
	}
	if _, err := m.Files(context.Background(), FilesArgs{Destination: "d1", Snapshot: "0123abcd", Username: "shop", Path: "nothing"}); code(err) != CodeNotInSnapshot {
		t.Errorf("a missing folder: %v", err)
	}
	meta, err := m.Meta(context.Background(), MetaArgs{Destination: "d1", Snapshot: "0123abcd"})
	if err != nil || meta.Snapshot.Username != "shop" || !strings.Contains(string(meta.Meta), `"format":1`) {
		t.Errorf("%+v %v", meta, err)
	}
}

func TestADownloadIsAnArchiveInTheHome(t *testing.T) {
	m, _, _, home := withSnapshot(t, "shop")
	out, err := m.Download(context.Background(), DownloadArgs{Destination: "d1", Snapshot: "0123abcd", Username: "shop", Path: "public_html"})
	if err != nil {
		t.Fatal(err)
	}
	if out.File != "lpanel-backups/shop-20261118-023000-public_html.tar.gz" {
		t.Errorf("file %s", out.File)
	}
	f, err := os.Open(filepath.Join(home, out.File))
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	gz, err := gzip.NewReader(f)
	if err != nil {
		t.Fatal(err)
	}
	if b, _ := io.ReadAll(gz); string(b) != "a tar archive of /home2/shop/public_html" {
		t.Errorf("content %q", b)
	}
	if fi, _ := os.Stat(filepath.Join(home, "lpanel-backups")); fi.Mode().Perm() != 0o700 {
		t.Errorf("folder mode %v", fi.Mode())
	}
	db, err := m.Download(context.Background(), DownloadArgs{Destination: "d1", Snapshot: "0123abcd", Username: "shop", Database: "shop_wp"})
	if err != nil || db.File != "lpanel-backups/shop_wp-20261118-023000.sql.gz" {
		t.Errorf("%+v %v", db, err)
	}
	all, err := m.Download(context.Background(), DownloadArgs{Destination: "d1", Snapshot: "0123abcd", Username: "shop"})
	if err != nil || all.File != "lpanel-backups/shop-20261118-023000.tar.gz" {
		t.Errorf("%+v %v", all, err)
	}
	if _, err := m.Download(context.Background(), DownloadArgs{Destination: "d1", Snapshot: "0123abcd", Username: "shop", Database: "other_wp"}); code(err) != "bad_args" {
		t.Errorf("another account's database: %v", err)
	}
	// The owner's link where the folder goes is replaced, not followed.
	os.RemoveAll(filepath.Join(home, "lpanel-backups"))
	os.Symlink("/etc", filepath.Join(home, "lpanel-backups"))
	if _, err := m.Download(context.Background(), DownloadArgs{Destination: "d1", Snapshot: "0123abcd", Username: "shop", Database: "shop_wp"}); err != nil {
		t.Fatal(err)
	}
	if fi, _ := os.Lstat(filepath.Join(home, "lpanel-backups")); !fi.IsDir() {
		t.Error("the link was followed")
	}
}

func TestAnotherServersRepositoryOpensWithItsPassword(t *testing.T) {
	m, f, dir := setup(t)
	f.answer["cat"] = func(Command) (string, string, int) { return "{}", "", 0 }
	d := local(dir)
	d.Password = "0f0e0d0c0b0a"
	if _, err := m.SetDestination(context.Background(), d); err != nil {
		t.Fatal(err)
	}
	if pw, _ := m.Password("d1"); pw != "0f0e0d0c0b0a" {
		t.Errorf("password %q", pw)
	}
	// Not for a destination that is there already.
	if _, err := m.SetDestination(context.Background(), d); code(err) != "bad_args" {
		t.Errorf("again: %v", err)
	}
	// A wrong one is said so, and leaves nothing behind.
	f.answer["cat"] = func(Command) (string, string, int) { return "", "Fatal: wrong password or no key found", 12 }
	d2 := DestinationArgs{Destination: Destination{ID: "d2", Type: Local, Path: filepath.Join(dir, "b2")}, Password: "wrong"}
	if _, err := m.SetDestination(context.Background(), d2); code(err) != CodeWrongPassword {
		t.Errorf("wrong: %v", err)
	}
	if _, err := os.Stat(m.passFile("d2")); !os.IsNotExist(err) {
		t.Error("the password of a destination that did not work was kept")
	}
	// No repository there for it to open.
	f.answer["cat"] = func(Command) (string, string, int) { return "", "Fatal: repository does not exist", 10 }
	if _, err := m.SetDestination(context.Background(), d2); code(err) != CodeUnreachable {
		t.Errorf("none there: %v", err)
	}
}
