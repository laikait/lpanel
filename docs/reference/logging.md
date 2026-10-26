# Logging

A log record says what happened, at what severity, with some structured data
attached. This page covers writing one, choosing where records go, and what the
framework does to make sure logging never breaks a request.

## Write a record

Ask for a `Logger`:

```php
final class ChargeCard
{
    public function __construct(private readonly Logger $log) {}

    public function __invoke(Order $order): void
    {
        $this->log->info('Card charged', ['order' => $order->id, 'amount' => $order->total]);
    }
}
```

One method per level — `emergency`, `alert`, `critical`, `error`, `warning`,
`notice`, `info`, `debug` — plus `log(Level $level, …)` for a level held in a
variable, and `with([...])` for context added to every later record.

The logger never learns where records go. That is a deployment decision, and it
changes without your module changing. **With no writers configured the call
costs one integer comparison and goes nowhere.**

A *channel* is a label on a record, so related records can be found together.
`LogManager::channel('billing')` returns a logger that tags everything it
writes; the framework uses `mcp`, `profile` and a few others.

## Choose where records go

**Nothing is attached by default.** A framework that starts writing files into a
folder nobody asked about is one that fills up a disk on somebody else's
machine.

```php
// config/logging.php
return [
    'writers' => ['file'],       // file, stderr, syslog, database
    'level'   => 'info',
    'file'    => ['prefix' => 'app', 'retention_days' => 30],
];
```

| Writer | Writes to | Suits |
|---|---|---|
| `file` | one file per day under `system/Logs` | one machine |
| `stderr` | standard error | containers, where the platform collects output |
| `syslog` | the system log | a machine that already ships its system log somewhere |
| `database` | a table | several machines sharing a database — **alongside** another writer |

Check what is actually attached:

```bash
php laika log:status            # what is attached, and what has stopped
php laika log:status --write    # and whether a record really arrives
```

An unknown writer name or an unreadable level is **ignored rather than fatal**:
a typo in a deployment's configuration must not stop an application from
starting, and `log:status` reports what is actually attached.

### The file writer

One file per day, named from `prefix`. Daily rather than by size, because the
two questions anybody asks of a log are "what happened just now" and "what
happened on the day the invoices went wrong", and a date in the filename answers
the second without reading the first.

`retention_days` deletes older files, and is bounded hard: only that folder,
only that writer's own naming, and only when a positive number is set. **The
default is 0, meaning keep everything.** Deleting an audit trail because a
default said so is a worse failure than a large folder.

### The database writer

```php
'logging' => [
    'writers'  => ['file', 'database'],
    'database' => ['connection' => '', 'table' => 'logs', 'retention_days' => 30],
],
```

- **Its table comes from `php laika migrate`**, as a framework migration, like
  the session, cache and queue tables. It is created only while `writers` names
  `database`. Until it exists the writer retires on its first record, and
  `log:status` prints the command to run.
- **Always name another writer alongside it.** A log kept in the database cannot
  record the database failing: when the database goes, this writer retires, and
  only the other writer is left to say why.
- **It uses a connection of its own** on MySQL, PostgreSQL and SQL Server. A
  shared connection would put each record inside whatever transaction the
  application had open, and a rollback would take the record of what went wrong
  with it. Writing a record is also never itself reported as a slow query. On
  SQLite, which allows one writer at a time, it shares the application's
  connection, because a second one would wait behind the first's transaction.
- **Each row** holds `logged_at` (UTC, with microseconds), `level` (the RFC 5424
  code, so "error or worse" is `level <= 3`), `level_name`, `channel`, `message`
  and `context` (JSON, or null when there is none).
- **`retention_days`** is 0 by default, as for files. When set, older rows are
  deleted once per process, before the first record.
- **`LOG_CONNECTION`** names the connection; empty means the default one.

**A writer that sends records to another server is not built.** It needs an HTTP
client that does not exist yet. `LogWriter` is three methods — `describe()`,
`accepts()`, `write()` — so it is a small class in an application that wants one.

## Context is data, not string interpolation

There is no `{placeholder}` splicing. A message is a message and context is
data; splicing one into the other produces a line that is harder to grep and a
context that has been said twice.

Context is cleaned up before any writer sees it, because a log line is written at
the worst possible moment and must not be the thing that fails next. A closure,
a PDO handle, a model with a circular reference, `NAN`, an open file, ten
thousand rows — all of those either break `json_encode` or write a megabyte into
the log. So:

