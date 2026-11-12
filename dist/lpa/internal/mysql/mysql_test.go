package mysql

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"lpa/internal/protocol"
)

// fake answers the agent's queries from a small model of the server and
// records every other statement.
type fake struct {
	dbs       map[string]bool
	users     map[string]bool // "name\thost"
	procs     [][]string      // id, user
	objects   string          // the rename check's count
	tables    []string
	ran       []string
	fail      string // a statement containing this fails
	installed bool
}

func newFake() *fake {
	return &fake{dbs: map[string]bool{}, users: map[string]bool{}, objects: "0", installed: true}
}

func (f *fake) Installed() bool { return f.installed }

func (f *fake) Run(_ context.Context, sql string) (string, error) {
	if f.fail != "" && strings.Contains(sql, f.fail) {
		return "", errors.New("ERROR 1064 (42000) at line 1: You have an error")
	}
	switch {
	case strings.HasPrefix(sql, "SELECT SCHEMA_NAME"):
		var out []string
		for db := range f.dbs {
			if strings.Contains(sql, "'"+strings.SplitN(db, "_", 2)[0]+"_'") {
				out = append(out, db)
			}
		}
		return strings.Join(out, "\n"), nil
	case strings.HasPrefix(sql, "SELECT User, Host"):
		var out []string
		for u := range f.users {
			if strings.Contains(sql, "'"+strings.SplitN(u, "_", 2)[0]+"_'") {
				out = append(out, u)
			}
		}
		return strings.Join(out, "\n"), nil
	case strings.HasPrefix(sql, "SELECT ID FROM"):
		var out []string
		for _, p := range f.procs {
			if strings.Contains(sql, "'"+p[1]+"'") || strings.Contains(sql, "'"+strings.SplitN(p[1], "_", 2)[0]+"_'") {
				out = append(out, p[0])
			}
		}
		return strings.Join(out, "\n"), nil
	case strings.HasPrefix(sql, "SELECT USER FROM"):
		for _, p := range f.procs {
			if strings.Contains(sql, "= "+p[0]+";") {
				return p[1], nil
			}
		}
		return "", nil
	case strings.HasPrefix(sql, "SELECT\n (SELECT COUNT(*)"):
		return f.objects + "\nutf8mb4\tutf8mb4_unicode_ci\n" + strings.Join(f.tables, "\n"), nil
	}
	f.ran = append(f.ran, sql)
	return "", nil
}

func (f *fake) script() string { return strings.Join(f.ran, "") }

func manager(f *fake) *Manager {
	m := &Manager{SQL: f, Owner: func(name string) (int, int, string, error) {
		if name == "ghost" {
			return 0, 0, "", protocol.Fail("not_account", "no such account")
		}
		return 1000, 1000, "/home/" + name, nil
	}}
	m.Defaults()
	return m
}

var hash = NativeHash("correct horse")

func TestNativeHashIsMariaDBs(t *testing.T) {
	// SELECT PASSWORD('password') in MariaDB.
	if got := NativeHash("password"); got != "*2470C0C06DEE42FD1618BB99005ADCA2EC9D1E19" {
		t.Fatalf("hash = %s", got)
	}
}

func TestApplyCreatesDatabasesUsersAndExactGrants(t *testing.T) {
	f := newFake()
	m := manager(f)
	res, err := m.Apply(context.Background(), ApplyArgs{
		Username:  "bob",
		Databases: []string{"bob_shop", "bob_blog"},
		Users: []User{{Name: "bob_app", Hash: hash, Grants: map[string][]string{
			"bob_shop": {"ALL"},
			"bob_blog": {"SELECT", "INSERT", "SELECT"},
		}}},
		Hosts: []string{"203.0.113.7", "198.51.100.0/255.255.255.0"},
	})
	if err != nil {
		t.Fatal(err)
	}
	if !res.Changed || len(res.Created) != 2 {
		t.Fatalf("result = %+v", res)
	}
	s := f.script()
	for _, want := range []string{
		"CREATE DATABASE IF NOT EXISTS `bob_blog` CHARACTER SET utf8mb4",
		"CREATE DATABASE IF NOT EXISTS `bob_shop`",
		"CREATE USER 'bob_app'@'localhost' IDENTIFIED BY PASSWORD '" + hash + "' ACCOUNT UNLOCK;",
		"CREATE USER 'bob_app'@'203.0.113.7' IDENTIFIED BY PASSWORD",
		"CREATE USER 'bob_app'@'198.51.100.0/255.255.255.0' IDENTIFIED BY PASSWORD",
		"REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'bob_app'@'localhost';",
		// _ is escaped, so the grant means this database only.
		"GRANT SELECT, INSERT ON `bob\\_blog`.* TO 'bob_app'@'localhost';",
		"GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE, EVENT, TRIGGER, REFERENCES ON `bob\\_shop`.* TO 'bob_app'@'localhost';",
	} {
		if !strings.Contains(s, want) {
			t.Errorf("missing %q in:\n%s", want, s)
		}
	}
	if strings.Contains(s, "GRANT OPTION ON") || strings.Contains(s, "ALL PRIVILEGES ON") {
		t.Errorf("an account user must never get GRANT OPTION or ALL PRIVILEGES:\n%s", s)
	}
}

