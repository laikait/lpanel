# Running in production

For whoever deploys the application and looks after it afterwards:

1. what has to be running,
2. what to do before the first deployment,
3. what to run on every deployment,
4. cron and queue workers,
5. what lives in `system/`, and what to back up,
6. running on more than one machine,
7. how to watch it, and what to do when something is stuck.

Setting up the web server is in [Deployment and security](deployment.md). Every
environment variable, with its default, is listed in
[`.env.example`](../../.env.example).

## What runs

| Process | How to run it | Needed when |
|---|---|---|
| PHP behind a web server | Apache with `.htaccess`, or nginx with PHP-FPM | always |
| `php laika schedule:run` | from cron, every minute, on **one** machine | any module has a schedule (`php laika schedule:list` is not empty) |
| `php laika queue:work` | under something that restarts it | `QUEUE_STORE` is `file` or `database` |

Nothing else runs in the background. There is no daemon to install and no port
to open besides the web server's.

## Before the first deployment

1. **PHP 8.2 or newer**, with the `json`, `mbstring` and `pdo` extensions, plus
   the PDO driver for your database, and `fileinfo` if the application accepts
   uploads.

   **Turn opcache on.** Without it every request spends 45–60 ms compiling PHP.

2. **Connect your user accounts, if the site has logins.** A fresh installation
   has none: nobody can log in, and every protected page refuses everybody. See
   [Users and permissions](../guides/users-and-permissions.md).

