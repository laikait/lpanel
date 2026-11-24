package cron

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"lpa/internal/protocol"
)

func setup(t *testing.T) (*Manager, string) {
	t.Helper()
	dir := filepath.Join(t.TempDir(), "cron.d")
	m := &Manager{
		Dir: dir,
		Owner: func(name string) (int, int, string, error) {
			if name != "shop1" {
				return 0, 0, "", protocol.Fail("not_ours", name+" was not made by LPanel")
			}
			return 1001, 1001, "/home/shop1", nil
		},
		Chown: func(string, int, int) error { return nil },
	}
	return m, dir
}

func code(err error) string {
	if pe, ok := err.(*protocol.Error); ok {
		return pe.Code
	}
	if err != nil {
		return "error: " + err.Error()
	}
	return ""
}

func TestJobsAreWrittenAsTheAccountsOwn(t *testing.T) {
	m, dir := setup(t)
	r, err := m.Apply(context.Background(), Args{Username: "shop1", Jobs: []Job{
		{Schedule: "*/15  *  * * *", Command: "php /home/shop1/public_html/cron.php"},
		{Schedule: "30 3 * * 1-5", Command: "date +%Y-%m-%d >> /home/shop1/logs/d.log"},
		{Schedule: "@daily", Command: "/home/shop1/bin/backup"},
	}})
	if err != nil || r.Jobs != 3 || !r.Changed {
		t.Fatalf("%v %+v", err, r)
	}
	b, _ := os.ReadFile(filepath.Join(dir, "lpanel-shop1"))
	text := string(b)
	for _, want := range []string{
		"HOME=/home/shop1\n", "MAILTO=\"\"\n", "TMPDIR=/home/shop1/tmp\n",
		"*/15 * * * * shop1 php /home/shop1/public_html/cron.php\n",
		"30 3 * * 1-5 shop1 date +" + `\%Y-\%m-\%d` + " >> /home/shop1/logs/d.log\n",
		"@daily shop1 /home/shop1/bin/backup\n",
	} {
		if !strings.Contains(text, want) {
			t.Fatalf("missing %q:\n%s", want, text)
		}
	}
	st, _ := os.Stat(filepath.Join(dir, "lpanel-shop1"))
	if st.Mode().Perm() != 0o644 {
		t.Fatalf("mode %v", st.Mode())
	}

	// The same again: nothing changes.
	r, err = m.Apply(context.Background(), Args{Username: "shop1", Jobs: []Job{
		{Schedule: "*/15 * * * *", Command: "php /home/shop1/public_html/cron.php"},
		{Schedule: "30 3 * * 1-5", Command: "date +%Y-%m-%d >> /home/shop1/logs/d.log"},
		{Schedule: "@daily", Command: "/home/shop1/bin/backup"},
	}})
	if err != nil || r.Changed {
		t.Fatalf("%v %+v", err, r)
	}
}

