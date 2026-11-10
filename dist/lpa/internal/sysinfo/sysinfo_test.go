package sysinfo

import (
	"errors"
	"path/filepath"
	"strings"
	"testing"
)

func fixture() *Reader {
	root := filepath.Join("testdata", "root")
	return &Reader{Root: root, Statfs: func(path string) (FSStats, error) {
		rel := filepath.ToSlash(strings.TrimPrefix(path, root))
		if rel == "" {
			rel = "/" // filepath.Join(root, "/") is root itself
		}
		switch rel {
		case "/", "/etc/lpa":
			return FSStats{TotalBytes: 100, FreeBytes: 40, AvailBytes: 35, TotalInodes: 10, FreeInodes: 6, Device: 1}, nil
		case "/srv/web data":
			return FSStats{TotalBytes: 500, FreeBytes: 500, AvailBytes: 500, TotalInodes: 50, FreeInodes: 50, Device: 2}, nil
		}
		return FSStats{}, errors.New("unexpected statfs of " + rel)
	}}
}

func TestOSRelease(t *testing.T) {
	rel, err := fixture().OSRelease()
	if err != nil {
		t.Fatal(err)
	}
	want := OSRelease{ID: "debian", VersionID: "13", Codename: "trixie", PrettyName: "Debian GNU/Linux 13 (trixie)"}
	if rel != want {
		t.Fatalf("got %+v", rel)
	}
}

func TestMachineFacts(t *testing.T) {
	r := fixture()
	if k, _ := r.Kernel(); k != "6.12.0-test-amd64" {
		t.Errorf("kernel %q", k)
	}
	if h, _ := r.Hostname(); h != "web-01" {
		t.Errorf("hostname %q", h)
	}
	if n, _ := r.CPUCount(); n != 2 {
		t.Errorf("cpus %d", n)
	}
	m, err := r.Memory()
	if err != nil || m.TotalBytes != 2048000*1024 || m.AvailableBytes != 1024000*1024 || m.SwapFreeBytes != 256000*1024 {
		t.Errorf("memory %+v %v", m, err)
	}
	l, err := r.Load()
	if err != nil || l != (Load{0.52, 0.48, 0.40}) {
		t.Errorf("load %+v %v", l, err)
	}
	if u, _ := r.UptimeSeconds(); u != 12345 {
		t.Errorf("uptime %d", u)
	}
}

func TestDisksFoldsBindMountsAndSkipsPseudoFilesystems(t *testing.T) {
	disks, err := fixture().Disks()
	if err != nil {
		t.Fatal(err)
	}
	if len(disks) != 2 {
		t.Fatalf("want 2 disks, got %+v", disks)
	}
	if disks[0].Mount != "/" || disks[0].UsedBytes != 60 || disks[0].AvailBytes != 35 {
		t.Errorf("root disk %+v", disks[0])
	}
	if disks[1].Mount != "/srv/web data" || disks[1].FSType != "xfs" {
		t.Errorf("escaped mount point not decoded: %+v", disks[1])
	}
}

func TestMissingFilesAreErrors(t *testing.T) {
	r := &Reader{Root: t.TempDir(), Statfs: statfs}
	if _, err := r.OSRelease(); err == nil {
		t.Error("OSRelease")
	}
	if _, err := r.Memory(); err == nil {
		t.Error("Memory")
	}
	if _, err := r.CPUCount(); err == nil {
		t.Error("CPUCount")
	}
}

func TestUnescapeMount(t *testing.T) {
	for in, want := range map[string]string{`/a\040b`: "/a b", `/x\011y`: "/x\ty", `/plain`: "/plain", `/end\04`: `/end\04`, `/back\134slash`: `/back\slash`} {
		if got := unescapeMount(in); got != want {
			t.Errorf("%q: got %q want %q", in, got, want)
		}
	}
}
