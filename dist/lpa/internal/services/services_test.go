package services

import (
	"context"
	"strings"
	"testing"

	"lpa/internal/protocol"
)

type fakeRun struct {
	calls []string
	out   map[string]string
	fail  map[string]string
}

func (f *fakeRun) Run(_ context.Context, tool string, args ...string) (int, string, error) {
	call := tool + " " + strings.Join(args, " ")
	f.calls = append(f.calls, call)
	for p, out := range f.fail {
		if strings.HasPrefix(call, p) {
			return 1, out, nil
		}
	}
	for p, out := range f.out {
		if strings.HasPrefix(call, p) {
			return 0, out, nil
		}
	}
	return 0, "", nil
}

const show = `Id=httpd.service
LoadState=loaded
ActiveState=active
SubState=running
UnitFileState=enabled
ActiveEnterTimestamp=Mon 2026-11-23 10:00:00 UTC
MemoryCurrent=52428800
NRestarts=0

Id=nginx.service
LoadState=loaded
ActiveState=failed
SubState=failed
UnitFileState=enabled
ActiveEnterTimestamp=
MemoryCurrent=[not set]
NRestarts=2

Id=pdns.service
LoadState=not-found
ActiveState=inactive
SubState=dead
UnitFileState=
ActiveEnterTimestamp=
MemoryCurrent=[not set]
NRestarts=0

Id=mariadb.service
LoadState=loaded
ActiveState=active
SubState=running
UnitFileState=disabled
ActiveEnterTimestamp=Tue 2026-11-24 08:30:00 UTC
MemoryCurrent=18446744073709551615
NRestarts=0
`

func TestListIsWhatIsInstalledAndHowItIsDoing(t *testing.T) {
	run := &fakeRun{out: map[string]string{"systemctl show": show}}
	m := &Manager{Run: run}
	list, err := m.List(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	by := map[string]Service{}
	for _, s := range list {
		by[s.Unit] = s
	}
	if len(list) != 3 {
		t.Fatalf("only installed units, in the catalogue's order: %+v", list)
	}
	if _, ok := by["pdns.service"]; ok {
		t.Fatal("a unit systemd does not know is not listed")
	}
	h := by["httpd.service"]
	if !h.Running() || !h.Enabled || h.MemoryBytes != 52428800 || h.Since != "2026-11-23T10:00:00Z" || h.Group != "web" {
		t.Fatalf("%+v", h)
	}
	n := by["nginx.service"]
	if n.Running() || n.Active != "failed" || n.Restarts != 2 || n.Since != "" || n.MemoryBytes != 0 {
		t.Fatalf("%+v", n)
	}
	if by["mariadb.service"].MemoryBytes != 0 || by["mariadb.service"].Enabled {
		t.Fatalf("an unset counter is nothing: %+v", by["mariadb.service"])
	}
	if !strings.Contains(run.calls[0], "-- lpa.service lpanel-authd.socket") || !strings.Contains(run.calls[0], "php83-php-fpm.service") {
		t.Fatalf("%s", run.calls[0])
	}
}

func TestActionsAreHeldToTheUnitsRules(t *testing.T) {
	run := &fakeRun{out: map[string]string{"systemctl show": show}}
	m := &Manager{Run: run}
	ctx := context.Background()

	s, err := m.Act(ctx, ActArgs{Unit: "httpd.service", Action: "restart"})
	if err != nil || s.Unit != "httpd.service" || !s.Running() {
		t.Fatalf("%v %+v", err, s)
	}
	if run.calls[0] != "systemctl restart -- httpd.service" {
		t.Fatalf("%v", run.calls)
	}

	for name, a := range map[string]ActArgs{
		"the agent":                  {"lpa.service", "restart"},
		"the panel's web server":     {"lpanel-nginx.service", "stop"},
		"the panel's php":            {"lpanel-php-fpm.service", "disable"},
		"the database being stopped": {"mariadb.service", "stop"},
		"ssh being stopped":          {"sshd.service", "stop"},
		"ssh being disabled":         {"sshd.service", "disable"},
		"the firewall being stopped": {"firewalld.service", "stop"},
	} {
		run.calls = nil
		_, err := m.Act(ctx, a)
		var pe *protocol.Error
		if err == nil || !asErr(err, &pe) || pe.Code != CodeRefused {
			t.Errorf("%s: %v", name, err)
		}
		if len(run.calls) != 0 {
			t.Errorf("%s: systemd was asked: %v", name, run.calls)
		}
	}

	for name, a := range map[string]ActArgs{
		"a unit that is not in the list": {"sshd.socket", "stop"},
		"a unit written as a flag":       {"--now", "stop"},
		"an action that is not one":      {"httpd.service", "mask"},
		"an action smuggled in":          {"httpd.service", "restart --force"},
		"a path":                         {"/etc/systemd/system/x.service", "start"},
	} {
		_, err := m.Act(ctx, a)
		var pe *protocol.Error
		if err == nil || !asErr(err, &pe) || pe.Code != protocol.CodeBadArgs {
			t.Errorf("%s: %v", name, err)
		}
	}
}

func TestASystemdFailureIsSaidPlainly(t *testing.T) {
	run := &fakeRun{out: map[string]string{"systemctl show": show}, fail: map[string]string{"systemctl restart": "Job for nginx.service failed because the control process exited with error code."}}
	_, err := (&Manager{Run: run}).Act(context.Background(), ActArgs{Unit: "nginx.service", Action: "restart"})
	var pe *protocol.Error
	if err == nil || !asErr(err, &pe) || pe.Code != CodeFailed || !strings.Contains(pe.Message, "control process exited") {
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
