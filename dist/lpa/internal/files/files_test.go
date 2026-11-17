package files

import (
	"archive/tar"
	"archive/zip"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"

	"lpa/internal/protocol"
)

type fixture struct {
	m       *Manager
	home    string
	staging string
}

// newFixture is a Manager whose workers run in the test's own process (so
// as the test's user, not an account's) over a temporary home.
func newFixture(t *testing.T) *fixture {
	t.Helper()
	f := &fixture{home: t.TempDir(), staging: t.TempDir()}
	f.m = &Manager{
		Owner: func(name string) (int, int, string, error) {
			if name != "bob" {
				return 0, 0, "", protocol.Fail("not_ours", name+" was not made by LPanel")
			}
			return 1000, 1000, f.home, nil
		},
		Staging: f.staging,
		Spawn: func(_ context.Context, _, _ int, home string, req []byte, in, out *os.File) ([]byte, error) {
			var r Request
			if err := json.Unmarshal(req, &r); err != nil {
				return nil, err
			}
			if r.Home != home {
				return nil, errors.New("the worker was sent another home")
			}
			result, err := Work(r, in, out)
			return json.Marshal(MakeReply(result, err))
		},
	}
	f.m.Defaults()
	for _, d := range []string{"public_html", "mail", "tmp", "logs"} {
		f.mkdir(t, d)
	}
	return f
}

func (f *fixture) put(t *testing.T, rel, content string) {
	t.Helper()
	p := filepath.Join(f.home, filepath.FromSlash(rel))
	if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(p, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
}

func (f *fixture) mkdir(t *testing.T, rel string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Join(f.home, filepath.FromSlash(rel)), 0o755); err != nil {
		t.Fatal(err)
	}
}

func (f *fixture) get(t *testing.T, rel string) string {
	t.Helper()
	data, err := os.ReadFile(filepath.Join(f.home, filepath.FromSlash(rel)))
	if err != nil {
		t.Fatal(err)
	}
	return string(data)
}

func (f *fixture) exists(rel string) bool {
	_, err := os.Lstat(filepath.Join(f.home, filepath.FromSlash(rel)))
	return err == nil
}

// stage writes content as the panel would and returns its token.
func (f *fixture) stage(t *testing.T, content string) string {
	t.Helper()
	sum := sha256.Sum256([]byte(content + t.Name()))
	token := hex.EncodeToString(sum[:])
	if err := os.WriteFile(filepath.Join(f.staging, token), []byte(content), 0o600); err != nil {
		t.Fatal(err)
	}
	// The agent refuses a staged file root owns (the panel is never root).
	if os.Geteuid() == 0 {
		if err := os.Chown(filepath.Join(f.staging, token), 1000, 1000); err != nil {
			t.Fatal(err)
		}
	}
	return token
}

func code(err error) string {
	var pe *protocol.Error
	if errors.As(err, &pe) {
		return pe.Code
	}
	if err == nil {
		return ""
	}
	return "error: " + err.Error()
}

func TestPathsAreKeptInsideTheHome(t *testing.T) {
	for in, want := range map[string]string{
		"": ".", "/": ".", ".": ".", "/public_html/": "public_html", "public_html//a/./b": "public_html/a/b",
	} {
		got, err := Clean(in)
		if err != nil || got != want {
			t.Errorf("Clean(%q) = %q, %v; want %q", in, got, err, want)
		}
	}
	for _, in := range []string{"..", "../etc", "/../etc/passwd", "a/../../b", "a/../b", "a\x00b", "\xff", strings.Repeat("a/", 2100)} {
		if _, err := Clean(in); code(err) != protocol.CodeBadArgs {
			t.Errorf("Clean(%q) was not refused: %v", in, err)
		}
	}
	for _, name := range []string{"", ".", "..", "a/b", "a\nb", "a\x7fb", strings.Repeat("x", 256)} {
		if CheckName(name) == nil {
			t.Errorf("CheckName(%q) was not refused", name)
		}
	}
	if err := CheckName("Résumé (final).pdf"); err != nil {
		t.Errorf("an ordinary name was refused: %v", err)
	}
	for in, want := range map[string]uint32{"644": 0o644, "0755": 0o755, "0": 0, "700": 0o700} {
		if got, err := Mode(in); err != nil || got != want {
			t.Errorf("Mode(%q) = %o, %v", in, got, err)
		}
	}
	for _, in := range []string{"4755", "888", "", "rwx", "07777"} {
		if _, err := Mode(in); err == nil {
			t.Errorf("Mode(%q) was not refused", in)
		}
	}
}