- scalars survive as they are;
- throwables become class, message and position;
- objects become their class name, unless they can say more for themselves;
- depth, string length and item count are capped.

**Some key names are replaced with `[redacted]`**: `password`, `token`,
`authorization`, `api_key` and a dozen more, and the list is configurable.

That is deliberately not the same thing as filtering a message, which this
framework refuses to do, on the grounds that guessing which substrings are
secret is wrong eventually. A key is not a substring — it is structured data,
matched exactly, against a list somebody wrote down. It catches the case that
actually happens, a request payload logged whole with `password` still in it,
and claims nothing about the rest.

**A log file is still sensitive.** An architecture test asserts `system/Logs` is
refused by the web server.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| Nothing is logged | No writer is configured; that is the default | Create `config/logging.php` |
| Logging stopped part-way through | A writer failed and was retired for this process | `php laika log:status` names it and why |
| The database writer says the table is missing | Its migration has not run on that connection | `php laika migrate` |
| A writer name does nothing | It is unknown, and was ignored rather than fatal | `log:status` shows what is attached |
| Records are missing below a level | `logging.level` filters them out | Lower it |
| A scheduled command's output is nowhere | It goes to the log, and nothing is logged yet | Configure a writer |

## Why it works this way

### Logging never breaks a request

This is the hardest requirement in this area, and most of `LogManager` follows
from it.

A writer that throws is caught, **retired** — taken out of the rotation for the
rest of the process — and the reason is kept.

Retiring matters as much as catching. A full disk does not un-fill itself, so a
writer that failed once fails on every record, and a request that logs forty
times would otherwise spend forty exceptions finding that out. Keeping the reason
matters because the failure mode of "catch everything" is an application that has
silently not been logging for three weeks, which is worse than the exception was.

Reentrancy is refused for the same reason: a writer that logs would recurse until
the stack ran out.

And **a writer may not catch its own failures**. An architecture test forbids
`catch` under `engine/Logging/Writers/`, because a writer that swallows its own
errors looks safer and is worse — the manager could no longer tell it had
stopped working, and `log:status` would report a healthy writer that writes
nothing.

### Error rendering does not know this layer exists

Nothing in `engine/Error/` mentions a logger. The error handler announces on
`error.reported`; `ErrorLog` is an ordinary listener on that hook, registered
exactly the way a module would register one. **Delete it and errors stop being
logged; nothing else changes** — there is a test that does precisely that and
asserts the 500 still renders.

Two architecture tests hold the line: `engine/Error/` may not mention a logger,
and only `ErrorLog` in `engine/Logging/` may mention an error. A hook has no
compile-time direction, which is why it is the right joint here.

The level comes from the status, not from the exception class:

| Status | Level |
|---|---|
| 5xx, or not an `HttpException` | `error` |
| 429 | `warning` |
| any other 4xx | `notice` |

A 404 is a visitor typing a URL. At error level, a scanner sweeping for
`/wp-admin` pages somebody at three in the morning.

**The log is where a withheld message belongs.** A response must not repeat
`dsn=secret-hunter2`; a log exists so somebody can find out what actually
happened. The two rules are opposites on purpose.

### Levels

The eight RFC 5424 severities, which are also the eight PSR-3 levels. The
specification's list also contains `log`, which is not a severity — it is the
name of the generic method that takes one, and `Logger::log()` is it. A ninth
case with no place in the ordering would make "is this record severe enough to
write" unanswerable.

The backing value is the RFC 5424 code, so comparing severity is comparing
integers. It is **not** what `syslog()` wants: on Windows there is no syslog, so
PHP collapses the eight onto the event log's three record types and `LOG_EMERG`
is 1, not 0. `Level::priority()` is the translation, and a test pins it — a
mismatch would file every record under the wrong severity on the one platform
where nothing would look broken.

### PSR-3

`psr/log` is deliberately not a dependency, and `Logger` deliberately does not
implement `LoggerInterface`. The eight method signatures match PSR-3 exactly;
what differs is `log()`, which takes a `Level` enum where PSR-3 takes a plain
string. The enum is worth more here than the interface is.

An application that must hand a PSR-3 logger to a vendor SDK writes the adapter
once:

```php
final class Psr3Logger extends AbstractLogger
{
    public function __construct(private readonly Logger $log) {}

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->log->log(Level::fromName((string) $level, Level::Info), (string) $message, $context);
    }
}
```

Twelve lines in an application that needs it, rather than a flattened API in
every application that does not.
