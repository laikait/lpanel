# CLI

`php laika <command>` runs a console command. The console boots the same
application HTTP does — same container, same modules, same hooks — so a command
can use everything a page can.

This page covers writing a command, how input is parsed, what the exit codes
mean, and the commands the framework ships with.

## Write a command

A command is an ordinary class. Nothing to extend, nothing to implement:

```php
final class SyncCustomers
{
    public function __construct(private readonly CustomerQuery $customers) {}

    public function __invoke(Output $output, string $since, int $limit, bool $dryRun): int
    {
        $output->line('Syncing since ' . $since);

        return 0;
    }
}
```

Then declare it in `module.php`, next to your routes:

```php
$module->commands(static function (CommandCollector $commands): void {
    $commands->add('customer:sync', SyncCustomers::class)
        ->describe('Pull customer records from the upstream system.')
        ->argument('since', 'Only records changed on or after this date.', required: false, default: 'yesterday')
        ->option('limit', 'Stop after this many records.', shortcut: 'l', default: '25')
        ->flag('dry-run', 'Report what would change without writing anything.', shortcut: 'd');
});
```

| Declares | Looks like on the command line |
|---|---|
| `argument('since', …)` | `php laika customer:sync 2026-01-01` |
| `option('limit', …, shortcut: 'l')` | `--limit=5`, `--limit 5`, `-l 5`, `-l5` |
| `flag('dry-run', …, shortcut: 'd')` | `--dry-run`, `-d`, and it is a `bool` |

Nothing is scanned. A `Commands/` folder is where these classes happen to live,
not how they are found. A file's mere existence should not change what an
application does, and what a module contributes stays readable in one file.

The handler takes the same three forms a route handler does:

```php
$commands->add('customer:sync', SyncCustomers::class);              // invokable class
$commands->add('customer:show', [ShowCustomer::class, 'show']);     // [class, method]
$commands->add('customer:count', static fn (CustomerQuery $c): string => …);  // closure
```

## How input reaches your parameters

```bash
php laika customer:sync 2026-01-01 --dry-run --limit=5
php laika customer:sync 2026-01-01 -dl5      # the same thing
```

Two rules:

- **What you declared arrives by name.** `since`, `limit` and `dry-run` become
  `$since`, `$limit` and `$dryRun`.
- **Anything with a class type arrives from the container, by type.** So a
  command that declares an argument called `output` still gets a real `Output`.

A dash is not legal in a PHP parameter name, so `--dry-run` binds to `$dryRun`.
That rename is mechanical, one-way, and applies only to names containing a dash.

Values are converted the same way route parameters are, so `"1"` means the same
thing on the command line as it does in a URL, and `--times=lots` for an
`int $times` is a usage error printed with the synopsis, not a `TypeError`.

What the parser accepts, and what it refuses:

| Written | Result |
|---|---|
| `--flag`, `--limit=50`, `--limit 50` | declared options |
| `-l 50`, `-l50`, `-abc` | shortcut, attached value, bundled flags |
| `--` | everything after it is positional |
| `--lim` for `--limit` | **refused** |
| `--limit --dry-run` | **refused** |

**Abbreviation is refused** because the abbreviation that is unique today
becomes ambiguous the day somebody adds an option, and a script written against
it breaks at a distance. For the same reason a suggested name is never run in
place of what you typed: a typo gets `Did you mean "customer:sync"?` and exit
127, so a command that deletes something is never reachable by a name its author
did not write.

**`--limit --dry-run` is refused** because reading `--dry-run` as the value would
hide a forgotten argument behind a nonsensical limit, and the run would look
like it worked.

## What to return, and what a shell sees

Return an `int` for the exit code, a `string` to print, or nothing for success.

**A `bool` is refused.** PHP's convention says `true` is success; the shell's
says `0` is. An exit code that gets it backwards turns a failed job into a green
tick.

| Code | Means |
|---|---|
| `0` | it worked |
| `1` | it ran and failed, or something threw |
| `2` | the command line was wrong — missing argument, unknown option |
| `127` | no such command |

The 2-versus-127 split earns its keep: a deployment script that mistypes a
command name and one that forgets an argument are different bugs, and a wrapper
that retries on one should not retry on the other.

Results go to standard output and complaints go to standard error, so
`laika route:list | grep customers` carries no warnings, and
`laika customer:sync 2>errors.log` separates the two.

## Help is generated

There is no second description of the interface to fall out of date:

```
$ php laika customer:sync --help
Usage:
  php laika customer:sync [since] [options]

Arguments:
  since  Only records changed on or after this date. (default: yesterday)

Options:
  -l, --limit=<value>  Stop after this many records. (default: 25)
  -d, --dry-run        Report what would change without writing anything.

Declared by module Example.
```

`php laika help` lists everything; `php laika help <name>` explains one.

## The framework's own commands are not special

Every one of these is registered through the same `CommandCollector` your module
uses, under the module name `engine`. The kernel has no idea they exist: delete
`CoreCommands` and the console still works, with fewer commands.