3. **Set the environment.** Use real environment variables, not a `.env` file, so
   that secrets are not sitting in the project folder:

   | Variable | Why |
   |---|---|
   | `APP_ENV=production`, `APP_DEBUG=false` | debug shows stack traces, paths and settings to anyone who triggers an error |
   | `APP_KEY` | get one with `php laika security:key --bare`, and keep it like a password. Changing it makes every open form's CSRF token invalid |
   | `APP_TIMEZONE` | set it; do not rely on what `php.ini` happens to say |
   | `MEMORY_LIMIT` | PHP's `memory_limit`, e.g. `256M`, for the same reason; queue workers stop at 80% of it |
   | `MAX_EXECUTION_TIME` | seconds a web request may run, e.g. `30`; the console and workers are not affected |
   | `DB_DSN`, `DB_USERNAME`, `DB_PASSWORD` | your database; or `config/database.php` for several |
   | `SESSION_STORE`, `CACHE_STORE`, `QUEUE_STORE` | see [More than one host](#more-than-one-host) |
   | `SESSION_ABSOLUTE` | a maximum session length, in seconds; there is none by default |

4. **Create the database tables:**

   ```bash
   php laika migrate --pretend --connection=<name>   # show the SQL, run nothing
   php laika migrate --connection=<name>
   ```

   `--connection` lets you use an account that is allowed to create tables. The
   account the application itself uses should not be allowed to.

   Run `migrate` on every deployment. It does nothing when nothing is pending,
   and only one deployment can run it at a time. Sessions, the cache, the queue
   and the log get their tables here too, when they are set to `database`.

5. **Decide where log records go.** Nothing is written by default. For daily
   files under `system/Logs`, create `config/logging.php`:

   ```php
   <?php

   return [
       'writers' => ['file'],
       'level' => 'info',
       'file' => ['prefix' => 'app', 'retention_days' => 30],
   ];
   ```

   | Writer | Suits |
   |---|---|
   | `file` | one machine; daily files, deleted after `retention_days` |
   | `stderr` | containers, where the platform collects output |
   | `syslog` | a machine that already ships its system log somewhere |
   | `database` | several machines sharing a database — always **alongside** one of the others, since a log in the database cannot record the database failing |

   `database` needs `php laika migrate` once, for its table.

6. **Make `system/` writable** by the user PHP runs as, and nothing else in the
   project.

## Every deployment

```bash
php laika down --retry=60          # optional: the web gets a 503 page meanwhile
composer install --no-dev --optimize-autoloader
php laika cache:clear
php laika cache:warm
php laika migrate
php laika security:check
php laika up
```

- **`cache:warm`** prepares the configuration and module list, so a request reads
  two ready-made files instead of searching folders. It refuses to run with debug
  on.
- **Always `cache:clear` before `cache:warm`.** Adding or removing a module, or
  editing anything in `config/`, is **not** noticed on its own. Changing an
  environment variable is.
- **`security:check`** exits with 1 on a real problem in production, so it can
  stop a deployment: debug on, no `APP_KEY`, rate-limit counters in memory, the
  HTTP MCP endpoint with neither `APP_URL` nor `trusted_hosts`, or the API docs
  open to anyone. Warnings, such as a route that changes data and requires
  nobody, are printed without stopping it. Read them.
- **Restart the queue workers**, so they run the new code. (`--max-time` makes
  them restart by themselves eventually, but not immediately.)
- **Read [`UPGRADING.md`](../../UPGRADING.md)** when the framework itself changed.
  Updating the framework is done before deploying, on a development copy —
  see [Updating the framework](updating.md).
- **Never run `composer dump-autoload --classmap-authoritative`.** Modules added
  afterwards would not load.

Then run the `curl` checks in [Deployment and security](deployment.md) once more,
to confirm the web server still serves no source files.

## Maintenance mode

```bash
php laika down --retry=120 --message="Back at 14:00 UTC" --allow=203.0.113.7,10.0.0.0/8 --secret=preview-token-2026-10
php laika up
```

While down, every web request gets **503** with `Retry-After` and the
`errors/503` page (the `--message` is shown on it), so search engines and
clients know to come back rather than treating the site as gone. Assets are
still served, so the page keeps its stylesheet.

Who still gets in:

- **`--allow`**: addresses and CIDR blocks. It is the client address
  `Request::ip()` reports, so it honours `http.trusted_proxies` and cannot be
  claimed with a forged `X-Forwarded-For`.
- **`--secret`**: visiting `/?lphp_bypass=<secret>` once redirects to the same
  page without it and sets a cookie that lets that browser in until `up`. The
  cookie is not the secret, and a later `down` needs a new visit.

**Not affected:** the console, queue workers and the scheduler. Stop the workers
yourself (`systemctl stop <name>-worker@default`) if a migration needs the queue
quiet. The state is a file in `system/Runtime/`, per machine: run `down` and `up`
on each web server. `security:check` warns while the application is down.

## Cron

Cron runs the scheduler once a minute; the scheduler decides what is due:

```cron
* * * * *  cd /srv/app && php laika schedule:run >> /dev/null 2>&1
```

Instead of editing the crontab by hand:

```bash
php laika system:cron:install   # add that line for the current user
php laika system:cron:list      # show what this application installed
php laika system:cron:remove    # take it out again
```

`system:cron:install` writes inside a block marked with this application's name
and leaves every other line alone, so it is safe to run on every deployment: an
unchanged line is not rewritten.

**One line, one machine**, however many modules declare schedules. The locks that
stop a task running twice are files on that machine, so a second machine would
run everything again. `schedule:run` exits with 1 if a task failed, which a cron
monitor can alert on; each task's output goes to the log.

### Or a systemd timer

On a machine with systemd, a timer can run `schedule:run` instead of cron — and
the same command installs the queue workers, below:

```bash
sudo php laika system:systemd:install --user=www-data --queue=default,billing
```

**Use cron or the timer, never both**: each runs every task, so with both every
task runs twice. `system:systemd:install` refuses while this application's cron
line is installed; `--replace-cron` removes the line first, and `--no-scheduler`
installs only the workers and leaves scheduling to cron. The one-machine rule is
the same as for cron.

## Queue workers

A worker is meant to exit, so run it under something that starts it again. On
systemd the framework writes the units for you:

```bash
php laika system:systemd:generate --user=www-data --queue=default,billing   # print them; changes nothing
sudo php laika system:systemd:install --user=www-data --queue=default,billing
sudo php laika system:systemd:remove                                          # stop, disable, delete
```

That installs three files in `/etc/systemd/system`, named after this application
(the same name its crontab block has, `system.cron.owner`):

| Unit | Does |
|---|---|
| `<name>-worker@.service` | one worker per queue: `<name>-worker@default`, `<name>-worker@billing` |
| `<name>-scheduler.service` | runs `schedule:run` once |
| `<name>-scheduler.timer` | starts it every minute |

```ini
# <name>-worker@.service, abridged
[Service]
User=www-data
WorkingDirectory=/srv/app
ExecStart=/usr/bin/php /srv/app/laika queue:work --queue=%i --max-jobs=1000 --max-time=3600
Restart=always
```

- Another queue later is `systemctl enable --now <name>-worker@reports`; nothing
  is regenerated.
- `install` is safe on every deployment: unchanged files are left alone. It needs
  root; without it, `generate --write=DIR` produces the files for you to copy.
- `--user` defaults to the owner of the application directory, and a worker is
  never made to run as root by default.
- `systemctl stop` ends a worker at once — it has no signal handling — so a job
  it was running runs again when its reservation expires (`QUEUE_TIMEOUT`).
  Nothing is lost; it is the same at-least-once rule as a crash.
- Check them with `systemctl list-units '<name>-*'` and
  `journalctl -u <name>-worker@default`.

Anything else that restarts a process — supervisor, a container's restart
policy — works as well; run `php laika queue:work` with the same options.

- `--max-jobs` and `--max-time` make the worker stop regularly, which keeps
  memory in check and makes sure a deployment's new code reaches every worker.
  It also stops, between jobs, at 80% of `MEMORY_LIMIT` (or `--memory=`), so it
  is restarted cleanly rather than killed mid-job.
- With `QUEUE_STORE=file`, any number of workers on **one** machine is fine.
  With `database`, workers on any number of machines share the same queue.
- A worker exits with 1 when it gave up on a job.

| Variable | Means |
|---|---|
| `QUEUE_TRIES` | how many attempts before a job is moved to the failed list (3 by default) |
| `QUEUE_TIMEOUT` | how many seconds a job may be held; after that, a crashed worker's job is run again |

## Housekeeping

Declare these in a module rather than adding more cron lines:

```php
$schedules->command('session:gc')->hourly();
$schedules->command('cache:clear', '--expired')->dailyAt('04:00');
```

- **`session:gc`** deletes sessions past their lifetime, for the `file` and
  `database` session stores. Expired sessions are already refused; this reclaims
  the space.
- **`cache:clear --expired`** removes only entries that have expired, and leaves
  the rest in place.

## What is in `system/`

| Folder | Holds | Back up? |
|---|---|---|
| `system/Logs` | log files | as your log policy says |
| `system/Sessions` | live sessions — **each file lets someone in** | no; losing them just logs people out |
| `system/Queue` | jobs waiting, and `failed/` | yes, if unfinished work matters |
| `system/Cache` | prepared configuration, module list, cached values | no; `cache:warm` rebuilds it |
| `system/Security` | rate-limit counters | no |
| `system/Schedule` | locks held by running tasks | no |
| `system/Runtime` | tool caches | no |

None of it is reachable from the web, and none of it belongs in git.

## More than one host

Five things keep their state in files on one machine, and become wrong as soon
as a second machine serves the same site:

| Setting | Change to | Otherwise |
|---|---|---|
| `SESSION_STORE=file` | `database` | a visitor sent to the other machine is logged out |
| `security.counters` `file` | nothing yet — no shared counter store is built | each rate limit counts per machine |
| `CACHE_STORE=file` | `database` | each machine caches, and clears, on its own |
| `QUEUE_STORE=file` | `database` | each machine's workers see only that machine's jobs |
| `logging.writers` `file` | add `database`, or ship the file with `syslog` | each machine's log stays on that machine |

`php laika migrate` creates the tables for whichever of these you set to
`database`. And, as above: `schedule:run` on exactly one machine.

## Watching it

- **`GET /health`** is for a load balancer or an uptime monitor. It answers
  **200** when this instance can serve and **503** when it cannot, as JSON:

  ```json
  {"status":"ok","checks":{"database":{"status":"ok"},"cache":{"status":"ok"},
   "queue":{"status":"ok","pending":{"default":3}},"disk":{"status":"ok"},
   "mail":{"status":"ok"},"storage":{"status":"ok"},"maintenance":{"status":"ok"}}}
  ```

  | Check | Fails when |
  |---|---|
  | `database` | the default connection cannot run `SELECT 1` (skipped with no database) |
  | `cache` | a value written to the cache does not come back |
  | `queue` | the queue store cannot be read. A backlog is reported; over `Shared.health.queue_backlog` (1000) it is `warn`, never a failure (skipped for `sync`) |
  | `disk` | `system/` is not writable, or has less than 50 MB free |
  | `mail` | the SMTP server does not answer a greeting, EHLO and STARTTLS (no login, no message), or sendmail is not executable (skipped for `log` and `array`) |
  | `storage` | a file written to a disk does not come back: the default disk, or those in `Shared.health.disks` |
  | `maintenance` | never. `warn` while `php laika down` is on, which only an allowed address can see |

  A check is `ok`, `warn`, `fail` or `skipped`, and only `fail` makes the answer
  503. A load balancer acts on the code, and a person reads `warn`.

  **Mail and storage are checked at most every five minutes**
  (`Shared.health.cache_seconds`), with the answer reused in between, because a
  load balancer asks every few seconds and neither an SMTP relay nor an S3 bill
  should hear about each one. Reusing the answer takes a cache store shared
  between requests (`file` or `database`). Change these settings in
  `config/Shared.php`.

  It names checks and states only, never a DSN, a path or an error message.
  With `APP_DEBUG=true` a `details` field says why a check failed. The response
  itself is never cached.

  **To add a check**, a module listens to the `health.checks` filter. It
  receives name => closure, and each closure returns `['status' => …]`:

  ```php
  $module->filter('health.checks', static fn(array $checks): array => [
      ...$checks,
      'payments' => static fn(): array => ['status' => Gateway::reachable() ? 'ok' : 'fail'],
  ]);
  ```

  To answer differently altogether, declare `GET /health` in your own module:
  the route declared last wins, as for `/`.
- **`X-Request-Id`** is on every response. When a user quotes it, you can find
  every log record for that request. The same id follows the work into the queue
  as `correlation_id`.
- **`php laika about`** — version, whether the caches are in use, modules,
  connections, what is switched on.
- **`php laika log:status --write`** — sends a real record through the real
  writers, and reports any writer that has stopped after an error.
- **`SLOW_QUERY_MS=250`** logs every database statement slower than 250 ms. The
  statement is logged; the values in it never are.
- **`APP_PROFILE=true`**, for a short while, records where each request's time
  went, to the `profile` log channel. Switch it off again.

See [Observability](../reference/observability.md).

## When something is stuck

| Symptom | What to do |
|---|---|
| A scheduled task has not run since a crash | `php laika schedule:list` shows it as running; release it with `schedule:unlock --id=<id>` |
| Jobs are piling up | `php laika queue:status`; check a worker is running for that queue |
| Jobs are failing | `php laika queue:failed` shows why; fix the cause, then `queue:failed --retry-all` |
| A configuration change does nothing | `php laika cache:clear`, then `cache:warm` |
| A new module is missing | `cache:clear`, then `cache:warm`; on Linux, check the folder's upper and lower case |
| Every request is a 500 after deploying | on the host, `APP_DEBUG=1 php laika about` shows the boot error. Never switch debug on for the web server |

More in [Troubleshooting](../troubleshooting.md).