func TestApplyAltersKnownUsersDropsUnlistedAndNeverDropsDatabases(t *testing.T) {
	f := newFake()
	f.dbs["bob_shop"], f.dbs["bob_old"] = true, true
	f.users["bob_app\tlocalhost"] = true
	f.users["bob_app\t203.0.113.7"] = true // that host is no longer listed
	f.users["bob_gone\tlocalhost"] = true
	f.users["alice_app\tlocalhost"] = true // another account's: never touched
	m := manager(f)
	res, err := m.Apply(context.Background(), ApplyArgs{
		Username: "bob", Databases: []string{"bob_shop"},
		Users: []User{{Name: "bob_app", Hash: hash, Grants: map[string][]string{}}},
	})
	if err != nil {
		t.Fatal(err)
	}
	s := f.script()
	for _, want := range []string{
		"ALTER USER 'bob_app'@'localhost' IDENTIFIED BY PASSWORD",
		"DROP USER 'bob_app'@'203.0.113.7';",
		"DROP USER 'bob_gone'@'localhost';",
	} {
		if !strings.Contains(s, want) {
			t.Errorf("missing %q in:\n%s", want, s)
		}
	}
	for _, never := range []string{"DROP DATABASE", "alice", "CREATE DATABASE"} {
		if strings.Contains(s, never) {
			t.Errorf("%q must not appear:\n%s", never, s)
		}
	}
	if len(res.Stray) != 1 || res.Stray[0] != "bob_old" {
		t.Errorf("stray = %v", res.Stray)
	}
}

func TestApplySuspendedLocksUsersAndEndsTheirConnections(t *testing.T) {
	f := newFake()
	f.dbs["bob_shop"] = true
	f.users["bob_app\tlocalhost"] = true
	f.procs = [][]string{{"41", "bob_app"}, {"42", "alice_app"}, {"43", "lpanel"}}
	m := manager(f)
	_, err := m.Apply(context.Background(), ApplyArgs{
		Username: "bob", Databases: []string{"bob_shop"}, Suspended: true,
		Users: []User{{Name: "bob_app", Hash: hash, Grants: map[string][]string{"bob_shop": {"ALL"}}}},
	})
	if err != nil {
		t.Fatal(err)
	}
	s := f.script()
	if !strings.Contains(s, "ACCOUNT LOCK;") || !strings.Contains(s, "KILL CONNECTION 41;") {
		t.Fatalf("script:\n%s", s)
	}
	if strings.Contains(s, "KILL CONNECTION 42") || strings.Contains(s, "KILL CONNECTION 43") {
		t.Fatalf("only bob's connections may be ended:\n%s", s)
	}
}

func TestApplyRefusesAnythingThatCouldReachSQLUnchecked(t *testing.T) {
	cases := map[string]ApplyArgs{
		"other prefix":      {Username: "bob", Databases: []string{"alice_shop"}},
		"no suffix":         {Username: "bob", Databases: []string{"bob_"}},
		"quote in name":     {Username: "bob", Databases: []string{"bob_x`; DROP DATABASE mysql; --"}},
		"upper case":        {Username: "bob", Databases: []string{"bob_Shop"}},
		"too long db":       {Username: "bob", Databases: []string{"bob_" + strings.Repeat("a", 61)}},
		"too long user":     {Username: "bob", Users: []User{{Name: "bob_" + strings.Repeat("a", 29), Hash: hash}}},
		"plain password":    {Username: "bob", Users: []User{{Name: "bob_app", Hash: "hunter2"}}},
		"quote in host":     {Username: "bob", Hosts: []string{"1.2.3.4' OR '1'='1"}},
		"localhost again":   {Username: "bob", Hosts: []string{"localhost"}},
		"global privilege":  {Username: "bob", Databases: []string{"bob_shop"}, Users: []User{{Name: "bob_app", Hash: hash, Grants: map[string][]string{"bob_shop": {"FILE"}}}}},
		"grant option":      {Username: "bob", Databases: []string{"bob_shop"}, Users: []User{{Name: "bob_app", Hash: hash, Grants: map[string][]string{"bob_shop": {"GRANT OPTION"}}}}},
		"grant on unlisted": {Username: "bob", Databases: []string{"bob_shop"}, Users: []User{{Name: "bob_app", Hash: hash, Grants: map[string][]string{"bob_other": {"ALL"}}}}},
		"no privileges":     {Username: "bob", Databases: []string{"bob_shop"}, Users: []User{{Name: "bob_app", Hash: hash, Grants: map[string][]string{"bob_shop": {}}}}},
		"bad account":       {Username: "Bob"},
		"not an account":    {Username: "ghost"},
	}
	for name, a := range cases {
		f := newFake()
		_, err := manager(f).Apply(context.Background(), a)
		if err == nil {
			t.Errorf("%s: accepted", name)
		}
		if len(f.ran) > 0 {
			t.Errorf("%s: ran SQL before refusing: %v", name, f.ran)
		}
	}
}