**Getting your bearings**

| Command | Does |
|---|---|
| `about` | Summarise this application: version, modules, routes, connections. |
| `help` | List the available commands, or explain one of them. |
| `module:list` | Discovered modules, in the order they load. |
| `module:make` | Create `modules/<Name>/module.php`, or `modules/<Group>/<Name>/module.php`. Never overwrites. See [below](#modulemake). |
| `route:list` | Every registered route and its owning module. |
| `openapi:export` | The OpenAPI description of the routes marked with `openapi` meta, to stdout or `--file`; see [OpenAPI](openapi.md). |
| `openapi:docs` | `--enable` or `--disable` the docs page and its description on this machine; no flag shows what is on. |
| `openapi:auth` | `--enable` (optionally `--can=…`) or `--disable` the login the docs page and description need. |
| `asset:list` | Every published asset folder and the URL prefix it answers on. |
| `template:list` | The template search path, highest precedence first. |
| `config:list` | The configuration this process actually resolved to. |
| `system:info` | The operating system, kernel, memory, disk and load of this machine. |

**Databases**

| Command | Does |
|---|---|
| `migrate` | Run every module's pending migrations, in module order, as one batch. |
| `migrate:status` | Every migration, whether it ran, and in which batch. |
| `migrate:rollback` | Undo the last batch of migrations, newest first. |
| `db:seed` | Run every module's seeders, in module order, or one module's. |

**Caches**

| Command | Does |
|---|---|
| `cache:clear` | Delete the configuration, module, template and application caches. |
| `cache:warm` | Build the production boot path: the configuration and discovery caches. |
| `config:cache` | Compile `config/` and the defaults into one cached file. |

**Background work**

| Command | Does |
|---|---|
| `queue:work` | Run queued jobs until told to stop. |
| `queue:status` | What is waiting on each queue, and what has failed. |
| `queue:failed` | The jobs that gave up; retry or discard them. |
| `schedule:run` | Run whatever is due this minute. This is what cron calls. |
| `schedule:list` | Every scheduled task, when it next runs, and what is running now. |
| `schedule:unlock` | Release a schedule lock held after a machine died mid-run. |

**Security and accounts**

| Command | Does |
|---|---|
| `security:check` | Audit what this deployment actually has switched on. |
| `security:key` | Print a new `APP_KEY`. |
| `auth:access` | Every capability, every role, and which routes check them. |
| `auth:hash` | Hash a password, for seeding the first account. |
| `session:gc` | Delete sessions past their lifetime. |

**Running a server**

| Command | Does |
|---|---|
| `nginx:make` | Write the nginx server block for this application to `nginx.conf`. |
| `log:status` | Where records go, and whether they are getting there. |
| `system:cron:install` | Install the crontab line that runs `schedule:run` every minute. |
| `system:cron:list` | The jobs this application owns in the crontab. |
| `system:cron:remove` | Remove this application's crontab jobs. |
| `down` | Maintenance mode: the web gets a 503 page; `--retry`, `--message`, `--allow`, `--secret`. See [Running](../operations/running.md#maintenance-mode). |
| `up` | Leave maintenance mode. |
| `framework:update` | Update this application's copy of the framework to a newer release; `--check`, `--dry-run`, `--from=<zip>`. See [Updating the framework](../operations/updating.md). |
| `framework:rollback` | Undo the last `framework:update` from its backup. |
| `framework:manifest` | Build `framework.json` for a release. Run by the release workflow. |
| `system:systemd:generate` | Print (or `--write`) the systemd units for the queue worker and the scheduler. |
| `system:systemd:install` | Install those units, reload systemd and start them. Root only. |
| `system:systemd:remove` | Stop, disable and delete this application's units. Root only. |
| `system:service:status` | Whether a systemd service is running. |
| `system:service:restart` | Restart a service that `system.services` allows restarting. |

**MCP**

| Command | Does |
|---|---|
| `mcp:list` | Every MCP tool, resource and prompt, its module and who may use it. |
| `mcp:stdio` | Serve MCP over stdin and stdout, as a user, for a local client. |

**Only `module:make` generates code**, and an architecture test pins that list.
`nginx:make` writes a file, but it is web server configuration that nothing in
the framework reads.

A `make:something` command writes a file whose shape the framework then quietly
depends on, and that shape is undocumented because the generator *is* the
documentation. It is how a framework stops being a library you call and becomes
a thing you live inside. So `module:make` writes the `module.php` that
[Modules: Start a new module](modules.md#start-a-new-module) documents line for
line, plus only the starter files you name with `--with`: nothing is created for
later.

### `module:make`

```bash
php laika module:make Billing            # modules/Billing/module.php
php laika module:make Plugins/Billing    # modules/Plugins/Billing/module.php
php laika module:list                    # Billing is there; nothing was registered
```

Creates `module.php`, and nothing else unless `--with` asks (below). Each part of the name must be a capital
letter followed by letters, digits or underscores (`Billing`, `OrderHistory`),
which also rules out `..`, drive letters and anything that is not a name. `/`
and `\` both separate. At most one group level is allowed.

**`--with` adds starter files**, one small working file per part, each the shape
the framework documents for it:

```bash
php laika module:make Billing --with=http,templates
php laika module:make Plugins/Billing --with=database,seeder,model
php laika module:make Billing --with=all
```

| Part | Writes |
|---|---|
| `http` | `Http/Index.php`, an invokable handler, and a `GET /billing` route in `module.php`. With `database` too, the handler lists the `name` of every row in the module's table (and, with `templates`, hands them to the template as `$rows`) |
| `model` | `Model/Billing.php`, extending `Model` |
| `database` | `Database/Migrations/<UTC timestamp>_create_billing_table.php`, a `Reversible` creating `billing` (`id`, `name`, timestamps) |
| `seeder` | `Database/Seeders/billing.php`, adding one row only if it is absent. Needs `database`, whose table it fills |
| `templates` | `Templates/index.php`, found as `@Billing/index`. With `http`, the handler renders it |
| `assets` | `assets/css/billing.css`, served at `/assets/module/Billing/css/billing.css` |
| `lang` | `lang/en.php` |

`all` is every part. Names are derived from the id: `OrderHistory` gives the
table `order_history`, the route `/order-history` and the file `order_history.php`.
An unknown part, or `seeder` without `database`, is refused before anything is
written. In a grouped module the classes are in `App\Modules\Plugins\Billing\…`.
Edit the generated files freely; nothing reads them differently from a file you wrote.

**The module id is the last part.** `Plugins/Billing` is the module `Billing`:
discovery names a module after its own folder, so the id (and `@Billing/…`,
`config/Billing.php`) never includes the group. Ids must be unique across all
`modules.paths` roots, so the command refuses an id that is already installed.
Classes in a grouped module live under `App\Modules\Plugins\Billing\…`, because
that is the folder path Composer maps.

**A grouped module needs its group in `modules.paths`.** The default is
`['modules']`, and discovery only looks at the direct children of each root, so
`modules/Plugins/Billing` loads once `modules/Plugins` is a root too:

```php
// config/modules.php
return ['paths' => ['modules', 'modules/Plugins']];
```

The command creates the module either way, then:

| `config/modules.php` | What it does |
|---|---|
| group already in `modules.paths` | nothing |
| does not exist (and `paths` is the default) | creates it with `['modules', 'modules/Plugins']` |
| exists | leaves it alone and prints the line to add |

It never edits a file you wrote, since that is PHP that may hold comments or
computed values. If you use `config:cache`, rebuild it after the file changes.

It **never overwrites**: if `modules/Billing` exists, as a folder or a file, it
exits 1 and touches nothing. A group folder that differs only by case from an
existing one is refused too, because `plugins` and `Plugins` are one directory on
Windows and macOS and two on Linux. If the write fails, what the run created is
removed and it exits 1.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| `Did you mean …?` and exit 127 | The command name is wrong; nothing was run | Type the full name |
| Exit 2 with a synopsis | A missing argument, an unknown option, or a value of the wrong type | The message names it |
| `--lim` is not accepted | Abbreviations are refused on purpose | Write the option in full |
| A new command is missing | A module cache from `cache:warm` is in use | `php laika cache:clear` |
| An error says only "Set APP_DEBUG=1…" | The message was withheld, as it is on the web | Rerun with `APP_DEBUG=1 php laika …` |

## Why it works this way

### No `Command` base class

An architecture test asserts that nothing under `engine/Cli/` is abstract, is an
interface, or has a protected member — because a base class is how a command
stops being an ordinary object. It arrives with `$this->argument()`, then
`$this->output`, then `$this->info()`, and after that a module's command can
only be written one way.

`ConsoleKernel` and `HttpKernel` read as mirror images because they do the same
job: look a name up in a registry, parse input against what was declared, call a
handler through the container, and turn the result into something the caller
understands.

### Declarations, not a signature string

`"{user : the id} {--queue=}"` is a small language embedded in a docblock:
invisible to static analysis, and checked only when somebody runs it. A method
call per argument is longer to write, is checked by your editor as you write it,
and generates the help from the same declaration.

### Errors on a terminal

`command.matched`, `command.finished` and `command.failed` are the CLI's
lifecycle hooks. There is deliberately **no filter over parsed input**: a filter
carries a value so a module can change it, and a module silently rewriting
another module's arguments is worse than the flexibility is worth.

An exception's message obeys the same rule the web does — an `HttpException`
message is written by this framework and survives, anything else is replaced
outside debug mode. The console was the one place that did not, and a
`DatabaseException` carrying `dsn=…` into a cron log is exactly what that rule
exists to prevent.

What the console adds is a way forward: when a message is withheld it says
`Set APP_DEBUG=1 for the full message and a stack trace`, because the person
reading a console error is the person who can turn debug on.
