# ADR-0008: The panel's own database is MariaDB

- **Status:** Accepted (product owner, 2026-10-27)
- **Context:** LPHP supports MySQL/MariaDB, PostgreSQL, SQLite and SQL Server. Customer databases will be MariaDB (plan §3.3). The supported OSes ship two series: 10.11 (Ubuntu 24.04, Debian 12) and 11.8 (Ubuntu 26.04, Debian 13), per ADR-0004's measured table.

## Decision

- Production: the distro's MariaDB (10.11 or 11.8), with a dedicated `lpanel` database and two accounts:
  - `lpanel_migrate` — DDL, used only by `php laika migrate` during install and upgrade;
  - `lpanel_app` — DML only, used by the web process and workers.

  This follows `docs/reference/database.md` §"Migrations and seeders" ("the application's own account should not be able to create tables").
- Tests: SQLite in memory by default (`ApplicationTestCase`). CI also runs against MariaDB via `DB_TEST_MYSQL_DSN`, because MySQL DDL isn't transactional and its locking differs.
- The panel's database is never the one customer databases are created in. On a single host they may share a MariaDB server, but an LPA database operation can never target the `lpanel` schema or the `lpanel_*` accounts (an LPA-side deny list).

## Consequences

- CI runs the panel's MariaDB tests against both 10.11 and 11.8.
- Operation claiming relies on `SELECT … FOR UPDATE`, which InnoDB supports.
- PostgreSQL support for the panel database isn't promised.