func TestNoJobsOrASuspendedAccountRemovesTheFile(t *testing.T) {
	m, dir := setup(t)
	jobs := []Job{{Schedule: "0 * * * *", Command: "true"}}
	m.Apply(context.Background(), Args{Username: "shop1", Jobs: jobs})
	file := filepath.Join(dir, "lpanel-shop1")

	if r, err := m.Apply(context.Background(), Args{Username: "shop1", Suspended: true, Jobs: jobs}); err != nil || !r.Changed {
		t.Fatalf("%v %+v", err, r)
	}
	if _, err := os.Stat(file); err == nil {
		t.Fatal("a suspended account runs nothing")
	}
	m.Apply(context.Background(), Args{Username: "shop1", Jobs: jobs})
	if _, err := m.Apply(context.Background(), Args{Username: "shop1"}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(file); err == nil {
		t.Fatal("no jobs, no file")
	}
	// Removing the file of an account that is gone needs no user.
	if _, err := m.Apply(context.Background(), Args{Username: "gone5"}); err != nil {
		t.Fatalf("%v", err)
	}
}

func TestWhatCronWouldMisreadIsRefused(t *testing.T) {
	m, dir := setup(t)
	var many []Job
	for i := 0; i < MaxJobs+1; i++ {
		many = append(many, Job{Schedule: "0 * * * *", Command: "true"})
	}
	bad := map[string]Args{
		"a name with a dot":           {Username: "shop.1"},
		"an unknown account":          {Username: "nobody1", Jobs: []Job{{Schedule: "0 * * * *", Command: "true"}}},
		"a newline in the command":    {Username: "shop1", Jobs: []Job{{Schedule: "0 * * * *", Command: "true\n* * * * * root rm -rf /"}}},
		"a carriage return":           {Username: "shop1", Jobs: []Job{{Schedule: "0 * * * *", Command: "true\rfalse"}}},
		"an empty command":            {Username: "shop1", Jobs: []Job{{Schedule: "0 * * * *", Command: ""}}},
		"a command with spaces round": {Username: "shop1", Jobs: []Job{{Schedule: "0 * * * *", Command: " true"}}},
		"a huge command":              {Username: "shop1", Jobs: []Job{{Schedule: "0 * * * *", Command: strings.Repeat("a", MaxCommand+1)}}},
		"every minute":                {Username: "shop1", Jobs: []Job{{Schedule: "* * * * *", Command: "true"}}},
		"every two minutes":           {Username: "shop1", Jobs: []Job{{Schedule: "*/2 * * * *", Command: "true"}}},
		"minutes close together":      {Username: "shop1", Jobs: []Job{{Schedule: "0,3 * * * *", Command: "true"}}},
		"close across the hour":       {Username: "shop1", Jobs: []Job{{Schedule: "58,1 * * * *", Command: "true"}}},
		"four fields":                 {Username: "shop1", Jobs: []Job{{Schedule: "0 * * *", Command: "true"}}},
		"six fields":                  {Username: "shop1", Jobs: []Job{{Schedule: "0 * * * * *", Command: "true"}}},
		"a word as a field":           {Username: "shop1", Jobs: []Job{{Schedule: "0 * * * mon", Command: "true"}}},
		"an hour of 24":               {Username: "shop1", Jobs: []Job{{Schedule: "0 24 * * *", Command: "true"}}},
		"a month of 0":                {Username: "shop1", Jobs: []Job{{Schedule: "0 0 1 0 *", Command: "true"}}},
		"a backwards range":           {Username: "shop1", Jobs: []Job{{Schedule: "10-5 * * * *", Command: "true"}}},
		"@reboot":                     {Username: "shop1", Jobs: []Job{{Schedule: "@reboot", Command: "true"}}},
		"a step of zero":              {Username: "shop1", Jobs: []Job{{Schedule: "*/0 * * * *", Command: "true"}}},
		"a schedule that is a flag":   {Username: "shop1", Jobs: []Job{{Schedule: "-1 * * * *", Command: "true"}}},
		"too many jobs":               {Username: "shop1", Jobs: many},
	}
	for name, a := range bad {
		_, err := m.Apply(context.Background(), a)
		if c := code(err); c != protocol.CodeBadArgs && !(name == "an unknown account" && c == "not_ours") {
			t.Errorf("%s: %q", name, c)
		}
	}
	if entries, _ := os.ReadDir(dir); len(entries) != 0 {
		t.Fatalf("nothing may be written for a refused list: %v", entries)
	}

	ok := []string{"*/5 * * * *", "0 * * * *", "0,30 * * * *", "5-55/10 * * * *", "0 3 * * 7", "0,2 9 * * *", "@hourly", "@weekly", "15 4 1,15 * *"}
	for _, s := range ok {
		if _, err := m.Apply(context.Background(), Args{Username: "shop1", Jobs: []Job{{Schedule: s, Command: "true"}}}); err != nil {
			t.Errorf("%q should be fine: %v", s, err)
		}
	}
}
