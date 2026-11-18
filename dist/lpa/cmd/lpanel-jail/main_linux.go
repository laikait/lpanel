package main

import (
	"fmt"
	"os"
	"os/user"
	"strconv"
	"syscall"
)

func main() {
	if err := run(); err != nil {
		fmt.Fprintln(os.Stderr, "lpanel:", err)
		os.Exit(1)
	}
}

func run() error {
	req, err := Parse(os.Args)
	if err != nil {
		return err
	}
	inner, err := Inner(req)
	if err != nil {
		return err
	}
	acct, err := current()
	if err != nil {
		return err
	}
	syscall.Umask(0o022)
	// The home's tmp/ is the jail's /tmp; the web server makes it, but an
	// account that has had no site yet may not have one.
	if err := os.Mkdir(acct.Home+"/tmp", 0o700); err != nil && !os.IsExist(err) {
		return fmt.Errorf("the home's tmp folder: %w", err)
	}
	passwd, err := dataFD(Passwd(acct))
	if err != nil {
		return err
	}
	group, err := dataFD(Group(acct))
	if err != nil {
		return err
	}
	exists := func(p string) bool { _, err := os.Stat(p); return err == nil }
	args := Args(acct, inner, passwd, group, exists)
	if err := syscall.Exec(Bwrap, args, Env(acct, os.Environ())); err != nil {
		return fmt.Errorf("the sandbox could not start (%v); ask the server's administrator", err)
	}
	return nil
}

// current is the account the shell runs as; never root.
func current() (Account, error) {
	uid := os.Getuid()
	if uid == 0 || os.Geteuid() == 0 {
		return Account{}, fmt.Errorf("not for root")
	}
	u, err := user.LookupId(strconv.Itoa(uid))
	if err != nil {
		return Account{}, err
	}
	gid, err := strconv.Atoi(u.Gid)
	if err != nil {
		return Account{}, err
	}
	if u.HomeDir == "" || u.HomeDir == "/" {
		return Account{}, fmt.Errorf("the account has no home")
	}
	return Account{Name: u.Username, UID: uid, GID: gid, Home: u.HomeDir}, nil
}

// dataFD is a descriptor bubblewrap can read data from (--file): a pipe
// whose read end survives exec.
func dataFD(data string) (int, error) {
	var p [2]int
	if err := syscall.Pipe(p[:]); err != nil {
		return 0, err
	}
	if _, err := syscall.Write(p[1], []byte(data)); err != nil {
		return 0, err
	}
	syscall.Close(p[1])
	return p[0], nil
}
