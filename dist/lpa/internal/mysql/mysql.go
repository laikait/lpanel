// Package mysql looks after each account's MariaDB databases and their users.
//
// The agent talks to MariaDB through its own command-line client over the
// local socket, as root (EL's MariaDB lets the system's root in through
// unix_socket authentication, with no password to keep). SQL goes on the
// client's standard input. Nothing the panel sends is put into SQL without a
// check first: database and user names come from a fixed alphabet and must
// carry the account's prefix ("bob_"), passwords arrive only as MariaDB's
// own hashes, privileges come from a fixed list and hosts from a narrow
// alphabet. So no quoting is ever needed, and none is trusted.
//
// The panel sends an account's whole database state at once (Apply): its
// databases, its users with their hashes and grants, and the remote hosts
// its users may connect from. Apply creates what is missing and drops users
// that are no longer listed, but it never drops a database: that is always
// an explicit request (Drop), so a lost row in the panel cannot cost an
// owner their data.
package mysql

import (
	"context"
	"crypto/rand"
	"crypto/sha1"
	"encoding/hex"
	"errors"
	"fmt"
	"io/fs"
	"log/slog"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"

	"lpa/internal/protocol"
)

// Error codes.
const (
	CodeUnavailable   = "db_unavailable"    // MariaDB is not installed or does not answer
	CodeFailed        = "db_failed"         // MariaDB refused a statement
	CodeExists        = "db_exists"         // the new name is taken
	CodeMissing       = "db_missing"        // no such database
	CodeRenameObjects = "db_rename_objects" // views, routines, triggers or events stop a rename
	CodeNotAccount    = "db_not_account"    // a thread to kill is not an account's
)

// Limits on names, as MariaDB and MySQL both accept them.
const (
	MaxDatabase = 64
	MaxUser     = 32
)

// Privileges are the ones an account's user may hold on its databases. ALL
// stands for every one of them (and no more: never GRANT OPTION, FILE,
// SUPER or anything global).
var Privileges = []string{
	"SELECT", "INSERT", "UPDATE", "DELETE", "CREATE", "DROP", "INDEX", "ALTER",
	"CREATE TEMPORARY TABLES", "LOCK TABLES", "EXECUTE", "CREATE VIEW", "SHOW VIEW",
	"CREATE ROUTINE", "ALTER ROUTINE", "EVENT", "TRIGGER", "REFERENCES",
}

var (
	accountRule = regexp.MustCompile(`^[a-z][a-z0-9]{1,15}$`)
	suffixRule  = regexp.MustCompile(`^[a-z0-9][a-z0-9_]*$`)
	hashRule    = regexp.MustCompile(`^\*[0-9A-F]{40}$`)
	// IPv4 and IPv6 addresses, IPv4 networks as address/netmask, host names,
	// and MariaDB's % and _ wildcards. Never a quote, backslash or space.
	hostRule = regexp.MustCompile(`^[A-Za-z0-9.:%_/-]{1,60}$`)
	// An account's user or its single sign-on user, for KILL.
	accountUser = regexp.MustCompile(`^([a-z][a-z0-9]{1,15}_[a-z0-9_]+|lpsso-[a-z][a-z0-9]{1,15})$`)
)

// Client runs SQL. The real one is CLI; tests use a fake.
type Client interface {
	// Run runs the statements and returns what they printed, one row per
	// line and tab between columns (MariaDB's batch format).
	Run(ctx context.Context, sql string) (string, error)
	// Installed tells whether MariaDB is on this server at all.
	Installed() bool
}

// Manager carries out the database operations.
type Manager struct {
	SQL Client
	// DataDir is MariaDB's data folder; a database's size is its folder's.
	DataDir string
	// Owner checks that a name is one of the panel's accounts.
	Owner func(name string) (uid, gid int, home string, err error)
	Log   *slog.Logger

	mu sync.Mutex
}

// Defaults fills in what tests and main leave empty.
func (m *Manager) Defaults() {
	if m.DataDir == "" {
		m.DataDir = "/var/lib/mysql"
	}
	if m.Log == nil {
		m.Log = slog.Default()
	}
}

