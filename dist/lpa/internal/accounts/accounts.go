// Package accounts makes, changes and removes the Linux side of a hosting
// account: the user and its group, the home folder, the disk quota and the
// login shell.
//
// It only ever touches accounts it made itself. Every one carries Marker as
// its comment, has a uid of at least MinUID and lives at HomesBase/<name>.
// A request naming anything else (root, a system user, a person's own login)
// is refused with CodeNotOurs, whatever the panel says. A compromised panel
// therefore cannot use these operations to lock out or delete the server's
// own users.
package accounts

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"regexp"

	"lpa/internal/protocol"
	"lpa/internal/quota"
	"lpa/internal/sysexec"
)

// Marker is the passwd comment of every account the agent made.
const Marker = "lpanel account"

// MinUID is the lowest uid a hosting account may have: below it are the
// system's own users.
const MinUID = 1000

// Error codes particular to accounts. The panel shows each one differently.
const (
	CodeExists    = "exists"          // the user, group or home is already there
	CodeNoAccount = "no_such_account" // there is no user of that name
	CodeNotOurs   = "not_ours"        // that user was not made by LPanel
	CodeQuota     = "quota"           // the quota could not be set or read
)

// nameRule is a hosting account's username: a lowercase letter, then
// lowercase letters or digits, 2 to 16 characters. Short and plain because it
// becomes a database prefix and part of paths and mail addresses later.
var nameRule = regexp.MustCompile(`^[a-z][a-z0-9]{1,15}$`)

// Shells a hosting account may have. "none" until jailed SSH arrives (M7).
var Shells = map[string]string{
	"none": "/sbin/nologin",
	"bash": "/bin/bash",
}

// Manager carries out account changes.
type Manager struct {
	HomesBase string
	Files     Files
	Run       sysexec.Runner
	Quota     quota.Quotas
	Log       *slog.Logger
	// Chown is os.Lchown when nil; tests replace it.
	Chown func(path string, uid, gid int) error
	// SELinux is true on a host with SELinux on. New homes are then given
	// the policy's labels (public_html becomes web content), because a
	// directory the agent makes itself would only inherit its parent's.
	SELinux bool
	// BeforeRemove runs before a terminated account's user is deleted, while
	// it still exists: the web server's pools for it must go first, or
	// PHP-FPM would refuse to reload with a pool for a missing user.
	BeforeRemove func(ctx context.Context, username string) error
}

// Account is what the operations answer about an account.
type Account struct {
	Username  string       `json:"username"`
	UID       int          `json:"uid"`
	GID       int          `json:"gid"`
	Home      string       `json:"home"`
	Shell     string       `json:"shell"`
	Suspended bool         `json:"suspended"`
	Usage     *quota.Usage `json:"usage"`
	// UsageProblem says why Usage is missing.
	UsageProblem string `json:"usage_problem,omitempty"`
}

// CreateArgs are account.create's arguments.
type CreateArgs struct {
	Username string       `json:"username"`
	Shell    string       `json:"shell"`
	Quota    quota.Limits `json:"quota"`
}

// ModifyArgs are account.modify's arguments; a missing field is left as it is.
type ModifyArgs struct {
	Username string        `json:"username"`
	Shell    *string       `json:"shell"`
	Quota    *quota.Limits `json:"quota"`
}

// NameArgs name one account.
type NameArgs struct {
	Username string `json:"username"`
}

// InfoArgs are account.info's arguments: the accounts to describe, or every
// LPanel account when empty.
type InfoArgs struct {
	Usernames []string `json:"usernames"`
}

// Info is account.info's answer.
type Info struct {
	Accounts []Account `json:"accounts"`
	// Missing lists asked-for names that are not LPanel accounts.
	Missing []string `json:"missing"`
}

func (m *Manager) home(name string) string { return filepath.Join(m.HomesBase, name) }

func badArgs(format string, a ...any) error {
	return protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf(format, a...))
}

func checkName(name string) error {
	if !nameRule.MatchString(name) {
		return badArgs("username %q is not 2–16 lowercase letters and digits starting with a letter", name)
	}
	return nil
}

func shellPath(shell string) (string, error) {
	path, ok := Shells[shell]
	if !ok {
		return "", badArgs("shell must be \"none\" or \"bash\", not %q", shell)
	}
	return path, nil
}

// Owner describes an account the agent made, for the other subsystems that
// act for it (web, later mail and FTP); anything else is refused.
func (m *Manager) Owner(name string) (uid, gid int, home string, err error) {
	e, err := m.owned(name)
	if err != nil {
		return 0, 0, "", err
	}
	return e.UID, e.GID, m.home(name), nil
}