func TestOnlyTheAgentsOwnAccounts(t *testing.T) {
	f := newFixture(t)
	if _, err := f.m.List(context.Background(), PathArgs{Username: "root", Path: ""}); code(err) != "not_ours" {
		t.Fatalf("listing another user's home: %v", err)
	}
}

func TestListingReadingAndSaving(t *testing.T) {
	f := newFixture(t)
	ctx := context.Background()
	f.put(t, "public_html/index.php", "<?php echo 'hi';\n")
	f.put(t, "public_html/Zebra.txt", "z")
	f.mkdir(t, "public_html/wp-content")
	f.put(t, "public_html/logo.png", "\x89PNG\r\n\x1a\n\x00\x00")

	l, err := f.m.List(ctx, PathArgs{Username: "bob", Path: "/public_html"})
	if err != nil {
		t.Fatal(err)
	}
	var names []string
	for _, e := range l.Entries {
		names = append(names, e.Name+":"+e.Type)
	}
	if got := strings.Join(names, " "); got != "wp-content:dir index.php:file logo.png:file Zebra.txt:file" {
		t.Errorf("listing: %s", got)
	}

	c, err := f.m.Read(ctx, PathArgs{Username: "bob", Path: "public_html/index.php"})
	if err != nil || c.Binary || c.Content != "<?php echo 'hi';\n" {
		t.Fatalf("read: %+v %v", c, err)
	}
	if b, err := f.m.Read(ctx, PathArgs{Username: "bob", Path: "public_html/logo.png"}); err != nil || !b.Binary || b.Content != "" {
		t.Errorf("a binary file was offered to the editor: %+v %v", b, err)
	}
	if _, err := f.m.Read(ctx, PathArgs{Username: "bob", Path: "public_html"}); code(err) != CodeType {
		t.Errorf("reading a folder: %v", err)
	}

	// Saved with the hash it was opened with.
	w, err := f.m.Write(ctx, WriteArgs{Username: "bob", Path: "public_html/index.php", Staged: f.stage(t, "<?php echo 'bye';\n"), Expect: c.SHA256})
	if err != nil || f.get(t, "public_html/index.php") != "<?php echo 'bye';\n" || w.Size != 18 {
		t.Fatalf("save: %+v %v", w, err)
	}
	// Saved again with the old hash: someone else changed it meanwhile.
	if _, err := f.m.Write(ctx, WriteArgs{Username: "bob", Path: "public_html/index.php", Staged: f.stage(t, "lost"), Expect: c.SHA256}); code(err) != CodeChanged {
		t.Errorf("a stale save was not refused: %v", err)
	}
	if f.get(t, "public_html/index.php") != "<?php echo 'bye';\n" {
		t.Error("a stale save changed the file")
	}
	// A new file must be new.
	if _, err := f.m.Write(ctx, WriteArgs{Username: "bob", Path: "public_html/index.php", Staged: f.stage(t, "x"), Create: true}); code(err) != CodeExists {
		t.Errorf("create over an existing file: %v", err)
	}
	if _, err := f.m.Write(ctx, WriteArgs{Username: "bob", Path: "public_html/new.html", Staged: f.stage(t, "<p>new</p>"), Create: true}); err != nil || f.get(t, "public_html/new.html") != "<p>new</p>" {
		t.Errorf("create: %v", err)
	}
	// No temporary file is left behind.
	des, _ := os.ReadDir(filepath.Join(f.home, "public_html"))
	for _, de := range des {
		if strings.HasPrefix(de.Name(), ".lpanel-") {
			t.Errorf("left behind: %s", de.Name())
		}
	}
	// Too big for the editor.
	f.put(t, "big.log", strings.Repeat("x", MaxEdit+1))
	if _, err := f.m.Read(ctx, PathArgs{Username: "bob", Path: "big.log"}); code(err) != CodeTooBig {
		t.Errorf("a big file: %v", err)
	}
	if _, err := f.m.Write(ctx, WriteArgs{Username: "bob", Path: "big2.log", Staged: f.stage(t, strings.Repeat("y", MaxEdit+1))}); code(err) != CodeTooBig {
		t.Errorf("saving a big file: %v", err)
	}
}