func TestApplyWithoutMariaDBSaysSo(t *testing.T) {
	f := newFake()
	f.installed = false
	_, err := manager(f).Apply(context.Background(), ApplyArgs{Username: "bob"})
	var p *protocol.Error
	if !errors.As(err, &p) || p.Code != CodeUnavailable {
		t.Fatalf("err = %v", err)
	}
}

func TestApplyReportsMariaDBRefusing(t *testing.T) {
	f := newFake()
	f.fail = "CREATE DATABASE"
	_, err := manager(f).Apply(context.Background(), ApplyArgs{Username: "bob", Databases: []string{"bob_shop"}})
	var p *protocol.Error
	if !errors.As(err, &p) || p.Code != CodeFailed {
		t.Fatalf("err = %v", err)
	}
}

func TestDropOnlyTheAccountsOwnDatabase(t *testing.T) {
	f := newFake()
	f.dbs["bob_shop"] = true
	m := manager(f)
	res, err := m.Drop(context.Background(), NameArgs{Username: "bob", Name: "bob_shop"})
	if err != nil || !res["dropped"] || !strings.Contains(f.script(), "DROP DATABASE IF EXISTS `bob_shop`;") {
		t.Fatalf("res=%v err=%v script=%s", res, err, f.script())
	}
	res, err = m.Drop(context.Background(), NameArgs{Username: "bob", Name: "bob_none"})
	if err != nil || res["dropped"] {
		t.Fatalf("a missing database: res=%v err=%v", res, err)
	}
	if _, err := m.Drop(context.Background(), NameArgs{Username: "bob", Name: "mysql"}); err == nil {
		t.Fatal("dropped a database without the account's prefix")
	}
}

func TestRenameMovesTablesInOneStatement(t *testing.T) {
	f := newFake()
	f.dbs["bob_shop"] = true
	f.tables = []string{"orders", "items"}
	m := manager(f)
	if _, err := m.Rename(context.Background(), RenameArgs{Username: "bob", From: "bob_shop", To: "bob_store"}); err != nil {
		t.Fatal(err)
	}
	s := f.script()
	for _, want := range []string{
		"CREATE DATABASE `bob_store` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
		"RENAME TABLE `bob_shop`.`orders` TO `bob_store`.`orders`, `bob_shop`.`items` TO `bob_store`.`items`;",
		"DROP DATABASE `bob_shop`;",
	} {
		if !strings.Contains(s, want) {
			t.Errorf("missing %q in:\n%s", want, s)
		}
	}
}

func TestRenameRefusesWhatCannotMove(t *testing.T) {
	f := newFake()
	f.dbs["bob_shop"], f.dbs["bob_taken"] = true, true
	m := manager(f)
	check := func(a RenameArgs, code string) {
		t.Helper()
		_, err := m.Rename(context.Background(), a)
		var p *protocol.Error
		if !errors.As(err, &p) || p.Code != code {
			t.Errorf("%+v: err = %v, want %s", a, err, code)
		}
	}
	check(RenameArgs{Username: "bob", From: "bob_none", To: "bob_new"}, CodeMissing)
	check(RenameArgs{Username: "bob", From: "bob_shop", To: "bob_taken"}, CodeExists)
	check(RenameArgs{Username: "bob", From: "bob_shop", To: "alice_x"}, protocol.CodeBadArgs)
	f.objects = "2"
	check(RenameArgs{Username: "bob", From: "bob_shop", To: "bob_new"}, CodeRenameObjects)
	if len(f.ran) > 0 {
		t.Fatalf("ran: %v", f.ran)
	}
}