// User is one of an account's database users.
type User struct {
	Name string `json:"name"`
	// Hash is MariaDB's mysql_native_password hash: "*" and 40 hex digits.
	Hash string `json:"hash"`
	// Grants are privileges by database: ["ALL"] or names from Privileges.
	Grants map[string][]string `json:"grants"`
}

// ApplyArgs is an account's whole database state.
type ApplyArgs struct {
	Username  string   `json:"username"`
	Databases []string `json:"databases"`
	Users     []User   `json:"users"`
	// Hosts other than localhost each user may connect from.
	Hosts []string `json:"hosts"`
	// Suspended locks every user (and ends their connections).
	Suspended bool `json:"suspended"`
}

// ApplyResult says what changed.
type ApplyResult struct {
	Changed bool     `json:"changed"`
	Created []string `json:"created"`
	// Stray databases carry the account's prefix but are not in the state.
	Stray []string `json:"stray"`
}

// Apply brings an account's databases and users in step.
func (m *Manager) Apply(ctx context.Context, a ApplyArgs) (ApplyResult, error) {
	res := ApplyResult{Created: []string{}, Stray: []string{}}
	if err := m.account(a.Username); err != nil {
		return res, err
	}
	prefix := a.Username + "_"
	want := map[string]bool{}
	for _, db := range a.Databases {
		if err := checkName(db, prefix, MaxDatabase); err != nil {
			return res, err
		}
		want[db] = true
	}
	hosts := []string{"localhost"}
	for _, h := range a.Hosts {
		if !hostRule.MatchString(h) || h == "localhost" {
			return res, protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf("host %q is not allowed", h))
		}
		hosts = append(hosts, h)
	}
	users := map[string]User{}
	for _, u := range a.Users {
		if err := checkName(u.Name, prefix, MaxUser); err != nil {
			return res, err
		}
		if !hashRule.MatchString(u.Hash) {
			return res, protocol.Fail(protocol.CodeBadArgs, "user "+u.Name+": the password hash is not a MariaDB hash")
		}
		for db, privs := range u.Grants {
			if !want[db] {
				return res, protocol.Fail(protocol.CodeBadArgs, "user "+u.Name+": grants on "+db+", which is not one of the account's databases")
			}
			if _, err := privilegeList(privs); err != nil {
				return res, err
			}
		}
		users[u.Name] = u
	}

	m.mu.Lock()
	defer m.mu.Unlock()
	if err := m.ready(); err != nil {
		return res, err
	}
	have, err := m.databases(ctx, prefix)
	if err != nil {
		return res, err
	}
	existing, err := m.users(ctx, prefix)
	if err != nil {
		return res, err
	}

	var sql strings.Builder
	for _, db := range sortedKeys(want) {
		if !have[db] {
			fmt.Fprintf(&sql, "CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n", db)
			res.Created = append(res.Created, db)
		}
	}
	for db := range have {
		if !want[db] {
			res.Stray = append(res.Stray, db)
		}
	}
	sort.Strings(res.Stray)

	lock := "ACCOUNT UNLOCK"
	if a.Suspended {
		lock = "ACCOUNT LOCK"
	}
	wanted := map[string]bool{}
	for _, name := range sortedKeys(users) {
		u := users[name]
		for _, h := range hosts {
			id := account(name, h)
			wanted[id] = true
			if existing[id] {
				fmt.Fprintf(&sql, "ALTER USER %s IDENTIFIED BY PASSWORD '%s' %s;\n", id, u.Hash, lock)
			} else {
				fmt.Fprintf(&sql, "CREATE USER %s IDENTIFIED BY PASSWORD '%s' %s;\n", id, u.Hash, lock)
				res.Changed = true
			}
			fmt.Fprintf(&sql, "REVOKE ALL PRIVILEGES, GRANT OPTION FROM %s;\n", id)
			for _, db := range sortedKeys(u.Grants) {
				privs, _ := privilegeList(u.Grants[db])
				fmt.Fprintf(&sql, "GRANT %s ON %s.* TO %s;\n", privs, grantTarget(db), id)
			}
		}
	}
	var gone []string
	for id := range existing {
		if !wanted[id] {
			gone = append(gone, id)
		}
	}
	sort.Strings(gone)
	for _, id := range gone {
		fmt.Fprintf(&sql, "DROP USER %s;\n", id)
		res.Changed = true
	}
	if len(res.Created) > 0 {
		res.Changed = true
	}
	if sql.Len() > 0 {
		if _, err := m.SQL.Run(ctx, sql.String()); err != nil {
			return res, fail(err)
		}
	}
	// A locked user keeps the connections it already has; end them.
	if a.Suspended {
		if err := m.killUsers(ctx, prefix, ""); err != nil {
			return res, err
		}
	}
	return res, nil
}