func TestStagedFilesAreTakenOnlyAsThePanelMadeThem(t *testing.T) {
	f := newFixture(t)
	ctx := context.Background()
	missing := strings.Repeat("a", 64)
	if _, err := f.m.Upload(ctx, UploadArgs{Username: "bob", Dir: "", Name: "x", Staged: missing}); code(err) != CodeStaged {
		t.Errorf("a missing staged file: %v", err)
	}
	if _, err := f.m.Upload(ctx, UploadArgs{Username: "bob", Dir: "", Name: "x", Staged: "../../etc/shadow"}); code(err) != protocol.CodeBadArgs {
		t.Errorf("a staged name that is a path: %v", err)
	}
	if runtime.GOOS == "windows" {
		return
	}
	secret := filepath.Join(t.TempDir(), "secret")
	if err := os.WriteFile(secret, []byte("root's"), 0o600); err != nil {
		t.Fatal(err)
	}
	link := strings.Repeat("b", 64)
	if err := os.Symlink(secret, filepath.Join(f.staging, link)); err != nil {
		t.Fatal(err)
	}
	if _, err := f.m.Upload(ctx, UploadArgs{Username: "bob", Dir: "", Name: "x", Staged: link}); code(err) != CodeStaged {
		t.Errorf("a staged link was followed: %v", err)
	}
	hard := strings.Repeat("c", 64)
	if err := os.Link(secret, filepath.Join(f.staging, hard)); err != nil {
		t.Fatal(err)
	}
	if _, err := f.m.Upload(ctx, UploadArgs{Username: "bob", Dir: "", Name: "x", Staged: hard}); code(err) != CodeStaged {
		t.Errorf("a staged hard link was taken: %v", err)
	}
	if f.exists("x") {
		t.Error("something was uploaded from a refused staged file")
	}
}

func TestUploadAndDownload(t *testing.T) {
	f := newFixture(t)
	ctx := context.Background()
	w, err := f.m.Upload(ctx, UploadArgs{Username: "bob", Dir: "/public_html", Name: "photo.jpg", Staged: f.stage(t, "JPEG")})
	if err != nil || w.Path != "public_html/photo.jpg" || f.get(t, "public_html/photo.jpg") != "JPEG" {
		t.Fatalf("upload: %+v %v", w, err)
	}
	if _, err := f.m.Upload(ctx, UploadArgs{Username: "bob", Dir: "public_html", Name: "photo.jpg", Staged: f.stage(t, "other")}); code(err) != CodeExists {
		t.Errorf("an upload over a file without overwrite: %v", err)
	}
	if _, err := f.m.Upload(ctx, UploadArgs{Username: "bob", Dir: "public_html", Name: "photo.jpg", Staged: f.stage(t, "newer"), Overwrite: true}); err != nil || f.get(t, "public_html/photo.jpg") != "newer" {
		t.Errorf("overwrite: %v", err)
	}
	if _, err := f.m.Upload(ctx, UploadArgs{Username: "bob", Dir: "public_html/photo.jpg", Name: "x", Staged: f.stage(t, "x")}); code(err) != CodeType {
		t.Errorf("an upload into a file: %v", err)
	}

	into := f.stage(t, "")
	d, err := f.m.Download(ctx, DownloadArgs{Username: "bob", Path: "public_html/photo.jpg", Into: into})
	if err != nil || d.Name != "photo.jpg" || d.Size != 5 {
		t.Fatalf("download: %+v %v", d, err)
	}
	if got, _ := os.ReadFile(filepath.Join(f.staging, into)); string(got) != "newer" {
		t.Errorf("the download holds %q", got)
	}
	if _, err := f.m.Download(ctx, DownloadArgs{Username: "bob", Path: "public_html", Into: into}); code(err) != CodeType {
		t.Errorf("downloading a folder: %v", err)
	}
}

