// lpanel-jail is the login shell of an LPanel account with SSH or SFTP
// access. It never runs anything outside the account's sandbox: sshd starts
// it (as the account, for a terminal, a command or SFTP) and it replaces
// itself with bubblewrap, which builds a world of the account's own:
//
//   - its home, read-write, at its usual path; no other home is there
//   - /usr and Remi's PHP read-only, a short list of /etc read-only, and
//     /etc/passwd and /etc/group naming only root, nobody and the account
//   - its own process list (a new PID namespace and /proc) and IPC
//   - /tmp is the home's tmp/, so it counts against the quota
//   - no privilege gain: setuid programs do not work inside (no_new_privs)
//
// Installed twice: as jail-shell (a shell, commands and SFTP) and jail-sftp
// (SFTP only); the name it is started under decides. If bubblewrap cannot
// start, the login fails; it never falls back to an unjailed shell.
package main

import (
	"errors"
	"fmt"
	"path/filepath"
	"strings"
)

// Paths inside the jail and on the host.
const (
	Bwrap      = "/usr/bin/bwrap"
	SFTPServer = "/usr/libexec/openssh/sftp-server"
	Shell      = "/bin/bash"
)

// etcFiles are what programs need from /etc: name resolution, time zone,
// the dynamic linker, certificates, the shell's profile, the MariaDB client
// and Remi's PHP settings. Each is bound read-only when it exists.
var etcFiles = []string{
	"resolv.conf", "hosts", "host.conf", "nsswitch.conf", "gai.conf", "localtime",
	"ld.so.cache", "ld.so.conf", "ld.so.conf.d", "pki", "ssl", "crypto-policies", "alternatives",
	"profile", "profile.d", "bashrc", "inputrc", "DIR_COLORS", "DIR_COLORS.256color", "DIR_COLORS.lightbgcolor",
	"services", "protocols", "mime.types", "os-release", "redhat-release", "system-release", "almalinux-release",
	"rocky-release", "locale.conf", "shells", "my.cnf", "my.cnf.d", "opt/remi", "php.ini", "php.d",
	"gitconfig", "vimrc", "virc", "nanorc", "wgetrc", "npmrc", "terminfo",
	// profile.d scripts read these (environment-modules through a link in /usr).
	"environment-modules", "modulefiles", "bash_completion.d",
}

// Account is who the jail is for.
type Account struct {
	Name     string
	UID, GID int
	Home     string
}

// Request is what sshd asked for.
type Request struct {
	SFTPOnly bool   // started as jail-sftp
	Command  string // -c's argument; "" for a terminal
	HasCmd   bool
}

// ErrSFTPOnly is the answer to a terminal or command on an SFTP-only account.
var ErrSFTPOnly = errors.New("this account has SFTP access only; use an SFTP client")

// Parse reads the shell's own name and arguments as sshd gives them: a
// login shell ("-jail-shell") with no arguments, or "-c command".
func Parse(argv []string) (Request, error) {
	if len(argv) == 0 {
		return Request{}, errors.New("no program name")
	}
	name := strings.TrimPrefix(filepath.Base(argv[0]), "-")
	r := Request{SFTPOnly: name == "jail-sftp"}
	switch {
	case len(argv) == 1:
	case len(argv) == 3 && argv[1] == "-c":
		r.Command, r.HasCmd = argv[2], true
	default:
		return Request{}, errors.New("usage: a login shell, or -c command")
	}
	return r, nil
}

// isSFTP says whether a command is sshd starting the SFTP subsystem.
func isSFTP(cmd string) bool {
	cmd = strings.TrimSpace(cmd)
	return cmd == SFTPServer || strings.HasPrefix(cmd, SFTPServer+" ")
}

// Inner is the program run inside the jail.
func Inner(r Request) ([]string, error) {
	switch {
	case r.SFTPOnly && r.HasCmd && isSFTP(r.Command):
		return []string{SFTPServer}, nil
	case r.SFTPOnly:
		return nil, ErrSFTPOnly
	case r.HasCmd:
		return []string{Shell, "-c", r.Command}, nil
	default:
		return []string{Shell, "-l"}, nil
	}
}

// Passwd and Group are the jail's /etc/passwd and /etc/group.
func Passwd(a Account) string {
	return fmt.Sprintf("root:x:0:0:root:/root:/sbin/nologin\n%s:x:%d:%d::%s:%s\nnobody:x:65534:65534:Kernel Overflow User:/:/sbin/nologin\n",
		a.Name, a.UID, a.GID, a.Home, Shell)
}

func Group(a Account) string {
	return fmt.Sprintf("root:x:0:\n%s:x:%d:\nnobody:x:65534:\n", a.Name, a.GID)
}

// Args are bubblewrap's arguments. passwdFD and groupFD are open
// descriptors holding Passwd and Group; exists says whether a host path is
// there (only paths that exist are bound).
func Args(a Account, inner []string, passwdFD, groupFD int, exists func(string) bool) []string {
	args := []string{"bwrap",
		"--die-with-parent",
		"--unshare-pid", "--unshare-ipc", "--unshare-cgroup-try",
		"--ro-bind", "/usr", "/usr",
		"--symlink", "usr/bin", "/bin",
		"--symlink", "usr/sbin", "/sbin",
		"--symlink", "usr/lib", "/lib",
		"--symlink", "usr/lib64", "/lib64",
	}
	if exists("/opt/remi") {
		args = append(args, "--ro-bind", "/opt/remi", "/opt/remi")
	}
	for _, f := range etcFiles {
		p := "/etc/" + f
		if exists(p) {
			args = append(args, "--ro-bind", p, p)
		}
	}
	args = append(args,
		"--file", fmt.Sprint(passwdFD), "/etc/passwd",
		"--file", fmt.Sprint(groupFD), "/etc/group",
		"--proc", "/proc",
		"--dev", "/dev",
		"--dir", "/run",
		"--dir", "/var",
		"--symlink", "../tmp", "/var/tmp",
	)
	// The local MariaDB server, for the mysql client and PHP scripts.
	if exists("/var/lib/mysql/mysql.sock") {
		args = append(args, "--bind", "/var/lib/mysql/mysql.sock", "/var/lib/mysql/mysql.sock")
	}
	args = append(args,
		"--bind", a.Home, a.Home,
		"--bind", a.Home+"/tmp", "/tmp",
		// The root built above may not be written to.
		"--remount-ro", "/",
		"--chdir", a.Home,
		"--",
	)
	return append(args, inner...)
}

// Env is the environment inside: the account's names, a plain PATH, and
// what sshd passes on about the terminal, the language and the connection.
func Env(a Account, host []string) []string {
	env := []string{
		"HOME=" + a.Home, "USER=" + a.Name, "LOGNAME=" + a.Name, "SHELL=" + Shell,
		"PATH=/usr/local/bin:/usr/bin",
		// environment-modules (on by default on version 8) starts a pager from
		// the login profile, which waits on the terminal for ever.
		"MODULES_PAGER=cat",
	}
	for _, kv := range host {
		k, _, _ := strings.Cut(kv, "=")
		switch {
		case k == "TERM", k == "LANG", k == "TZ", k == "SSH_CONNECTION", k == "SSH_CLIENT", k == "SSH_TTY",
			strings.HasPrefix(k, "LC_"):
			env = append(env, kv)
		}
	}
	return env
}
