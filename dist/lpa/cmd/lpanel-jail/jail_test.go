package main

import (
	"errors"
	"slices"
	"strings"
	"testing"
)

func TestParseAndInner(t *testing.T) {
	cases := []struct {
		argv  []string
		inner []string
		err   error
	}{
		{[]string{"-jail-shell"}, []string{Shell, "-l"}, nil},
		{[]string{"/usr/libexec/lpanel/jail-shell", "-c", "ls -la"}, []string{Shell, "-c", "ls -la"}, nil},
		{[]string{"jail-shell", "-c", SFTPServer}, []string{Shell, "-c", SFTPServer}, nil},
		{[]string{"/usr/libexec/lpanel/jail-sftp", "-c", SFTPServer}, []string{SFTPServer}, nil},
		{[]string{"jail-sftp", "-c", SFTPServer + " -l INFO"}, []string{SFTPServer}, nil},
		{[]string{"-jail-sftp"}, nil, ErrSFTPOnly},
		{[]string{"jail-sftp", "-c", "bash"}, nil, ErrSFTPOnly},
		{[]string{"jail-sftp", "-c", SFTPServer + "; bash"}, nil, ErrSFTPOnly},
		{[]string{"jail-sftp", "-c", "/usr/libexec/openssh/sftp-server-evil"}, nil, ErrSFTPOnly},
	}
	for _, c := range cases {
		r, err := Parse(c.argv)
		if err != nil {
			t.Fatalf("%v: %v", c.argv, err)
		}
		inner, err := Inner(r)
		if !errors.Is(err, c.err) || !slices.Equal(inner, c.inner) {
			t.Errorf("%v: got %v, %v; want %v, %v", c.argv, inner, err, c.inner, c.err)
		}
	}
	for _, bad := range [][]string{{}, {"jail-shell", "-x"}, {"jail-shell", "-c"}, {"jail-shell", "-c", "a", "b"}} {
		if _, err := Parse(bad); err == nil {
			t.Errorf("%v: accepted", bad)
		}
	}
}

func TestArgs(t *testing.T) {
	a := Account{Name: "shop7", UID: 1001, GID: 1001, Home: "/home/shop7"}
	have := map[string]bool{"/etc/resolv.conf": true, "/opt/remi": true, "/var/lib/mysql/mysql.sock": true}
	args := Args(a, []string{Shell, "-l"}, 5, 6, func(p string) bool { return have[p] })
	s := strings.Join(args, " ")
	for _, want := range []string{
		"--unshare-pid", "--ro-bind /usr /usr", "--ro-bind /opt/remi /opt/remi", "--ro-bind /etc/resolv.conf /etc/resolv.conf",
		"--file 5 /etc/passwd", "--file 6 /etc/group", "--proc /proc",
		"--bind /home/shop7 /home/shop7", "--bind /home/shop7/tmp /tmp", "--remount-ro /", "--chdir /home/shop7",
		"--bind /var/lib/mysql/mysql.sock /var/lib/mysql/mysql.sock", "-- /bin/bash -l",
	} {
		if !strings.Contains(s, want) {
			t.Errorf("missing %q in %s", want, s)
		}
	}
	// Nothing of the host's that was not asked for: no /home, no /etc as a
	// whole, nothing missing bound.
	for _, never := range []string{"--bind /home /home", "--ro-bind /etc /etc", "/etc/hosts", "--bind / "} {
		if strings.Contains(s, never) {
			t.Errorf("%q in %s", never, s)
		}
	}
	if args[len(args)-1] != "-l" || args[len(args)-3] != "--" {
		t.Errorf("the inner command is not last: %v", args)
	}
}

func TestPasswdAndEnv(t *testing.T) {
	a := Account{Name: "shop7", UID: 1001, GID: 1002, Home: "/home/shop7"}
	p := Passwd(a)
	if !strings.Contains(p, "shop7:x:1001:1002::/home/shop7:/bin/bash\n") || strings.Count(p, "\n") != 3 {
		t.Errorf("passwd: %q", p)
	}
	if g := Group(a); !strings.Contains(g, "shop7:x:1002:\n") {
		t.Errorf("group: %q", g)
	}
	env := Env(a, []string{"TERM=xterm", "LC_ALL=C", "LD_PRELOAD=/tmp/x.so", "SSH_AUTH_SOCK=/tmp/a", "BASH_ENV=/tmp/x", "PATH=/evil"})
	s := strings.Join(env, "\n")
	for _, want := range []string{"HOME=/home/shop7", "USER=shop7", "TERM=xterm", "LC_ALL=C", "PATH=/usr/local/bin:/usr/bin"} {
		if !strings.Contains(s, want) {
			t.Errorf("env lacks %s", want)
		}
	}
	for _, never := range []string{"LD_PRELOAD", "SSH_AUTH_SOCK", "BASH_ENV", "/evil"} {
		if strings.Contains(s, never) {
			t.Errorf("env has %s", never)
		}
	}
}
