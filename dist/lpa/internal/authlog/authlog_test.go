package authlog

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func scanner(t *testing.T) (*Scanner, string) {
	t.Helper()
	dir := t.TempDir()
	d := Default()
	for i := range d.Sources {
		d.Sources[i].File = filepath.Join(dir, d.Sources[i].Name+".log")
	}
	return d, dir
}

func appendTo(t *testing.T, path, text string) {
	t.Helper()
	f, err := os.OpenFile(path, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o600)
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	if _, err := f.WriteString(text); err != nil {
		t.Fatal(err)
	}
}

func counts(r Result) map[string]int {
	m := map[string]int{}
	for _, f := range r.Failures {
		m[f.Service+" "+f.IP] += f.Count
	}
	return m
}

func TestFirstScanStartsAtTheEndOfTheLog(t *testing.T) {
	s, dir := scanner(t)
	appendTo(t, filepath.Join(dir, "ssh.log"), "Nov 1 sshd[1]: Failed password for root from 198.51.100.9 port 22 ssh2\n")
	r, err := s.Scan(context.Background(), Args{})
	if err != nil || len(r.Failures) != 0 {
		t.Fatalf("history must not count: %v %+v", err, r)
	}
	if r.Cursors["ssh"].Offset == 0 {
		t.Fatal("the cursor must be at the end")
	}
	if len(r.Missing) != 3 {
		t.Fatalf("the other logs are missing: %v", r.Missing)
	}
}

func TestEachServiceIsRead(t *testing.T) {
	s, dir := scanner(t)
	r, _ := s.Scan(context.Background(), Args{})
	for _, n := range []string{"ssh", "ftp", "mail", "smtp"} {
		appendTo(t, filepath.Join(dir, n+".log"), "")
		// the cursor of an empty file: at its start
	}
	r, err := s.Scan(context.Background(), Args{Cursors: r.Cursors})
	if err != nil {
		t.Fatal(err)
	}
	appendTo(t, filepath.Join(dir, "ssh.log"), strings.Join([]string{
		"Nov 23 10:00:01 h sshd[10]: Failed password for root from 198.51.100.9 port 4000 ssh2",
		"Nov 23 10:00:02 h sshd[10]: Failed password for invalid user bob from 198.51.100.9 port 4001 ssh2",
		"Nov 23 10:00:03 h sshd[11]: Failed publickey for alice from 2001:db8::5 port 4002 ssh2",
		"Nov 23 10:00:04 h sshd[12]: Accepted password for alice from 198.51.100.77 port 4003 ssh2",
		"Nov 23 10:00:05 h sshd[12]: Failed password for root from 127.0.0.1 port 4003 ssh2",
		"",
	}, "\n"))
	appendTo(t, filepath.Join(dir, "ftp.log"), "Nov 23 10:01:00 h pure-ftpd: (?@203.0.113.4) [WARNING] Authentication failed for user [shop1]\nNov 23 10:01:00 h pure-ftpd: (shop1@203.0.113.4) [INFO] shop1 is now logged in\n")
	appendTo(t, filepath.Join(dir, "mail.log"), "Nov 23 10:02:00 h dovecot[1]: imap-login: Disconnected (auth failed, 3 attempts in 4 secs): user=<a@b.test>, method=PLAIN, rip=203.0.113.50, lip=10.0.0.1, TLS\nNov 23 10:02:01 h dovecot[1]: pop3-login: Disconnected: Aborted login (auth failed, 1 attempts in 2 secs): user=<>, rip=203.0.113.51, lip=10.0.0.1\nNov 23 10:02:02 h dovecot[1]: imap-login: Login: user=<ok@b.test>, rip=203.0.113.52\n")
	appendTo(t, filepath.Join(dir, "smtp.log"), "2026-11-23 10:03:00 login authenticator failed for (win) [203.0.113.60]:51234 I=[10.0.0.1]:587: 535 Incorrect authentication data (set_id=x)\n")

	r, err = s.Scan(context.Background(), Args{Cursors: r.Cursors})
	if err != nil {
		t.Fatal(err)
	}
	got := counts(r)
	want := map[string]int{
		"ssh 198.51.100.9": 2, "ssh 2001:db8::5": 1, "ftp 203.0.113.4": 1,
		"mail 203.0.113.50": 3, "mail 203.0.113.51": 1, "smtp 203.0.113.60": 1,
	}
	if len(got) != len(want) {
		t.Fatalf("got %v want %v", got, want)
	}
	for k, v := range want {
		if got[k] != v {
			t.Fatalf("%s: got %d want %d (%v)", k, got[k], v, got)
		}
	}

	// Nothing new: nothing reported, the cursor holds.
	again, _ := s.Scan(context.Background(), Args{Cursors: r.Cursors})
	if len(again.Failures) != 0 {
		t.Fatalf("lines are counted once: %+v", again.Failures)
	}
}

func TestAReplacedLogIsReadFromItsTop(t *testing.T) {
	s, dir := scanner(t)
	p := filepath.Join(dir, "ssh.log")
	appendTo(t, p, "Nov 1 h sshd[1]: Failed password for root from 198.51.100.1 port 1 ssh2\n")
	r, _ := s.Scan(context.Background(), Args{})
	if err := os.WriteFile(p, []byte("Nov 2 h sshd[2]: Failed password for root from 198.51.100.2 port 1 ssh2\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	r, err := s.Scan(context.Background(), Args{Cursors: r.Cursors})
	if err != nil || counts(r)["ssh 198.51.100.2"] != 1 {
		t.Fatalf("%v %+v", err, r)
	}
}

func TestALineStillBeingWrittenIsLeftForNextTime(t *testing.T) {
	s, dir := scanner(t)
	p := filepath.Join(dir, "ssh.log")
	appendTo(t, p, "Nov 1 h sshd[1]: Failed password for root from 198.51.100.1 port 1 ssh2\n")
	r, _ := s.Scan(context.Background(), Args{})
	appendTo(t, p, "Nov 2 h sshd[2]: Failed password for root from 198.51.100.2 po")
	r, _ = s.Scan(context.Background(), Args{Cursors: r.Cursors})
	if len(r.Failures) != 0 {
		t.Fatalf("a half line counts for nothing: %+v", r.Failures)
	}
	appendTo(t, p, "rt 1 ssh2\n")
	r, _ = s.Scan(context.Background(), Args{Cursors: r.Cursors})
	if counts(r)["ssh 198.51.100.2"] != 1 {
		t.Fatalf("the whole line counts once: %+v", r.Failures)
	}
}