// NameArgs names one database of an account.
type NameArgs struct {
	Username string `json:"username"`
	Name     string `json:"name"`
}

// Drop deletes a database and everything in it.
func (m *Manager) Drop(ctx context.Context, a NameArgs) (map[string]bool, error) {
	if err := m.account(a.Username); err != nil {
		return nil, err
	}
	if err := checkName(a.Name, a.Username+"_", MaxDatabase); err != nil {
		return nil, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if err := m.ready(); err != nil {
		return nil, err
	}
	have, err := m.databases(ctx, a.Username+"_")
	if err != nil {
		return nil, err
	}
	if !have[a.Name] {
		return map[string]bool{"dropped": false}, nil
	}
	if _, err := m.SQL.Run(ctx, fmt.Sprintf("DROP DATABASE IF EXISTS `%s`;\n", a.Name)); err != nil {
		return nil, fail(err)
	}
	return map[string]bool{"dropped": true}, nil
}

// RenameArgs renames one database of an account.
type RenameArgs struct {
	Username string `json:"username"`
	From     string `json:"from"`
	To       string `json:"to"`
}

// Rename moves every table of a database into a new one of that name, then
// drops the old one. MariaDB has no RENAME DATABASE; tables move with one
// RENAME TABLE, which is quick and all or nothing. Views, routines,
// triggers and events cannot move that way, so a database with any is
// refused rather than half moved. Grants follow when the panel applies the
// account's state with the new name.
func (m *Manager) Rename(ctx context.Context, a RenameArgs) (map[string]bool, error) {
	if err := m.account(a.Username); err != nil {
		return nil, err
	}
	prefix := a.Username + "_"
	for _, n := range []string{a.From, a.To} {
		if err := checkName(n, prefix, MaxDatabase); err != nil {
			return nil, err
		}
	}
	if a.From == a.To {
		return nil, protocol.Fail(protocol.CodeBadArgs, "the new name is the old one")
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if err := m.ready(); err != nil {
		return nil, err
	}
	have, err := m.databases(ctx, prefix)
	if err != nil {
		return nil, err
	}
	switch {
	case !have[a.From]:
		return nil, protocol.Fail(CodeMissing, "there is no database "+a.From)
	case have[a.To]:
		return nil, protocol.Fail(CodeExists, "there is already a database "+a.To)
	}
	out, err := m.SQL.Run(ctx, fmt.Sprintf(`SELECT
 (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '%[1]s' AND TABLE_TYPE = 'VIEW')
 + (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '%[1]s')
 + (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '%[1]s')
 + (SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = '%[1]s');
SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '%[1]s';
SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '%[1]s' AND TABLE_TYPE <> 'VIEW';
`, a.From))
	if err != nil {
		return nil, fail(err)
	}
	rows := Rows(out)
	if len(rows) < 2 || len(rows[0]) != 1 || len(rows[1]) != 2 {
		return nil, protocol.Fail(CodeFailed, "MariaDB's answer about "+a.From+" was not understood")
	}
	if rows[0][0] != "0" {
		return nil, protocol.Fail(CodeRenameObjects, a.From+" has views, routines, triggers or events, which cannot be moved to another name")
	}
	charset, collation := rows[1][0], rows[1][1]
	if !suffixRule.MatchString(charset) || !suffixRule.MatchString(collation) {
		return nil, protocol.Fail(CodeFailed, "unexpected character set "+charset+"/"+collation)
	}
	var sql strings.Builder
	fmt.Fprintf(&sql, "CREATE DATABASE `%s` CHARACTER SET %s COLLATE %s;\n", a.To, charset, collation)
	var moves []string
	for _, r := range rows[2:] {
		if len(r) != 1 || strings.ContainsAny(r[0], "`\\") {
			return nil, protocol.Fail(CodeFailed, "a table name in "+a.From+" cannot be moved safely")
		}
		moves = append(moves, fmt.Sprintf("`%s`.`%s` TO `%s`.`%s`", a.From, r[0], a.To, r[0]))
	}
	if len(moves) > 0 {
		fmt.Fprintf(&sql, "RENAME TABLE %s;\n", strings.Join(moves, ", "))
	}
	fmt.Fprintf(&sql, "DROP DATABASE `%s`;\n", a.From)
	if _, err := m.SQL.Run(ctx, sql.String()); err != nil {
		return nil, fail(err)
	}
	return map[string]bool{"renamed": true}, nil
}

// SizesArgs limits Sizes to some accounts (all when empty).
type SizesArgs struct {
	Usernames []string `json:"usernames"`
}

// Sizes measures each account database's folder, in bytes.
func (m *Manager) Sizes(a SizesArgs) (map[string]any, error) {
	only := map[string]bool{}
	for _, u := range a.Usernames {
		if !accountRule.MatchString(u) {
			return nil, protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf("%q is not an account name", u))
		}
		only[u] = true
	}
	sizes := map[string]int64{}
	entries, err := os.ReadDir(m.DataDir)
	if err != nil {
		if errors.Is(err, fs.ErrNotExist) {
			return map[string]any{"databases": sizes}, nil
		}
		return nil, protocol.Fail(CodeUnavailable, err.Error())
	}
	for _, e := range entries {
		name := e.Name()
		owner, _, ok := strings.Cut(name, "_")
		if !e.IsDir() || !ok || !accountRule.MatchString(owner) || !suffixRule.MatchString(name[len(owner)+1:]) {
			continue
		}
		if len(only) > 0 && !only[owner] {
			continue
		}
		sizes[name] = dirSize(filepath.Join(m.DataDir, name))
	}
	return map[string]any{"databases": sizes}, nil
}

// SSOArgs asks for a login to phpMyAdmin.
type SSOArgs struct {
	Username  string   `json:"username"`
	Databases []string `json:"databases"`
}

// SSOResult is the login: a MariaDB user only for phpMyAdmin.
type SSOResult struct {
	User     string `json:"user"`
	Password string `json:"password"`
}

// SSO makes (or renews) the account's single sign-on user, "lpsso-<name>",
// with a new random password and every privilege on the account's
// databases, and nothing else: it cannot make databases, so phpMyAdmin
// cannot leave any the panel does not know. The panel hands the password to
// phpMyAdmin once and never keeps it; the next sign-on changes it, and
// SSORevoke removes the user.
func (m *Manager) SSO(ctx context.Context, a SSOArgs) (SSOResult, error) {
	if err := m.account(a.Username); err != nil {
		return SSOResult{}, err
	}
	for _, db := range a.Databases {
		if err := checkName(db, a.Username+"_", MaxDatabase); err != nil {
			return SSOResult{}, err
		}
	}
	secret := make([]byte, 24)
	if _, err := rand.Read(secret); err != nil {
		return SSOResult{}, err
	}
	password := hex.EncodeToString(secret)
	user := "lpsso-" + a.Username
	id := account(user, "localhost")

	m.mu.Lock()
	defer m.mu.Unlock()
	if err := m.ready(); err != nil {
		return SSOResult{}, err
	}
	var sql strings.Builder
	fmt.Fprintf(&sql, "CREATE USER IF NOT EXISTS %s;\n", id)
	fmt.Fprintf(&sql, "ALTER USER %s IDENTIFIED BY PASSWORD '%s' ACCOUNT UNLOCK;\n", id, NativeHash(password))
	fmt.Fprintf(&sql, "REVOKE ALL PRIVILEGES, GRANT OPTION FROM %s;\n", id)
	for _, db := range a.Databases {
		fmt.Fprintf(&sql, "GRANT ALL PRIVILEGES ON %s.* TO %s;\n", grantTarget(db), id)
	}
	if _, err := m.SQL.Run(ctx, sql.String()); err != nil {
		return SSOResult{}, fail(err)
	}
	return SSOResult{User: user, Password: password}, nil
}

// UserArgs names an account.
type UserArgs struct {
	Username string `json:"username"`
}

// SSORevoke removes the account's single sign-on user and ends its
// connections.
func (m *Manager) SSORevoke(ctx context.Context, a UserArgs) (map[string]bool, error) {
	if !accountRule.MatchString(a.Username) {
		return nil, protocol.Fail(protocol.CodeBadArgs, "not an account name")
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if !m.SQL.Installed() {
		return map[string]bool{"revoked": false}, nil
	}
	if err := m.killUsers(ctx, "", "lpsso-"+a.Username); err != nil {
		return nil, err
	}
	if _, err := m.SQL.Run(ctx, fmt.Sprintf("DROP USER IF EXISTS %s;\n", account("lpsso-"+a.Username, "localhost"))); err != nil {
		return nil, fail(err)
	}
	return map[string]bool{"revoked": true}, nil
}

// Remove drops every database and user of an account; account.terminate
// runs it before the account's system user goes. It is a no-op on a server
// without MariaDB.
func (m *Manager) Remove(ctx context.Context, a UserArgs) (map[string]int, error) {
	if !accountRule.MatchString(a.Username) {
		return nil, protocol.Fail(protocol.CodeBadArgs, "not an account name")
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if !m.SQL.Installed() {
		return map[string]int{"databases": 0, "users": 0}, nil
	}
	prefix := a.Username + "_"
	sso := "lpsso-" + a.Username
	if err := m.killUsers(ctx, prefix, sso); err != nil {
		return nil, err
	}
	have, err := m.databases(ctx, prefix)
	if err != nil {
		return nil, err
	}
	users, err := m.users(ctx, prefix)
	if err != nil {
		return nil, err
	}
	var sql strings.Builder
	for _, id := range sortedKeys(users) {
		fmt.Fprintf(&sql, "DROP USER %s;\n", id)
	}
	fmt.Fprintf(&sql, "DROP USER IF EXISTS %s;\n", account(sso, "localhost"))
	for _, db := range sortedKeys(have) {
		fmt.Fprintf(&sql, "DROP DATABASE IF EXISTS `%s`;\n", db)
	}
	if _, err := m.SQL.Run(ctx, sql.String()); err != nil {
		return nil, fail(err)
	}
	return map[string]int{"databases": len(have), "users": len(users)}, nil
}

// RemoveFor is Remove in the shape account.terminate's hook takes.
func (m *Manager) RemoveFor(ctx context.Context, username string) error {
	_, err := m.Remove(ctx, UserArgs{Username: username})
	return err
}

// Info describes the server.
type Info struct {
	Installed bool   `json:"installed"`
	Running   bool   `json:"running"`
	Version   string `json:"version"`
	Uptime    int64  `json:"uptime"`
	Threads   int64  `json:"threads"`
	Queries   int64  `json:"queries"`
	Error     string `json:"error,omitempty"`
}

// Info asks MariaDB about itself.
func (m *Manager) Info(ctx context.Context) Info {
	info := Info{Installed: m.SQL.Installed()}
	if !info.Installed {
		return info
	}
	out, err := m.SQL.Run(ctx, "SELECT VERSION();\nSHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime', 'Threads_connected', 'Queries');\n")
	if err != nil {
		info.Error = err.Error()
		return info
	}
	info.Running = true
	for i, r := range Rows(out) {
		switch {
		case i == 0 && len(r) == 1:
			info.Version = r[0]
		case len(r) == 2:
			n, _ := strconv.ParseInt(r[1], 10, 64)
			switch r[0] {
			case "Uptime":
				info.Uptime = n
			case "Threads_connected":
				info.Threads = n
			case "Queries":
				info.Queries = n
			}
		}
	}
	return info
}

// Process is one connection, as SHOW PROCESSLIST shows it.
type Process struct {
	ID      int64  `json:"id"`
	User    string `json:"user"`
	Host    string `json:"host"`
	DB      string `json:"db"`
	Command string `json:"command"`
	Time    int64  `json:"time"`
	State   string `json:"state"`
	Info    string `json:"info"`
	// Killable: an account's connection, which KILL may end.
	Killable bool `json:"killable"`
}

// Processes lists MariaDB's connections, the longest-running first.
func (m *Manager) Processes(ctx context.Context) ([]Process, error) {
	if err := m.ready(); err != nil {
		return nil, err
	}
	out, err := m.SQL.Run(ctx, "SELECT ID, USER, HOST, IFNULL(DB, ''), COMMAND, TIME, IFNULL(STATE, ''), IFNULL(LEFT(INFO, 500), ''), '.' FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() ORDER BY TIME DESC LIMIT 200;\n")
	if err != nil {
		return nil, fail(err)
	}
	list := []Process{}
	for _, r := range Rows(out) {
		// The last column is a marker, so an empty INFO is not trimmed away.
		if len(r) != 9 {
			continue
		}
		id, _ := strconv.ParseInt(r[0], 10, 64)
		t, _ := strconv.ParseInt(r[5], 10, 64)
		list = append(list, Process{ID: id, User: r[1], Host: r[2], DB: r[3], Command: r[4], Time: t, State: r[6], Info: r[7], Killable: accountUser.MatchString(r[1])})
	}
	return list, nil
}

// KillArgs names a connection.
type KillArgs struct {
	ID int64 `json:"id"`
}

// Kill ends one of an account's connections. The panel's own, root's and
// MariaDB's threads are never touched.
func (m *Manager) Kill(ctx context.Context, a KillArgs) (map[string]bool, error) {
	if a.ID <= 0 {
		return nil, protocol.Fail(protocol.CodeBadArgs, "a connection id is a positive number")
	}
	if err := m.ready(); err != nil {
		return nil, err
	}
	out, err := m.SQL.Run(ctx, fmt.Sprintf("SELECT USER FROM information_schema.PROCESSLIST WHERE ID = %d;\n", a.ID))
	if err != nil {
		return nil, fail(err)
	}
	rows := Rows(out)
	if len(rows) == 0 {
		return map[string]bool{"killed": false}, nil
	}
	if len(rows[0]) != 1 || !accountUser.MatchString(rows[0][0]) {
		return nil, protocol.Fail(CodeNotAccount, "connection "+strconv.FormatInt(a.ID, 10)+" is not an account's")
	}
	if _, err := m.SQL.Run(ctx, fmt.Sprintf("KILL CONNECTION %d;\n", a.ID)); err != nil {
		// It may have ended on its own meanwhile.
		if strings.Contains(err.Error(), "Unknown thread id") {
			return map[string]bool{"killed": false}, nil
		}
		return nil, fail(err)
	}
	return map[string]bool{"killed": true}, nil
}

// NativeHash is MariaDB's mysql_native_password hash of a password.
func NativeHash(password string) string {
	first := sha1.Sum([]byte(password))
	second := sha1.Sum(first[:])
	return "*" + strings.ToUpper(hex.EncodeToString(second[:]))
}

// Rows splits MariaDB's batch output into rows and columns, undoing its
// escapes (\t, \n, \0 and \\).
func Rows(out string) [][]string {
	var rows [][]string
	for _, line := range strings.Split(strings.TrimRight(out, "\n"), "\n") {
		if line == "" {
			continue
		}
		cols := strings.Split(line, "\t")
		for i, c := range cols {
			cols[i] = unescape(c)
		}
		rows = append(rows, cols)
	}
	return rows
}

func unescape(s string) string {
	if !strings.Contains(s, `\`) {
		return s
	}
	var b strings.Builder
	for i := 0; i < len(s); i++ {
		if s[i] != '\\' || i+1 == len(s) {
			b.WriteByte(s[i])
			continue
		}
		i++
		switch s[i] {
		case 't':
			b.WriteByte('\t')
		case 'n':
			b.WriteByte('\n')
		case '0':
			b.WriteByte(0)
		default:
			b.WriteByte(s[i])
		}
	}
	return b.String()
}

// ---------------------------------------------------------------------------

func (m *Manager) account(name string) error {
	if !accountRule.MatchString(name) {
		return protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf("%q is not an account name", name))
	}
	if m.Owner != nil {
		if _, _, _, err := m.Owner(name); err != nil {
			return err
		}
	}
	return nil
}

func (m *Manager) ready() error {
	if !m.SQL.Installed() {
		return protocol.Fail(CodeUnavailable, "MariaDB is not installed on this server")
	}
	return nil
}

// databases are the account's databases on the server now.
func (m *Manager) databases(ctx context.Context, prefix string) (map[string]bool, error) {
	out, err := m.SQL.Run(ctx, fmt.Sprintf("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE LEFT(SCHEMA_NAME, %d) = '%s';\n", len(prefix), prefix))
	if err != nil {
		return nil, fail(err)
	}
	have := map[string]bool{}
	for _, r := range Rows(out) {
		if len(r) == 1 {
			have[r[0]] = true
		}
	}
	return have, nil
}

// users are the account's users on the server now, as 'name'@'host'.
func (m *Manager) users(ctx context.Context, prefix string) (map[string]bool, error) {
	out, err := m.SQL.Run(ctx, fmt.Sprintf("SELECT User, Host FROM mysql.user WHERE LEFT(User, %d) = '%s';\n", len(prefix), prefix))
	if err != nil {
		return nil, fail(err)
	}
	have := map[string]bool{}
	for _, r := range Rows(out) {
		// A host outside our alphabet was not made by the agent, and cannot be
		// named safely in SQL; it is left alone.
		if len(r) == 2 && strings.HasPrefix(r[0], prefix) && suffixRule.MatchString(r[0][len(prefix):]) && hostRule.MatchString(r[1]) {
			have[account(r[0], r[1])] = true
		}
	}
	return have, nil
}

// killUsers ends the connections of an account's users (prefix) and/or one
// named user.
func (m *Manager) killUsers(ctx context.Context, prefix, user string) error {
	var cond []string
	if prefix != "" {
		cond = append(cond, fmt.Sprintf("LEFT(USER, %d) = '%s'", len(prefix), prefix))
	}
	if user != "" {
		cond = append(cond, fmt.Sprintf("USER = '%s'", user))
	}
	out, err := m.SQL.Run(ctx, "SELECT ID FROM information_schema.PROCESSLIST WHERE "+strings.Join(cond, " OR ")+";\n")
	if err != nil {
		return fail(err)
	}
	for _, r := range Rows(out) {
		id, err := strconv.ParseInt(r[0], 10, 64)
		if err != nil {
			continue
		}
		if _, err := m.SQL.Run(ctx, fmt.Sprintf("KILL CONNECTION %d;\n", id)); err != nil && !strings.Contains(err.Error(), "Unknown thread id") {
			return fail(err)
		}
	}
	return nil
}

func checkName(name, prefix string, max int) error {
	if len(name) > max || !strings.HasPrefix(name, prefix) || !suffixRule.MatchString(name[len(prefix):]) {
		return protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf("%q is not a name for this account: %s, then lowercase letters, digits and _, at most %d characters", name, prefix, max))
	}
	return nil
}

// privilegeList checks privileges and returns them as SQL.
func privilegeList(privs []string) (string, error) {
	if len(privs) == 0 {
		return "", protocol.Fail(protocol.CodeBadArgs, "a grant needs at least one privilege")
	}
	if len(privs) == 1 && privs[0] == "ALL" {
		return strings.Join(Privileges, ", "), nil
	}
	known := map[string]bool{}
	for _, p := range Privileges {
		known[p] = true
	}
	seen := map[string]bool{}
	var out []string
	for _, p := range privs {
		if !known[p] {
			return "", protocol.Fail(protocol.CodeBadArgs, fmt.Sprintf("%q is not a privilege an account may grant", p))
		}
		if !seen[p] {
			seen[p] = true
			out = append(out, p)
		}
	}
	return strings.Join(out, ", "), nil
}

// grantTarget names a database in GRANT. In a database-level grant, _ and %
// are wildcards; escaped, bob_shop means only bob_shop, never bobxshop.
func grantTarget(db string) string {
	return "`" + strings.ReplaceAll(db, "_", `\_`) + "`"
}

func account(user, host string) string { return "'" + user + "'@'" + host + "'" }

func fail(err error) error {
	var p *protocol.Error
	if errors.As(err, &p) {
		return err
	}
	return protocol.Fail(CodeFailed, err.Error())
}

func dirSize(dir string) int64 {
	var total int64
	_ = filepath.WalkDir(dir, func(_ string, d fs.DirEntry, err error) error {
		if err != nil || d.IsDir() {
			return nil
		}
		if info, err := d.Info(); err == nil {
			total += info.Size()
		}
		return nil
	})
	return total
}

func sortedKeys[V any](m map[string]V) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return keys
}
