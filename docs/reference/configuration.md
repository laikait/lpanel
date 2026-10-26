# Configuration

Settings live in PHP files under `config/`, and the values in them usually come
from environment variables. This page covers how to read a setting, where
settings come from, and what the configuration cache does and does not notice.

## Read a setting

Ask for a `Config` in your constructor:

```php
final class SendInvoice
{
    public function __construct(private readonly Config $config) {}

    public function __invoke(): void
    {
        $from = $this->config->string('billing.sender', 'billing@example.com');
        $size = $this->config->int('Example.page_size', 25);
    }
}
```

A key is `<file>.<key>` — `billing.sender` is `'sender'` in `config/billing.php`.
The second argument is the default, used when the key is absent.

```php
$config->string('app.timezone', 'UTC');   // ?string
$config->int('logging.file.retention_days', 0);
$config->bool('app.debug');
$config->float('billing.rate');
$config->array('database.connections');
$config->strings('logging.writers');      // list<string>
```

**These do not convert.** A key that is present but of the wrong type throws,
naming the key, the type wanted, and what was there instead.

That is deliberate. Write `'retention_days' => '30'` in a file, let a helpful
fallback absorb it, and it silently becomes `0` — which means keep every log
forever, and nobody finds out for a year. It is a mistake in a file somebody has
open right now, and right now is the cheapest moment to mention it.

Text is legitimate in exactly one place, the environment, and that is where the
parsing lives.

## Where settings come from

Every key, every environment variable and its default is listed in
[Default settings](defaults.md).

Four sources, each overriding the one before it:

| Source | Holds |
|---|---|
| `Bootstrap::defaults()` | every key the framework reads, with a working value |
| `config/*.php` | what this installation decided |
| whatever `Bootstrap::create()` is handed | an embedding application, or a test |
| a module's `config()` declaration | **defaults only** — see [Configuring a module](#configuring-a-module) |

**The filename is the namespace.** `config/database.php` lands under
`database`, so the file you open to change a connection is the one named after
it. Nothing has to be registered, and there is no lookup table to keep in step.

**Nothing in `config/` is required.** An application with no such folder runs on
the defaults and the environment.

The environment is **not** a fifth layer. Config files read it themselves, so
the order is visible in the file you have open rather than hidden in a merge
somewhere else:

```php
// config/database.php
return [
    'default' => Env::string('DB_CONNECTION', 'default'),
    'connections' => [
        'default' => [
            'dsn' => Env::string('DB_DSN', 'sqlite::memory:'),
            'username' => Env::string('DB_USERNAME'),
            'password' => Env::string('DB_PASSWORD'),
        ],
    ],
];
```

**Why PHP files** rather than YAML, JSON or INI? PHP reads the file, opcache
already caches it, a typo is a parse error on the line where it happened, and
your editor can complete the constants it names. A format that needs a parser
buys you a parser.

## Configuring a module

A module's id is a path, and a subfolder in `config/` matches it:

```php
// config/Example.php — the module at modules/Example
return ['page_size' => 10];
```

```php
// modules/Example/module.php
$module->config(['page_size' => 25]);
```

**The file wins**, and that direction is the point. A module's `config()` is a
set of *defaults*, written by whoever wrote the module. The file is the
*decision*, made by whoever runs the installation.

Modules register long after `config/` has been read, so merging their values the
ordinary way would have every module quietly overwrite whatever the application
had configured for it. The symptom of getting this backwards is a config file
that appears to do nothing at all — which is why `Config::defaults()` is a
separate method from `merge()`, with a test holding each direction.

Only the keys your file names are overridden. The rest of the module's defaults
stay as they are, so your file never has to be kept in step with the module's.

## Environment variables

`Env` is the only class in the framework that reads the environment. An
architecture test enforces that, and a second forbids `putenv()` anywhere,
because it is not thread-safe and this is a ZTS build.

```php
Env::string('APP_ENV', 'production');
Env::bool('APP_DEBUG', false);   // true/false, yes/no, on/off, 1/0
Env::int('LOG_RETENTION_DAYS', 30);
Env::list('TRUSTED_PROXIES');    // comma separated
```

Three things to know:

1. **`"false"` is `false` here.** To PHP, `"false"` is a non-empty string and
   therefore true, which is the single most expensive gotcha in this area.
2. **An empty variable counts as absent**, because `APP_ENV=` is a variable
   somebody meant to fill in.
3. **A bad value stops the boot**, naming the variable. A boolean that reads
   `maybe`, or a number that reads `30 days`, is not guessed at.

Every variable the engine reads is listed in
[`.env.example`](../../.env.example), and a test fails if one is added without
being documented there.

### `.env` fills gaps; it does not override

A `.env` file is loaded if there is one, for machines with no real environment
to speak of — a laptop, a CI container.

**A real environment variable always wins.** That is the opposite of the usual
"last loader wins", and it is what makes loading `.env` unconditionally safe: a
`.env` left behind on a server cannot override what the deployment set. Values
go into `$_ENV` only.

