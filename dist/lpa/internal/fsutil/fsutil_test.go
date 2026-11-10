package fsutil

import (
	"os"
	"path/filepath"
	"runtime"
	"testing"
)

func TestWriteFileAtomic(t *testing.T) {
	path := filepath.Join(t.TempDir(), "f")
	if err := WriteFileAtomic(path, []byte("one"), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := WriteFileAtomic(path, []byte("two"), 0o600); err != nil {
		t.Fatal(err)
	}
	if got, _ := os.ReadFile(path); string(got) != "two" {
		t.Fatalf("got %q", got)
	}
	entries, _ := os.ReadDir(filepath.Dir(path))
	if len(entries) != 1 {
		t.Errorf("temporary files left behind: %v", entries)
	}
	if runtime.GOOS != "windows" {
		if info, _ := os.Stat(path); info.Mode().Perm() != 0o600 {
			t.Errorf("mode %v", info.Mode().Perm())
		}
	}
}

func TestEnsureDirRefusesAWiderOrNonDirectoryPath(t *testing.T) {
	base := t.TempDir()
	fresh := filepath.Join(base, "fresh")
	if err := EnsureDir(fresh, 0o700); err != nil {
		t.Fatalf("a new directory: %v", err)
	}

	file := filepath.Join(base, "file")
	_ = os.WriteFile(file, nil, 0o600)
	if err := EnsureDir(file, 0o700); err == nil {
		t.Error("a file was accepted as a directory")
	}

	if runtime.GOOS == "windows" {
		return
	}
	wide := filepath.Join(base, "wide")
	_ = os.Mkdir(wide, 0o700)
	_ = os.Chmod(wide, 0o755)
	if err := EnsureDir(wide, 0o700); err == nil {
		t.Error("a 0755 directory was accepted where 0700 is required")
	}
}