// owned finds an account the agent made, refusing anything else.
func (m *Manager) owned(name string) (*Entry, error) {
	if err := checkName(name); err != nil {
		return nil, err
	}
	e, err := m.Files.lookup(name)
	if err != nil {
		return nil, err
	}
	if e == nil {
		return nil, protocol.Fail(CodeNoAccount, "there is no user named "+name)
	}
	if e.GECOS != Marker || e.UID < MinUID || filepath.Clean(e.Home) != m.home(name) {
		return nil, protocol.Fail(CodeNotOurs, name+" was not made by LPanel; the agent does not change it")
	}
	return e, nil
}

// Create makes the user, its group, its home (0711, with public_html) and
// its quota. If any step fails, the steps already taken are undone.
func (m *Manager) Create(ctx context.Context, a CreateArgs) (Account, error) {
	if err := checkName(a.Username); err != nil {
		return Account{}, err
	}
	shell, err := shellPath(a.Shell)
	if err != nil {
		return Account{}, err
	}
	if e, err := m.Files.lookup(a.Username); err != nil {
		return Account{}, err
	} else if e != nil {
		return Account{}, protocol.Fail(CodeExists, "a user named "+a.Username+" already exists")
	}
	if exists, err := m.Files.groupExists(a.Username); err != nil {
		return Account{}, err
	} else if exists {
		return Account{}, protocol.Fail(CodeExists, "a group named "+a.Username+" already exists")
	}
	home := m.home(a.Username)
	if _, err := os.Lstat(home); err == nil {
		return Account{}, protocol.Fail(CodeExists, home+" already exists")
	}

	if err := sysexec.Must(ctx, m.Run, "useradd",
		"--create-home", "--home-dir", home, "--shell", shell, "--user-group",
		"--comment", Marker, "--key", "UMASK=0077", a.Username); err != nil {
		return Account{}, err
	}

	acct, err := m.finishCreate(ctx, a, home)
	if err != nil {
		// Context may have run out; undoing must still happen.
		if uerr := sysexec.Must(context.WithoutCancel(ctx), m.Run, "userdel", "--remove", a.Username); uerr != nil {
			m.Log.Error("could not undo a half-made account", "username", a.Username, "err", uerr)
		}
		return Account{}, err
	}
	return acct, nil
}

func (m *Manager) finishCreate(ctx context.Context, a CreateArgs, home string) (Account, error) {
	e, err := m.Files.lookup(a.Username)
	if err != nil {
		return Account{}, err
	}
	if e == nil || e.UID < MinUID {
		return Account{}, errors.New("useradd did not make the user as expected")
	}
	// Others may pass through the home to reach public_html (the web server,
	// from M2) but not list or read it.
	if err := os.Chmod(home, 0o711); err != nil {
		return Account{}, err
	}
	web := filepath.Join(home, "public_html")
	if err := os.Mkdir(web, 0o750); err != nil && !errors.Is(err, os.ErrExist) {
		return Account{}, err
	}
	// The agent runs with umask 0077 (lpa.service); set the mode outright.
	if err := os.Chmod(web, 0o750); err != nil {
		return Account{}, err
	}
	chown := m.Chown
	if chown == nil {
		chown = os.Lchown
	}
	if err := chown(web, e.UID, e.GID); err != nil {
		return Account{}, err
	}
	if m.SELinux {
		if err := sysexec.Must(ctx, m.Run, "restorecon", "-R", home); err != nil {
			return Account{}, err
		}
	}
	if err := m.setQuota(home, e.UID, a.Quota); err != nil {
		return Account{}, err
	}
	return m.describe(*e), nil
}

func (m *Manager) setQuota(home string, uid int, l quota.Limits) error {
	if err := m.Quota.Set(home, uid, l); err != nil {
		return protocol.Fail(CodeQuota, err.Error())
	}
	return nil
}

// Modify changes the shell and/or the quota.
func (m *Manager) Modify(ctx context.Context, a ModifyArgs) (Account, error) {
	e, err := m.owned(a.Username)
	if err != nil {
		return Account{}, err
	}
	if a.Shell != nil {
		shell, err := shellPath(*a.Shell)
		if err != nil {
			return Account{}, err
		}
		if shell != e.Shell {
			if err := sysexec.Must(ctx, m.Run, "usermod", "--shell", shell, a.Username); err != nil {
				return Account{}, err
			}
		}
	}
	if a.Quota != nil {
		if err := m.setQuota(e.Home, e.UID, *a.Quota); err != nil {
			return Account{}, err
		}
	}
	e, err = m.owned(a.Username)
	if err != nil {
		return Account{}, err
	}
	return m.describe(*e), nil
}