func TestSSOUserHasOnlyTheAccountsDatabasesAndAFreshPassword(t *testing.T) {
	f := newFake()
	m := manager(f)
	one, err := m.SSO(context.Background(), SSOArgs{Username: "bob", Databases: []string{"bob_shop"}})
	if err != nil {
		t.Fatal(err)
	}
	two, _ := m.SSO(context.Background(), SSOArgs{Username: "bob", Databases: []string{"bob_shop"}})
	if one.User != "lpsso-bob" || len(one.Password) != 48 || one.Password == two.Password {
		t.Fatalf("one=%+v two=%+v", one, two)
	}
	s := f.ran[0]
	if strings.Contains(s, one.Password) {
		t.Fatal("the password itself reached SQL; only its hash may")
	}
	for _, want := range []string{
		"CREATE USER IF NOT EXISTS 'lpsso-bob'@'localhost';",
		"IDENTIFIED BY PASSWORD '" + NativeHash(one.Password) + "'",
		"GRANT ALL PRIVILEGES ON `bob\\_shop`.* TO 'lpsso-bob'@'localhost';",
	} {
		if !strings.Contains(s, want) {
			t.Errorf("missing %q in:\n%s", want, s)
		}
	}
	if strings.Contains(s, "ON *.*") {
		t.Errorf("a global grant:\n%s", s)
	}
}

func TestRemoveDropsEverythingOfTheAccountOnly(t *testing.T) {
	f := newFake()
	f.dbs["bob_shop"], f.dbs["alice_shop"] = true, true
	f.users["bob_app\tlocalhost"], f.users["alice_app\tlocalhost"] = true, true
	f.procs = [][]string{{"7", "lpsso-bob"}, {"8", "alice_app"}}
	res, err := manager(f).Remove(context.Background(), UserArgs{Username: "bob"})
	if err != nil || res["databases"] != 1 || res["users"] != 1 {
		t.Fatalf("res=%v err=%v", res, err)
	}
	s := f.script()
	for _, want := range []string{"KILL CONNECTION 7;", "DROP USER 'bob_app'@'localhost';", "DROP USER IF EXISTS 'lpsso-bob'@'localhost';", "DROP DATABASE IF EXISTS `bob_shop`;"} {
		if !strings.Contains(s, want) {
			t.Errorf("missing %q in:\n%s", want, s)
		}
	}
	if strings.Contains(s, "alice") || strings.Contains(s, "KILL CONNECTION 8") {
		t.Errorf("touched another account:\n%s", s)
	}
	f.installed = false
	if _, err := manager(f).Remove(context.Background(), UserArgs{Username: "bob"}); err != nil {
		t.Fatalf("without MariaDB, remove is a no-op: %v", err)
	}
}

func TestKillOnlyAnAccountsConnection(t *testing.T) {
	f := newFake()
	f.procs = [][]string{{"5", "bob_app"}, {"6", "lpanel"}, {"7", "root"}, {"8", "lpsso-bob"}}
	m := manager(f)
	for _, id := range []int64{5, 8} {
		if res, err := m.Kill(context.Background(), KillArgs{ID: id}); err != nil || !res["killed"] {
			t.Errorf("%d: res=%v err=%v", id, res, err)
		}
	}
	for _, id := range []int64{6, 7} {
		_, err := m.Kill(context.Background(), KillArgs{ID: id})
		var p *protocol.Error
		if !errors.As(err, &p) || p.Code != CodeNotAccount {
			t.Errorf("%d: err = %v", id, err)
		}
	}
	if res, _ := m.Kill(context.Background(), KillArgs{ID: 99}); res["killed"] {
		t.Error("killed a connection that does not exist")
	}
}

func TestSizesMeasuresAccountDatabaseFolders(t *testing.T) {
	dir := t.TempDir()
	write := func(path string, n int) {
		_ = os.MkdirAll(filepath.Dir(path), 0o755)
		_ = os.WriteFile(path, make([]byte, n), 0o644)
	}
	write(filepath.Join(dir, "bob_shop", "orders.ibd"), 3000)
	write(filepath.Join(dir, "bob_shop", "items.ibd"), 1000)
	write(filepath.Join(dir, "alice_blog", "posts.ibd"), 500)
	write(filepath.Join(dir, "mysql", "user.MAD"), 9999)
	write(filepath.Join(dir, "ibdata1"), 9999)
	m := manager(newFake())
	m.DataDir = dir
	res, err := m.Sizes(SizesArgs{})
	if err != nil {
		t.Fatal(err)
	}
	sizes := res["databases"].(map[string]int64)
	if len(sizes) != 2 || sizes["bob_shop"] != 4000 || sizes["alice_blog"] != 500 {
		t.Fatalf("sizes = %v", sizes)
	}
	res, _ = m.Sizes(SizesArgs{Usernames: []string{"alice"}})
	if s := res["databases"].(map[string]int64); len(s) != 1 || s["alice_blog"] != 500 {
		t.Fatalf("only alice: %v", s)
	}
}

func TestRowsUndoesBatchEscapes(t *testing.T) {
	rows := Rows("1\tSELECT\\t'a\\nb'\\\\\t.\n2\t\t.\n")
	if len(rows) != 2 || rows[0][1] != "SELECT\t'a\nb'\\" || rows[1][1] != "" {
		t.Fatalf("rows = %q", rows)
	}
}
