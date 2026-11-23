package isolation

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"lpa/internal/protocol"
)

type fakeRun struct {
	calls []string
	fail  map[string]string
	after func(call string)
}

func (f *fakeRun) Run(_ context.Context, tool string, args ...string) (int, string, error) {
	call := tool + " " + strings.Join(args, " ")
	f.calls = append(f.calls, call)
	for p, out := range f.fail {
		if strings.HasPrefix(call, p) {
			return 1, out, nil
		}
	}
	if f.after != nil {
		f.after(call)
	}
	return 0, "", nil
}

func (f *fakeRun) ran(prefix string) int {
	n := 0
	for _, c := range f.calls {
		if strings.HasPrefix(c, prefix) {
			n++
		}
	}
	return n
}

func setup(t *testing.T) (*Manager, *fakeRun, string) {
	t.Helper()
	root := t.TempDir()
	run := &fakeRun{fail: map[string]string{}}
	m := &Manager{
		Run: run, SysctlFile: filepath.Join(root, "sysctl.d", "90.conf"), UnitFile: filepath.Join(root, "system", "lpanel-hidepid.service"),
		ProcMounts: filepath.Join(root, "mounts"), ProcSys: filepath.Join(root, "sys"),
	}
	os.MkdirAll(filepath.Dir(m.SysctlFile), 0o755)
	os.MkdirAll(filepath.Dir(m.UnitFile), 0o755)
	os.WriteFile(m.ProcMounts, []byte("sysfs /sys sysfs rw 0 0\nproc /proc proc rw,nosuid,nodev,noexec,relatime 0 0\n"), 0o644)
	for name, v := range map[string]string{"symlinks": "1", "hardlinks": "1", "regular": "2", "fifos": "1"} {
		os.MkdirAll(filepath.Join(root, "sys", "fs"), 0o755)
		os.WriteFile(filepath.Join(root, "sys", "fs", "protected_"+name), []byte(v+"\n"), 0o644)
	}
	return m, run, root
}

func TestInfoReadsTheSystem(t *testing.T) {
	m, _, root := setup(t)
	info := m.Info(context.Background())
	if info.Hidepid || info.Mode != "0" || info.HidepidKept || info.LinksKept {
		t.Fatalf("%+v", info)
	}
	if info.LinksOK {
		t.Fatalf("fifos are 1, the setting wants 2: %+v", info.Links)
	}
	os.WriteFile(m.ProcMounts, []byte("proc /proc proc rw,nosuid,nodev,noexec,relatime,hidepid=invisible,gid=0 0 0\n"), 0o644)
	os.WriteFile(filepath.Join(root, "sys", "fs", "protected_fifos"), []byte("2\n"), 0o644)
	info = m.Info(context.Background())
	if !info.Hidepid || info.Mode != "invisible" || !info.LinksOK {
		t.Fatalf("%+v", info)
	}
}

func TestLinksAreWrittenLoadedAndLoadedOnce(t *testing.T) {
	m, run, _ := setup(t)
	ctx := context.Background()
	if _, err := m.Apply(ctx, Settings{Links: true}); err != nil {
		t.Fatal(err)
	}
	b, _ := os.ReadFile(m.SysctlFile)
	for _, want := range []string{"fs.protected_symlinks = 1", "fs.protected_hardlinks = 1", "fs.protected_regular = 2", "fs.protected_fifos = 2"} {
		if !strings.Contains(string(b), want) {
			t.Fatalf("%s:\n%s", want, b)
		}
	}
	if run.ran("sysctl -p "+m.SysctlFile) != 1 {
		t.Fatalf("%v", run.calls)
	}

	// Off: the file goes and the distribution's values are put back at once.
	run.calls = nil
	if _, err := m.Apply(ctx, Settings{}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(m.SysctlFile); err == nil {
		t.Fatal("the file must be gone")
	}
	if run.ran("sysctl -w fs.protected_symlinks=1 fs.protected_hardlinks=1 fs.protected_regular=2 fs.protected_fifos=1") != 1 {
		t.Fatalf("%v", run.calls)
	}

	run.fail["sysctl"] = "permission denied"
	_, err := m.Apply(ctx, Settings{Links: true})
	var pe *protocol.Error
	if err == nil || !asErr(err, &pe) || pe.Code != CodeSysctl {
		t.Fatalf("%v", err)
	}
}

func asErr(err error, pe **protocol.Error) bool {
	e, ok := err.(*protocol.Error)
	if ok {
		*pe = e
	}
	return ok
}

func TestHidepidIsAUnitAndAMount(t *testing.T) {
	m, run, _ := setup(t)
	ctx := context.Background()
	run.after = func(call string) {
		if strings.HasPrefix(call, "mount -o remount,hidepid=2") {
			os.WriteFile(m.ProcMounts, []byte("proc /proc proc rw,hidepid=invisible,gid=0 0 0\n"), 0o644)
		}
		if strings.HasPrefix(call, "mount -o remount,hidepid=0") {
			os.WriteFile(m.ProcMounts, []byte("proc /proc proc rw 0 0\n"), 0o644)
		}
	}
	info, err := m.Apply(ctx, Settings{Hidepid: true})
	if err != nil || !info.Hidepid || !info.HidepidKept {
		t.Fatalf("%v %+v", err, info)
	}
	unit, _ := os.ReadFile(m.UnitFile)
	if !strings.Contains(string(unit), "ExecStart=/usr/bin/mount -o remount,hidepid=2,gid=0 /proc") || !strings.Contains(string(unit), "ExecStop=/usr/bin/mount -o remount,hidepid=0 /proc") {
		t.Fatalf("%s", unit)
	}
	if run.ran("systemctl enable --quiet lpanel-hidepid.service") != 1 || run.ran("systemctl daemon-reload") != 1 {
		t.Fatalf("%v", run.calls)
	}

	// The same again: no new mount, no reload.
	run.calls = nil
	if _, err := m.Apply(ctx, Settings{Hidepid: true}); err != nil {
		t.Fatal(err)
	}
	if run.ran("mount") != 0 || run.ran("systemctl daemon-reload") != 0 {
		t.Fatalf("nothing to do: %v", run.calls)
	}

	// Off.
	run.calls = nil
	info, err = m.Apply(ctx, Settings{})
	if err != nil || info.Hidepid || info.HidepidKept {
		t.Fatalf("%v %+v", err, info)
	}
	if run.ran("systemctl disable --quiet lpanel-hidepid.service") != 1 || run.ran("mount -o remount,hidepid=0 /proc") != 1 {
		t.Fatalf("%v", run.calls)
	}
}

func TestAMountThatFailsIsSaidPlainly(t *testing.T) {
	m, run, _ := setup(t)
	run.fail["mount"] = "mount: /proc: permission denied."
	_, err := m.Apply(context.Background(), Settings{Hidepid: true})
	var pe *protocol.Error
	if err == nil || !asErr(err, &pe) || pe.Code != CodeProc || !strings.Contains(pe.Message, "permission denied") {
		t.Fatalf("%v", err)
	}
}

func TestAMinimalSystemHasNoSysctlFolderYet(t *testing.T) {
	m, _, root := setup(t)
	os.RemoveAll(filepath.Join(root, "sysctl.d"))
	os.RemoveAll(filepath.Join(root, "system"))
	if _, err := m.Apply(context.Background(), Settings{Hidepid: true, Links: true}); err != nil {
		t.Fatal(err)
	}
	for _, f := range []string{m.SysctlFile, m.UnitFile} {
		if _, err := os.Stat(f); err != nil {
			t.Fatalf("%s: %v", f, err)
		}
	}
}