func TestMovingCopyingRenamingDeleting(t *testing.T) {
	f := newFixture(t)
	ctx := context.Background()
	f.put(t, "site/index.html", "home")
	f.put(t, "site/css/app.css", "body{}")
	f.put(t, "notes.txt", "n")

	// The server's own folders stay where they are.
	for _, err := range []error{
		func() error {
			_, err := f.m.Delete(ctx, PathsArgs{Username: "bob", Paths: []string{"public_html"}})
			return err
		}(),
		func() error {
			_, err := f.m.Move(ctx, MoveArgs{Username: "bob", Paths: []string{"mail"}, To: "site"})
			return err
		}(),
		func() error {
			_, err := f.m.Rename(ctx, RenameArgs{Username: "bob", From: "/logs", To: "old-logs"})
			return err
		}(),
		func() error {
			_, err := f.m.Chmod(ctx, ChmodArgs{Username: "bob", Paths: []string{"public_html"}, Mode: "777"})
			return err
		}(),
	} {
		if code(err) != CodeProtected {
			t.Errorf("a server folder was not protected: %v", err)
		}
	}
	if _, err := f.m.Delete(ctx, PathsArgs{Username: "bob", Paths: []string{"/"}}); code(err) != protocol.CodeBadArgs {
		t.Errorf("deleting the home: %v", err)
	}

	// Copy a tree into public_html, then again: the second is refused whole.
	c, err := f.m.Copy(ctx, MoveArgs{Username: "bob", Paths: []string{"site", "notes.txt"}, To: "public_html"})
	if err != nil || c.Count != 5 || f.get(t, "public_html/site/css/app.css") != "body{}" || f.get(t, "site/index.html") != "home" {
		t.Fatalf("copy: %+v %v", c, err)
	}
	if _, err := f.m.Copy(ctx, MoveArgs{Username: "bob", Paths: []string{"site"}, To: "public_html"}); code(err) != CodeExists {
		t.Errorf("a copy over existing files: %v", err)
	}
	if _, err := f.m.Copy(ctx, MoveArgs{Username: "bob", Paths: []string{"site"}, To: "site/css"}); code(err) != CodeInto {
		t.Errorf("a folder copied into itself: %v", err)
	}

	// Rename, then move.
	if _, err := f.m.Rename(ctx, RenameArgs{Username: "bob", From: "notes.txt", To: "todo.txt"}); err != nil || !f.exists("todo.txt") || f.exists("notes.txt") {
		t.Errorf("rename: %v", err)
	}
	if _, err := f.m.Rename(ctx, RenameArgs{Username: "bob", From: "todo.txt", To: "public_html/notes.txt"}); code(err) != CodeExists {
		t.Errorf("a rename over a file: %v", err)
	}
	if _, err := f.m.Rename(ctx, RenameArgs{Username: "bob", From: "todo.txt", To: "nowhere/todo.txt"}); code(err) != CodeMissing {
		t.Errorf("a rename into a missing folder: %v", err)
	}
	m, err := f.m.Move(ctx, MoveArgs{Username: "bob", Paths: []string{"todo.txt", "site"}, To: "tmp"})
	if err != nil || m.Count != 2 || !f.exists("tmp/site/css/app.css") || f.exists("site") {
		t.Errorf("move: %+v %v", m, err)
	}

	// Delete: a folder with everything in it, and something already gone.
	d, err := f.m.Delete(ctx, PathsArgs{Username: "bob", Paths: []string{"tmp/site", "tmp/gone.txt"}})
	if err != nil || d.Count != 1 || d.Skipped != 1 || f.exists("tmp/site") {
		t.Errorf("delete: %+v %v", d, err)
	}

	// Folders and files are made new, never over something.
	if _, err := f.m.Mkdir(ctx, PathArgs{Username: "bob", Path: "public_html/blog"}); err != nil {
		t.Error(err)
	}
	if _, err := f.m.Mkdir(ctx, PathArgs{Username: "bob", Path: "public_html/blog"}); code(err) != CodeExists {
		t.Errorf("a second mkdir: %v", err)
	}
	if _, err := f.m.Create(ctx, PathArgs{Username: "bob", Path: "public_html/blog/.htaccess"}); err != nil || f.get(t, "public_html/blog/.htaccess") != "" {
		t.Errorf("create: %v", err)
	}
	if _, err := f.m.Create(ctx, PathArgs{Username: "bob", Path: "public_html/bad\nname"}); code(err) != protocol.CodeBadArgs {
		t.Errorf("a name with a newline: %v", err)
	}

	if runtime.GOOS != "windows" {
		if _, err := f.m.Chmod(ctx, ChmodArgs{Username: "bob", Paths: []string{"public_html/blog/.htaccess"}, Mode: "600"}); err != nil {
			t.Fatal(err)
		}
		info, _ := os.Stat(filepath.Join(f.home, "public_html/blog/.htaccess"))
		if info.Mode().Perm() != 0o600 {
			t.Errorf("chmod left %o", info.Mode().Perm())
		}
	}
}

