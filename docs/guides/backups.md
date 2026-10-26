# Database backups

`backup:make` and `backup:restore`, for MySQL, PostgreSQL, SQLite and SQL
Server, following the pattern in `tests/Fixtures/Modules/Backup/`, which you
can copy wholesale into your own `modules/Backup/`.

It is not shipped with the framework. `modules/` ships with `Shared` and
nothing else -- see [What is not built](../what-is-not-built.md) -- so this
is something you add, the same way you would add any other module.

## What each driver actually needs

Only MySQL and PostgreSQL shell out to an external program. SQLite and SQL
Server go through the same PDO connection the application already has, so
neither needs anything added to `config/system.php`.

| Driver | Backup | Restore | External tool |
|---|---|---|---|
| MySQL | `mysqldump --single-transaction --no-tablespaces -h HOST -P PORT -u USER DB`, stdout written to a `.sql` file | `mysql -h HOST -P PORT -u USER DB`, with the file piped in as stdin | `mysqldump`, `mysql` |
| PostgreSQL | `pg_dump -F p -h HOST -p PORT -U USER DB`, stdout to `.sql` | `psql -h HOST -p PORT -U USER DB`, file piped in as stdin | `pg_dump`, `psql` |
| SQLite | `VACUUM INTO '<file>'`, over PDO -- atomic, safe on a live database | Copies the file over the live one | none |
| SQL Server | `BACKUP DATABASE [db] TO DISK = N'<file>' WITH INIT, COMPRESSION`, over PDO | `RESTORE DATABASE [db] FROM DISK = N'<file>' WITH REPLACE` | none |

The password never appears as a command-line argument (visible in `ps` to
anyone on the machine): MySQL gets it through the `MYSQL_PWD` environment
variable, PostgreSQL through `PGPASSWORD`, both wrapped in
`App\Engine\Security\Secret` until the moment the process actually starts.

## Before mysqldump/pg_dump will run at all

`CommandExecutor` default-denies every external program unless
`config/system.php` names it (`CommandPolicy`, `engine/System/SystemConfig.php`).
Add the four MySQL/PostgreSQL binaries with their absolute paths (matched
after `realpath()` -- no wildcards):

```php
// config/system.php
return [
    'commands' => [
        'allowed' => [
            '/usr/bin/mysqldump',
            '/usr/bin/mysql',
            '/usr/bin/pg_dump',
            '/usr/bin/psql',
        ],
    ],
];
```

Running `backup:make`/`backup:restore` against MySQL or PostgreSQL without
this refuses immediately, with a clear "not allowed" error -- that is the
policy working as designed, not a bug to work around.

## The two commands

```bash
php laika backup:make --connection=default
php laika backup:restore --connection=default --force system/Backups/default-mysql-2026-09-27-020000.sql
```

`backup:make` never needs `--force`: the filename is timestamped
(`<connection>-<driver>-<Y-m-d-His>.<ext>`), so nothing already there is ever
overwritten.

`backup:restore` always needs `--force`, on every environment, not only
production -- there is no `down()` for a restore. `--connection` has no
default either: which database a restore overwrites is not something a typo
should decide for you.

## Storage location

```php
// modules/Backup/module.php
$module->config([
    'directory' => null, // null = system/Backups under the application root
]);
```

Set `Backup.directory` in `config/Backup.php` to put backups somewhere else
-- off the application's own disk, ideally, since a backup living next to
what it backs up survives none of the failures that make you need it.
Nothing here prunes old backups; that is a separate decision (a cron job
with `find -mtime +N -delete`, or a `backup:prune` you add yourself).

## Restoring: the two caveats that actually matter

**SQLite: stop the application first.** There is no coordination attempted
with whatever else has the database file open. Restoring while the
application is still handling requests means replacing a file mid-write,
which is a corrupted database, not a restored one.

**SQL Server: the path is on the database server's filesystem, not
necessarily this one.** `BACKUP`/`RESTORE DATABASE ... TO/FROM DISK` is a
statement the SQL Server *service* executes, and it writes/reads on whatever
machine that service runs on. This lines up automatically only when the
application and the database share a filesystem -- the common case for a
small, single-server deployment. Otherwise `directory` has to be a path
(typically a UNC share) that the SQL Server service account can itself
reach, which is a deployment decision this module cannot make for you.

## Scheduling

Not run by default. Add it because you decided to, in your own
`modules/Backup/module.php`:

```php
$module->schedules(static function (ScheduleCollector $schedules): void {
    $schedules->command('backup:make')->dailyAt('02:00')->withoutOverlapping();
});
```

See [Background work](background-work.md) for how the scheduler itself
works, and `php laika schedule:list` to confirm it registered.

## Watching it work

Neither service calls a logger directly; both fire hook events
(`backup.started`/`.completed`/`.failed`, `restore.started`/`.completed`/`.failed`)
that `Log/BackupLog.php` listens to, the same shape as `ScheduleLog` for the
scheduler (`engine/Logging/ScheduleLog.php`). Delete `BackupLog` and backups
still run; they simply stop being written to the log. `CommandExecutor`
separately audits every `mysqldump`/`mysql`/`pg_dump`/`psql` invocation on
its own (`system.command.*`), whether or not `BackupLog` is there.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| "not allowed" running mysqldump/pg_dump | `config/system.php` does not name it | Add its absolute path to `commands.allowed`, above |
| `backup:restore` refuses immediately | No `--force` | Pass `--force`; it is required on every environment |
| A restored SQLite database is corrupt | The application was still writing to it during the copy | Stop the application first |
| SQL Server's `BACKUP DATABASE` says the path does not exist | The path is resolved on the database server, not this machine | Use a path the SQL Server service account can reach |
| The connection's DSN names no database | `Dsn::parse()` found no `dbname`/`Database` part | Check the connection's `DB_DSN`; `sqlite::memory:` cannot be backed up to a file at all except via `VACUUM INTO` into a real path |