// Suspend expires the account, which stops every login (password, SSH key,
// FTP, su) through PAM's account check, and ends its running processes.
// Nothing about the account is changed that Unsuspend would have to remember.
func (m *Manager) Suspend(ctx context.Context, a NameArgs) (Account, error) {
	if _, err := m.owned(a.Username); err != nil {
		return Account{}, err
	}
	if err := sysexec.Must(ctx, m.Run, "usermod", "--expiredate", suspendedExpiry, a.Username); err != nil {
		return Account{}, err
	}
	if err := m.killAll(ctx, a.Username); err != nil {
		return Account{}, err
	}
	e, err := m.owned(a.Username)
	if err != nil {
		return Account{}, err
	}
	return m.describe(*e), nil
}

// Unsuspend removes the expiry Suspend set.
func (m *Manager) Unsuspend(ctx context.Context, a NameArgs) (Account, error) {
	if _, err := m.owned(a.Username); err != nil {
		return Account{}, err
	}
	if err := sysexec.Must(ctx, m.Run, "usermod", "--expiredate", "", a.Username); err != nil {
		return Account{}, err
	}
	e, err := m.owned(a.Username)
	if err != nil {
		return Account{}, err
	}
	return m.describe(*e), nil
}

// TerminateResult is account.terminate's answer.
type TerminateResult struct {
	Username string `json:"username"`
	// Existed is false when there was no such user to remove; the account is
	// gone either way.
	Existed bool `json:"existed"`
}

// Terminate ends the account's processes, removes its quota, the user, its
// group, its home and its mail spool. A name with no user is already gone.
func (m *Manager) Terminate(ctx context.Context, a NameArgs) (TerminateResult, error) {
	e, err := m.owned(a.Username)
	var pe *protocol.Error
	if errors.As(err, &pe) && pe.Code == CodeNoAccount {
		return TerminateResult{Username: a.Username}, nil
	}
	if err != nil {
		return TerminateResult{}, err
	}
	if m.BeforeRemove != nil {
		if err := m.BeforeRemove(ctx, a.Username); err != nil {
			return TerminateResult{}, err
		}
	}
	if err := m.killAll(ctx, a.Username); err != nil {
		return TerminateResult{}, err
	}
	if err := m.Quota.Set(e.Home, e.UID, quota.Limits{}); err != nil && !errors.Is(err, quota.ErrOff) && !errors.Is(err, quota.ErrNotEnabled) {
		m.Log.Warn("could not clear the quota of a terminated account", "username", a.Username, "err", err)
	}
	// userdel without --remove: the home is removed below, by a path the agent
	// worked out itself rather than the one in passwd.
	if err := sysexec.Must(ctx, m.Run, "userdel", a.Username); err != nil {
		return TerminateResult{}, err
	}
	// os.RemoveAll does not follow symbolic links, so a link the customer
	// left in their home cannot point the removal elsewhere.
	if err := os.RemoveAll(m.home(a.Username)); err != nil {
		return TerminateResult{}, err
	}
	if err := os.Remove(filepath.Join("/var/spool/mail", a.Username)); err != nil && !errors.Is(err, os.ErrNotExist) {
		m.Log.Warn("could not remove a mail spool", "username", a.Username, "err", err)
	}
	return TerminateResult{Username: a.Username, Existed: true}, nil
}

// killAll ends every process of the user. pkill exits 1 when there were none.
func (m *Manager) killAll(ctx context.Context, name string) error {
	code, out, err := m.Run.Run(ctx, "pkill", "--signal", "KILL", "--uid", name)
	if err != nil {
		return err
	}
	if code != 0 && code != 1 {
		return fmt.Errorf("pkill exited with %d: %s", code, out)
	}
	return nil
}

// Info describes LPanel accounts, with their disk use.
func (m *Manager) Info(a InfoArgs) (Info, error) {
	for _, n := range a.Usernames {
		if err := checkName(n); err != nil {
			return Info{}, err
		}
	}
	all, err := m.Files.users()
	if err != nil {
		return Info{}, err
	}
	want := map[string]bool{}
	for _, n := range a.Usernames {
		want[n] = true
	}
	out := Info{Accounts: []Account{}, Missing: []string{}}
	for _, e := range all {
		if e.GECOS != Marker || e.UID < MinUID || filepath.Clean(e.Home) != m.home(e.Name) {
			continue
		}
		if len(want) > 0 && !want[e.Name] {
			continue
		}
		delete(want, e.Name)
		out.Accounts = append(out.Accounts, m.describe(e))
	}
	for _, n := range a.Usernames {
		if want[n] {
			out.Missing = append(out.Missing, n)
		}
	}
	return out, nil
}

func (m *Manager) describe(e Entry) Account {
	acct := Account{Username: e.Name, UID: e.UID, GID: e.GID, Home: e.Home, Shell: "none", Suspended: e.Suspended}
	for name, path := range Shells {
		if path == e.Shell {
			acct.Shell = name
		}
	}
	if u, err := m.Quota.Get(e.Home, e.UID); err == nil {
		acct.Usage = &u
	} else {
		acct.UsageProblem = err.Error()
	}
	return acct
}
