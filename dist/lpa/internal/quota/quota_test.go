package quota

import (
	"os"
	"path/filepath"
	"testing"
)

func TestMountOf(t *testing.T) {
	table := filepath.Join(t.TempDir(), "mounts")
	body := "/dev/mapper/root / xfs rw,relatime 0 0\n" +
		"/dev/sdb1 /home ext4 rw,relatime,quota,usrquota 0 0\n" +
		"/dev/sdc1 /home2 ext4 rw 0 0\n" +
		"/dev/sdd1 /srv/with\\040space xfs rw,usrquota 0 0\n"
	if err := os.WriteFile(table, []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}
	for dir, want := range map[string]string{
		"/home":                  "/dev/sdb1",
		"/home/alice":            "/dev/sdb1",
		"/home2/bob":             "/dev/sdc1", // not /home: a prefix of the name is not a parent
		"/var/www":               "/dev/mapper/root",
		"/srv/with space/carol":  "/dev/sdd1",
		"/home/../etc/something": "/dev/mapper/root",
	} {
		dev, _, err := mountOf(table, dir)
		if err != nil || dev != want {
			t.Errorf("%s: got %q %v, want %q", dir, dev, err, want)
		}
	}
}

func TestOffSetsNothingAndKnowsNothing(t *testing.T) {
	if err := (Off{}).Set("/home", 1000, Limits{BlocksKiB: 1}); err != nil {
		t.Fatal(err)
	}
	if _, err := (Off{}).Get("/home", 1000); err != ErrOff {
		t.Fatalf("got %v", err)
	}
}