func TestLinksDoNotLeadOutOfTheHome(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("symbolic links need privileges on Windows")
	}
	f := newFixture(t)
	ctx := context.Background()
	outside := t.TempDir()
	if err := os.WriteFile(filepath.Join(outside, "passwd"), []byte("root:x:0:0"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(outside, filepath.Join(f.home, "etc")); err != nil {
		t.Fatal(err)
	}
	// A relative link that stays inside is followed as usual.
	if err := os.Symlink("public_html", filepath.Join(f.home, "www")); err != nil {
		t.Fatal(err)
	}
	f.put(t, "public_html/a.txt", "a")
	if c, err := f.m.Read(ctx, PathArgs{Username: "bob", Path: "www/a.txt"}); err != nil || c.Content != "a" {
		t.Errorf("a link inside the home: %+v %v", c, err)
	}

	l, err := f.m.List(ctx, PathArgs{Username: "bob", Path: ""})
	if err != nil {
		t.Fatal(err)
	}
	for _, e := range l.Entries {
		if e.Name == "etc" && (e.Type != "link" || e.To != "outside") {
			t.Errorf("a link out of the home shows as %+v", e)
		}
	}
	for name, err := range map[string]error{
		"list": func() error { _, err := f.m.List(ctx, PathArgs{Username: "bob", Path: "etc"}); return err }(),
		"read": func() error { _, err := f.m.Read(ctx, PathArgs{Username: "bob", Path: "etc/passwd"}); return err }(),
		"write": func() error {
			_, err := f.m.Write(ctx, WriteArgs{Username: "bob", Path: "etc/passwd", Staged: f.stage(t, "pwned")})
			return err
		}(),
		"upload": func() error {
			_, err := f.m.Upload(ctx, UploadArgs{Username: "bob", Dir: "etc", Name: "x", Staged: f.stage(t, "x")})
			return err
		}(),
		"copy": func() error {
			_, err := f.m.Copy(ctx, MoveArgs{Username: "bob", Paths: []string{"public_html/a.txt"}, To: "etc"})
			return err
		}(),
		"extract": func() error {
			_, err := f.m.Extract(ctx, ExtractArgs{Username: "bob", Path: "etc/x.zip", To: "public_html"})
			return err
		}(),
		"usage": func() error { _, err := f.m.Usage(ctx, PathArgs{Username: "bob", Path: "etc"}); return err }(),
	} {
		if code(err) != CodeOutside {
			t.Errorf("%s through a link out of the home: %v", name, err)
		}
	}
	if got, _ := os.ReadFile(filepath.Join(outside, "passwd")); string(got) != "root:x:0:0" {
		t.Error("a file outside the home was changed")
	}
	// Deleting the link removes the link, not what it leads to.
	if _, err := f.m.Delete(ctx, PathsArgs{Username: "bob", Paths: []string{"etc"}}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(outside, "passwd")); err != nil {
		t.Error("deleting a link removed its target")
	}
}

func zipOf(t *testing.T, entries map[string]string) []byte {
	t.Helper()
	var buf bytes.Buffer
	zw := zip.NewWriter(&buf)
	for name, content := range entries {
		w, err := zw.Create(name)
		if err != nil {
			t.Fatal(err)
		}
		_, _ = w.Write([]byte(content))
	}
	if err := zw.Close(); err != nil {
		t.Fatal(err)
	}
	return buf.Bytes()
}

func TestCompressingAndExtracting(t *testing.T) {
	f := newFixture(t)
	ctx := context.Background()
	f.put(t, "public_html/index.php", "<?php")
	f.put(t, "public_html/wp/wp-config.php", "define();")

	for _, name := range []string{"site.zip", "site.tar.gz", "site.tar"} {
		p, err := f.m.Compress(ctx, CompressArgs{Username: "bob", Paths: []string{"public_html/index.php", "public_html/wp"}, To: "backups/" + name})
		if code(err) != CodeMissing {
			t.Fatalf("an archive in a missing folder: %v", err)
		}
		f.mkdir(t, "backups")
		p, err = f.m.Compress(ctx, CompressArgs{Username: "bob", Paths: []string{"public_html/index.php", "public_html/wp"}, To: "backups/" + name})
		if err != nil || p.Items != 3 || p.Size == 0 {
			t.Fatalf("%s: %+v %v", name, p, err)
		}
		if _, err := f.m.Compress(ctx, CompressArgs{Username: "bob", Paths: []string{"public_html/index.php"}, To: "backups/" + name}); code(err) != CodeExists {
			t.Errorf("an archive over an existing file: %v", err)
		}
		u, err := f.m.Extract(ctx, ExtractArgs{Username: "bob", Path: "backups/" + name, To: "restore/" + name})
		if err != nil || u.Files != 2 || f.get(t, "restore/"+name+"/wp/wp-config.php") != "define();" {
			t.Fatalf("extract %s: %+v %v", name, u, err)
		}
		// Again: what is there already is left alone.
		f.put(t, "restore/"+name+"/index.php", "mine")
		u, err = f.m.Extract(ctx, ExtractArgs{Username: "bob", Path: "backups/" + name, To: "restore/" + name})
		if err != nil || u.Files != 0 || u.Skipped != 2 || f.get(t, "restore/"+name+"/index.php") != "mine" {
			t.Errorf("a second extract of %s: %+v %v", name, u, err)
		}
		os.RemoveAll(filepath.Join(f.home, "backups"))
	}
	if _, err := f.m.Compress(ctx, CompressArgs{Username: "bob", Paths: []string{"public_html/index.php", "logs"}, To: "x.zip"}); code(err) != protocol.CodeBadArgs {
		t.Errorf("compressing from two folders: %v", err)
	}
	if _, err := f.m.Compress(ctx, CompressArgs{Username: "bob", Paths: []string{"public_html"}, To: "x.rar"}); code(err) != protocol.CodeBadArgs {
		t.Errorf("an unknown archive format: %v", err)
	}
}

func TestArchivesCannotWriteOutsideTheirFolder(t *testing.T) {
	f := newFixture(t)
	ctx := context.Background()
	f.put(t, "evil.zip", string(zipOf(t, map[string]string{
		"../escaped.txt":        "no",
		"a/../../escaped2.txt":  "no",
		"/abs.txt":              "rooted names become relative",
		"ok/fine.txt":           "yes",
		"bad\nname.txt":         "no",
		"..\\windows-style.txt": "no",
	})))
	u, err := f.m.Extract(ctx, ExtractArgs{Username: "bob", Path: "evil.zip", To: "public_html/x"})
	if err != nil {
		t.Fatal(err)
	}
	if u.Files != 2 || u.Skipped != 4 {
		t.Errorf("extract: %+v", u)
	}
	if f.exists("escaped.txt") || f.exists("public_html/escaped.txt") || f.exists("public_html/escaped2.txt") || f.exists("windows-style.txt") {
		t.Error("an entry escaped the folder")
	}
	if f.get(t, "public_html/x/ok/fine.txt") != "yes" || f.get(t, "public_html/x/abs.txt") == "" {
		t.Error("the ordinary entries were not extracted")
	}

	// A tar with a link and then a file "through" it: the link is not made.
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	_ = tw.WriteHeader(&tar.Header{Name: "link", Typeflag: tar.TypeSymlink, Linkname: "/etc"})
	_ = tw.WriteHeader(&tar.Header{Name: "link/cron.d/evil", Typeflag: tar.TypeReg, Mode: 0o4755, Size: 2})
	_, _ = tw.Write([]byte("no"))
	_ = tw.Close()
	_ = gz.Close()
	f.put(t, "evil.tgz", buf.String())
	u, err = f.m.Extract(ctx, ExtractArgs{Username: "bob", Path: "evil.tgz", To: "t"})
	if err != nil {
		t.Fatal(err)
	}
	if u.Skipped != 1 || u.Files != 1 {
		t.Errorf("tar extract: %+v", u)
	}
	info, err := os.Lstat(filepath.Join(f.home, "t", "link"))
	if err != nil || !info.IsDir() {
		t.Fatalf("the link was made as a link, or not at all: %v", err)
	}
	if runtime.GOOS != "windows" {
		info, _ = os.Stat(filepath.Join(f.home, "t/link/cron.d/evil"))
		if info.Mode()&os.ModeSetuid != 0 || info.Mode().Perm() != 0o755 {
			t.Errorf("an extracted file kept %v", info.Mode())
		}
	}

	f.put(t, "broken.zip", "not a zip")
	if _, err := f.m.Extract(ctx, ExtractArgs{Username: "bob", Path: "broken.zip", To: "b"}); code(err) != CodeArchive {
		t.Errorf("a broken archive: %v", err)
	}
}

func TestDiskUsage(t *testing.T) {
	f := newFixture(t)
	ctx := context.Background()
	f.put(t, "public_html/big.bin", strings.Repeat("x", 300000))
	f.put(t, "public_html/small.txt", "s")
	f.put(t, "mail/example.com/info/Maildir/cur/1", strings.Repeat("m", 100000))
	f.put(t, "notes.txt", "n")

	u, err := f.m.Usage(ctx, PathArgs{Username: "bob", Path: ""})
	if err != nil {
		t.Fatal(err)
	}
	if len(u.Entries) < 3 || u.Entries[0].Name != "public_html" || u.Entries[1].Name != "mail" || u.Truncated {
		t.Fatalf("usage: %+v", u)
	}
	if u.Entries[0].Bytes < 300000 || u.Entries[0].Items != 2 || u.Entries[1].Items != 5 {
		t.Errorf("public_html: %+v, mail: %+v", u.Entries[0], u.Entries[1])
	}
	if u.Bytes < 400000 {
		t.Errorf("the total is %d", u.Bytes)
	}
	if _, err := f.m.Usage(ctx, PathArgs{Username: "bob", Path: "notes.txt"}); code(err) != CodeType {
		t.Errorf("usage of a file: %v", err)
	}
}

func TestStagingBufferDropsWhatIsTooMuch(t *testing.T) {
	var l limited
	l.max = 4
	n, err := l.Write([]byte("abcdef"))
	if n != 6 || err != nil || l.String() != "abcd" {
		t.Errorf("limited: %d %v %q", n, err, l.String())
	}
}