There is no variable interpolation: `${OTHER}` stays the six characters it looks
like. Interpolation turns a flat list of settings into a small programming
language, and its first question — does it see the real environment or the
file — has no good answer.

A line that is not an assignment stops the boot rather than being skipped. A
setting that silently fails to apply is worse than a boot that stops.

**A test run reads `.env.testing`, never `.env`.** When the real environment
says `APP_ENV=testing` — `phpunit.xml` does — `.env.testing` is loaded instead,
and a missing one means nothing is. A developer's `.env` names their database
and turns debug on; a suite that read it would pass or fail depending on whose
laptop it ran on. A test that needs a database server still asks for one with
the `DB_TEST_*` variables.

## Memory limit

`MEMORY_LIMIT` sets PHP's `memory_limit` when the application boots, so a laptop
and a server agree instead of each using whatever its `php.ini` says:

```bash
MEMORY_LIMIT=256M
```

```php
// or config/app.php
return ['memory_limit' => '512M'];   // -1 (as an int or a string) means no limit
```

- The value is PHP's own notation: `256M`, `1G`, `134217728`, `-1`. Unset leaves
  `php.ini` alone.
- A value PHP would not understand (`lots`, `256MB`, `1.5G`) **stops the boot**,
  naming it, instead of being passed to `ini_set()` and quietly ignored.
- So does a limit below what the process already uses: the next allocation would
  be a fatal error with no room left to report it.

Queue workers use it too: with no `--memory` or `QUEUE_MAX_MEMORY`, a worker
stops between jobs at 80% of this limit. See
[Running a worker](queue.md#running-a-worker).

## Time limit

`MAX_EXECUTION_TIME` is how long a **web request** may run, in seconds; `0`
means no limit. Unset leaves `php.ini`'s `max_execution_time`.

```bash
MAX_EXECUTION_TIME=30
```

- Web requests only. Console commands keep PHP's own "no limit", which is what
  a migration or a long report needs, and a queue worker limits each job with
  `QUEUE_TIMEOUT`.
- A negative number stops the boot, and so does a host that has disabled
  `set_time_limit()` — a limit you asked for that PHP does not apply is worse
  than one you were told about.

## The configuration cache

```bash
php laika config:cache          # compile config/ and the defaults into one file
php laika config:cache --clear  # or cache:clear, which clears every cache
php laika config:list --sources # what resolved, and where it came from
php laika cache:warm            # this cache and the module cache, in one step
```

The cache is one `var_export`ed array in `system/Cache/config.php`, which
opcache already holds. **Once the file exists it is used** — there is no setting
to switch it on, because that setting would have to be read out of the
configuration this is building.

### It notices a changed environment variable

The well-known failure of cached configuration is that config files read
environment variables, the cache freezes those values, and changing a variable
afterwards does nothing at all — silently, with no error and no clue.

Because every read goes through `Env`, `Env` records what it was asked and what
it answered, and that record goes into the file. On load, those variables are
compared against the environment as it is now. One difference makes the cache
stale, and it is ignored:

```bash
php laika config:cache                      # built with APP_DEBUG unset
APP_DEBUG=1 php laika config:list -p app    # app.debug true: the cache noticed
```

### It does not notice an edited file

Noticing would mean checking every file on every request, which is the work the
cache exists to avoid. A deployment that changes configuration is a deployment,
and a deployment runs `cache:clear`.

The environment is different: it changes without any file changing, and checking
it costs a few dozen string comparisons.

Anything that is not plain data — a closure, an object — is refused when the
cache is written, by name, rather than being written happily and failing as a
fatal error inside a generated file on the way back in. A test asserts that the
framework's own shipped configuration can always be cached, so nobody discovers
otherwise while running `config:cache` on a production machine.

## `config/` is never web-readable

It holds your database credentials, and it sits outside `public/`, which is the
only folder any web server serves — Apache, nginx and the `php -S` router alike.

So does `.env`, and that matters more than it looks: a `.env` is not a `.php`
file, so a server that could reach one would hand it over as plain text.

`config:list` prints `[hidden]` for a handful of key names — `password`,
`token`, `dsn` and a few more. There is deliberately no flag to reveal them: a
flag like that exists to be used, and where it gets used is a terminal somebody
is sharing their screen from.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| A change in `config/` does nothing | The configuration cache is still the old one | `php laika cache:clear`, then `cache:warm` |
| "of the wrong type", naming a key | A value is `'30'` where `30` was wanted | Fix the file; typed reads do not convert |
| The boot stops, naming a variable | A boolean or number in the environment cannot be read | Use `true/false/yes/no/on/off/1/0`, or a plain number |
| The boot stops, naming `app.memory_limit` | `MEMORY_LIMIT` is not PHP's notation, or is below what the process uses | `256M`, `1G` or `-1` |
| A `.env` value is ignored | A real environment variable of the same name wins | Unset the real one, or change it |
| A module's `config()` value is ignored | Your `config/` file overrides it, which is the design | Change the file, not the module |
| `config:cache` refuses | A closure or object is in the configuration | The message names the key |
