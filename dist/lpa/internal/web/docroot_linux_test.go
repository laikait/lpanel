package web

import (
	"os"
	"path/filepath"
	"syscall"
	"testing"
)

// The account owns its home and can plant links anywhere in it.
func TestMakeDirsRefusesLinksAndSetsTheWebGroup(t *testing.T) {
	uid, gid := os.Getuid(), os.Getgid()
	home := filepath.Join(t.TempDir(), "shop1")
	outside := filepath.Join(t.TempDir(), "elsewhere")
	os.Mkdir(home, 0o711)
	os.Mkdir(outside, 0o755)

	made, err := makeDirs(home, []string{"public_html", "public_html/blog/en"}, uid, gid, gid)
	// tmp, logs, public_html, public_html/blog and public_html/blog/en.
	if err != nil || len(made) != 5 {
		t.Fatalf("%v %v", made, err)
	}
	st, err := os.Stat(filepath.Join(home, "public_html"))
	if err != nil || st.Mode().Perm() != 0o750 {
		t.Fatalf("public_html: %v %v", st, err)
	}
	if st, _ := os.Stat(filepath.Join(home, "public_html", "blog", "en")); st == nil || st.Mode().Perm() != 0o755 {
		t.Fatal("no nested root")
	}
	if st, _ := os.Stat(filepath.Join(home, "tmp")); st == nil || st.Mode().Perm() != 0o700 {
		t.Fatal("no private tmp")
	}

	// A link as the top folder, or on the way down, is refused.
	os.Symlink(outside, filepath.Join(home, "addon.test"))
	if _, err := makeDirs(home, []string{"addon.test"}, uid, gid, gid); code(err) != CodeDocroot {
		t.Fatalf("a link as the root: %v", err)
	}
	os.Symlink(outside, filepath.Join(home, "public_html", "shop"))
	if _, err := makeDirs(home, []string{"public_html/shop/www"}, uid, gid, gid); code(err) != CodeDocroot {
		t.Fatalf("a link on the way: %v", err)
	}
	if _, err := os.Stat(filepath.Join(outside, "www")); !os.IsNotExist(err) {
		t.Fatal("a folder was made through the link")
	}

	// A tmp that is a link is refused too.
	os.Remove(filepath.Join(home, "tmp"))
	os.Symlink(outside, filepath.Join(home, "tmp"))
	if _, err := makeDirs(home, nil, uid, gid, gid); code(err) != CodeDocroot {
		t.Fatalf("a linked tmp: %v", err)
	}

	// A top folder someone else owns is not taken over (needs root to set up).
	if uid == 0 {
		os.Mkdir(filepath.Join(home, "foreign"), 0o755)
		syscall.Chown(filepath.Join(home, "foreign"), 4242, 4242)
		os.Remove(filepath.Join(home, "tmp"))
		if _, err := makeDirs(home, []string{"foreign"}, uid, gid, gid); code(err) != CodeDocroot {
			t.Fatalf("someone else's folder: %v", err)
		}
	}
}
