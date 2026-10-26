# System operations

`engine/System` is how an application changes the machine it runs on: running
programs, starting background processes, the crontab, systemd services, files
outside the application, permissions, and reading what the machine is.

```php
$result = $executor->run(new Command('rsync', ['-a', '--', $from, $to], timeout: 600.0));

$services->restart('nginx');                                   // if system.services allows it
$cron->install(ScheduleRunJob::forApplication('/srv/app'));    // the one crontab line
$files->write('/srv/backups/2026-09/manifest.json', $json);    // inside an allowed root
```

It is Linux-first, and every part of it is **deny by default, audited, and never
a shell unless you asked for one by name**.

**It is not an interface.** Nothing here reads a request, a console argument or
a session; it does not know who is asking. **The people and programs that ask
are handled by your module**, which authorizes them and then calls these
managers — see [Who may ask](#who-may-ask).

```text
System  ≠ CLI       the console is one administrative interface to it
        ≠ MCP       MCP tools are another; neither is System
        ≠ Module    a module owns WHAT (make a backup); System owns HOW (run tar)
        ≠ Scheduler the scheduler decides what runs when; System installs the one cron line
        ≠ Queue     long work is a queued job whose handler uses System
```

Everything below is **Experimental**; see [`STABILITY.md`](../../STABILITY.md).

## Running a command

```php
public function __construct(private readonly CommandExecutor $executor) {}

$result = $this->executor->run(new Command('/usr/bin/tar', ['-czf', $archive, '--', $directory], timeout: 900.0));

$result->successful();   // exit code 0
$result->exitCode();     // 0-255, or null when a signal or the timeout ended it
$result->stdout();       // up to the output limit
$result->timedOut();
$result->truncated();
$result->orFail();       // the result, or CommandFailedException / CommandTimeoutException
```

**The executable and each argument stay apart all the way to the operating
system.** Nothing is joined into a command line or read by a shell, so an
argument holding `; rm -rf /`, `$(…)` or a quote is one argument meaning exactly
what it says.

The executable is a bare name found on `PATH`, or an absolute path. `sh`,
`bash`, `cmd` and `powershell` are refused as executables.

A value that looks like an option is still an option to the program: **put `--`
before values you did not write.**

**Every command has a timeout and an output limit** — its own, or the configured
defaults (60 s, 1 MiB per stream). There is no way to ask for none. A timed-out
process is sent SIGTERM, then SIGKILL a second later; its output so far is kept.

**Its environment is small.** A command that names no environment gets only
`PATH`, `HOME`, `LANG`, `LC_ALL`, `TZ` and `TMPDIR` — never `APP_KEY`,
`DB_PASSWORD` or anything else this PHP process holds. Pass what a program needs
in `environment:`; a `Secret` is revealed only when the process starts.

**Nothing is thrown for a command that ran and failed**: its output is the
explanation, so it is a result. `CommandNotFoundException` means nothing ran.

## Shell scripts

```php
$executor->run(ShellCommand::bash('/srv/app/scripts/backup.sh', [$database, $target]));
```

Off by default (`system.shell.enabled`).

A script is a file. Its arguments reach it as `$1`, `$2`, … and are never pasted
into source, so they are safe **if the script quotes them** (`"$1"`).

There is no way to run inline shell source: a pipeline belongs in a script,
where it is reviewed as code. bash starts with `--noprofile --norc`, and
`BASH_ENV`, `ENV`, `SHELLOPTS` and exported functions are refused in its
environment.

## Background processes

```php
$process = $processes->start(new Command('ffmpeg', [...], timeout: 300.0));
$process->isRunning();
$process->terminate();
$result = $process->wait();     // the same CommandResult
```

The timeout is enforced whenever the process is looked at. **Dropping a running
`Process` terminates it**, so nothing is left behind by a request that forgot
it.

This is not a supervisor: work that must outlive its caller belongs on the
[queue](queue.md).

## Cron

```bash
php laika system:cron:install     # schedule:run, every minute, on this host
php laika system:cron:list
php laika system:cron:remove
```

The application's jobs live in one marked block of the user's crontab, named for
this application. Every other line — somebody's own jobs, `MAILTO`, another
application's block — is left byte for byte. A block that was edited by hand, or
is not exactly what the framework wrote, is refused rather than rewritten.

What runs and when is the [scheduler](scheduler.md)'s business; the crontab gets
one line. A `CronJob` holds a structured command, and its line is quoted for `sh`
and for cron's own `%` handling — never taken as a string.

### Or systemd

```bash
php laika system:systemd:generate    # the worker and scheduler units; changes nothing
php laika system:systemd:install     # root: write them, daemon-reload, enable --now
php laika system:systemd:remove      # root: disable --now, delete, daemon-reload
```

`SystemdUnits` builds the three units — a `<name>-worker@.service` template whose
instance is the queue, and a `<name>-scheduler.service` started every minute by
`<name>-scheduler.timer` — named after the crontab owner, so two checkouts on one
machine never share units. `SystemdManager` writes them into
`/etc/systemd/system` and runs `systemctl` through the `CommandExecutor`, so the
command policy and the audit log apply (`system.systemd.installed`,
`system.systemd.removed`). It refuses without root and where systemd is not
running, and it refuses to start the timer while this application's cron line is
installed, since both would run every task. The details are in
[Running](../operations/running.md#queue-workers).

## Services

```php
$services->status('nginx')->isActive();
$services->reload('nginx');
```

systemd only, `.service` units only (`poweroff.target` is refused).

Reading a status needs nothing; **every change needs `system.services` to list
that action on that service**. Privilege itself is the operating system's: root,
or a polkit rule for the PHP user. `ServiceNotFoundException` means systemd has
no such unit.

## Files and permissions

```php
$files->write($path, $contents);            // atomic; refuses to overwrite unless overwrite: true
$files->delete($path, recursive: true);     // never follows a link
$permissions->chmod($path, 0o640);
```

Every path must be **absolute, contain no `..`, and resolve — following symbolic
links — inside a root** from `system.filesystem.read` or `.write`. A link inside
a root that points out of it is refused. `/` cannot be a root.

Modes are 0–0777, with no setuid, setgid, sticky or world-writable bits.
Ownership goes only to the users and groups in `system.permissions`.

## System information

```php
$info = new SystemInfo();
$info->os(); $info->memory()?->available; $info->loadAverage(); $info->disk('/srv');
```

Read from PHP and `/proc`; nothing is run. `null` where the machine does not
say. `php laika system:info` prints it.

## Who may ask

```php
$module->access(static function (AccessCollector $access): void {
    SystemCapability::declare($access, SystemCapability::ServiceRead, SystemCapability::ServiceRestart);
    $access->role('operator', [SystemCapability::ServiceRestart->value]);
});

$this->system->authorize($identity, SystemCapability::ServiceRestart, 'nginx.service');
$this->services->restart('nginx');
```

`system.*` capabilities are ordinary [capabilities](auth.md): declared by the
module that checks them, granted by roles, listed by `auth:access`. A module's
`authorization.decision` listener receives a `SystemOperation` and can refuse by
target — "may restart nginx, not php-fpm" — and cannot grant.

**Two questions, both required.** The capability says *this person may*; the
configuration says *the application will*. Holding `system.service.restart` does
not let anyone restart a service `system.services` does not list.

**Grant system capabilities one at a time.** `system.*` and `*` include
`system.shell.execute`.

The console asks for no identity — its user has a shell — but the configured
policies and the audit apply to it exactly as to a module.

**Over [MCP](mcp.md)**, a system operation is a tool that names a `system.*`
capability as its permission and calls your module's service. That service then
authorizes the exact target, as above. Clients without the capability never see
the tool, and the service policies still decide what actually runs:

```php
$mcp->tool('server.service.restart', ServiceRestartTool::class, 'Restart an allowed service.', permission: SystemCapability::ServiceRestart->value);
```

Offer named operations, never `server.execute` or `server.shell`. For an
operation that changes the machine, ask the client to confirm in the call
itself — for example with a `confirm` argument whose schema is `{"const": true}`.

## Audit

Every change and every refusal is fired on the `system.audit` hook, and written
to the **`audit` log channel**: info (done), error (failed), warning (refused).

Authorization records name the principal, and the log's request and correlation
ids tie them to the operations that followed, across the queue.

**Records never contain** an argument's value, an environment value, stdout,
stderr or file contents. `system.audit.enabled: false` detaches the log, not the
hook.

## Settings

```php
// config/system.php
return [
    'execution' => ['default_timeout' => 60, 'max_output' => 1048576, 'max_concurrent' => 16, 'http_timeout' => 10],
    'shell' => ['enabled' => false, 'binary' => 'bash'],
    'commands' => ['allowed' => null, 'scripts' => null],
    'services' => ['nginx' => ['reload', 'restart']],
    'filesystem' => ['read' => [], 'write' => ['/srv/backups']],
    'permissions' => ['owners' => [], 'groups' => []],
    'cron' => ['enabled' => true, 'owner' => null],
    'audit' => ['enabled' => true],
];
```

| Setting | Default | Means |
|---|---|---|
| `commands.allowed`, `.scripts` | `null` | a list, **even an empty one**, is an allowlist of exact paths |
| `execution.max_concurrent` | 16 | commands running at once across all PHP processes; past it, refused |
| `execution.http_timeout` | 10 | no command inside a web request runs longer; the console and workers are not capped |
| `enabled` | `true` | `false` makes every system manager refuse to be built |

Every value is checked the first time a manager is built (`http_timeout` at
boot, since it applies to every web request), and a wrong one is a
`ConfigurationException` naming its key.

The injected managers carry all of it. A module needing richer allowlist rules —
argument patterns, folders — builds a `CommandPolicy` in code.

Two filters may **narrow** limits and never widen them:
`system.command.timeout` and `system.command.max_output`.

## Security

**Trusted and untrusted input.** The executable, the script, the allowlist and
the policies are the application's own code and configuration: trusted.
Arguments, file names, service names and paths that came from a user are
untrusted, and the framework treats them as data — separate arguments, quoted
crontab parts, validated unit names, resolved and contained paths. What it
cannot do is know what an argument *means* to the program receiving it.

**Shell risks.** A shell reads its input as code. `sh -c` cannot be reached
through `Command`; `ShellCommand` runs only script files, only when enabled,
with values as positional parameters. Keep scripts short, quote every parameter,
and list each script in `commands.scripts`.

**In production:**

- Set `commands.allowed` and `commands.scripts` to exactly what the application
  runs. Without an allowlist, anything on `PATH` can run.
- Leave `shell.enabled` off unless a script is genuinely needed.
- Give `filesystem.write` folders only the application writes to. A root another
  account can write to can have a folder swapped for a link between the check
  and the operation.
- Run PHP as an unprivileged user, and grant exactly the privileges needed — a
  polkit rule for one service's restart rather than root.
- Grant `system.*` capabilities individually, to few roles.
- Keep the `audit` channel somewhere retained, and alert on its warnings.
- Queue anything that can take longer than `http_timeout`.

Linux-first: services, cron, signals and permissions refuse on Windows rather
than pretend. Commands and processes work there, for development.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| A manager refuses to be built | `system.enabled` is false | Switch it on for this application |
| A command is refused | It is not in `commands.allowed`, or it is a shell | Add its exact path; use a script for a pipeline |
| A service change is refused | `system.services` does not list that action | Add it — the capability alone is not enough |
| A path is refused | It is relative, contains `..`, or resolves outside a root | Use an absolute path inside `filesystem.write` |
| A command dies after 10 seconds in a request | `execution.http_timeout` caps web requests | Run it as a queued job |
| Nothing works on Windows | Services, cron and permissions are Linux-only | Use Linux for those; commands still run |
| An audit record is missing detail | Values, output and file contents are never recorded | By design |
