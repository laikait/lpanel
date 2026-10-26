# Contributing to the framework

For people changing `engine/`, the shipped `Shared` module, the default template
or the documentation. Building an application *on* the framework is covered by
the [guides](../README.md#guides) instead.

The framework holds itself to a small number of rules, and most of them are
enforced by tests rather than by review. This page says what they are, so a
failing architecture test is a reminder rather than a surprise.

## Set up

```bash
git clone <repository> && cd <repository>
composer install
composer check
```

PHP 8.2 or newer with `pdo_sqlite`. There is no coverage gate, so Xdebug and PCOV
are not needed.

## The gate

`composer check` must pass before anything is merged:

| | |
|---|---|
| `composer cs` | PHP-CS-Fixer, dry run, over `engine/` and `tests/`. `composer cs:fix` applies it |
| `composer stan` | PHPStan level 8 over `engine/`, `modules/` and `tests/`, analysed for every PHP version from 8.2 to 8.5. An application's own modules are held to the same level; the coding standard (`composer cs`) covers only `engine/` and `tests/` |
| `composer test` | PHPUnit: `tests/Unit`, `tests/Architecture`, `tests/Feature` |

There is **no PHPStan baseline**, and there will not be one. Fix the error, or
narrow the type honestly; do not add `@var` casts or `ignoreErrors` entries to
make one go away.

CI (`.github/workflows/pcc.yml`) runs two jobs:

- **Gate** — `composer check` on 8.2, 8.3, 8.4 and 8.5, on Linux. Linux matters:
  it is the only place a directory whose case does not match its namespace fails.
- **Runtime** — `composer install --no-dev` on 8.2, lints every shipped file,
  boots the console, and serves `/` and a 404 through `php -S`. It proves the
  framework runs without its development dependencies.

Benchmarks (`composer bench`) are informational and never part of the gate. See
[Performance](../reference/performance.md#benchmarks-and-what-track-regressions-can-honestly-mean).

## Rules for engine code

**Things the framework does not have, on purpose.** No facades, no service
providers, no middleware, no gates or policies, no form-request objects, no
Eloquent, Blade or Artisan clone, no global `Controllers/` or `Models/`
directory, and no `make:*` generator. Each has an architecture test. A change
that needs one of these is a design discussion first.

**Create only what is used.** No empty directories for later, no interface with
one speculative implementation, no configuration key nothing reads, no helper
for a subsystem that does not exist.

**Injection, not location.** Engine classes take collaborators in their
constructor. No file under `engine/` may call the global helpers
(`add_hook()`, `asset()`, …) except `helpers.php`, and nothing may resolve
services by name at runtime. The helper set is closed: `Support\Extensions`
exposes exactly `hooks`, `filters`, `assets` and `templates`.

**Layers stay separate.** Among the boundaries the architecture tests hold:

- `Schema` cannot see `Http`, `Routing`, `Model` or the container.
- `Database` knows nothing about models; only `Grammar` builds SQL.
- `Template` and `Asset\AssetManager` never see `Request` or `Response`.
- `Error` never mentions a logger, and only `Logging\ErrorLog` mentions errors.
- Nothing in `Queue` sees a request, and nothing in `Scheduler` sleeps, loops on
  the clock or starts a process.
- Only `System` starts a process, and nothing in the engine calls `exec()`,
  `system()`, `passthru()`, `popen()` or backticks, and `proc_open()` is only
  ever given an argv array. A shell runs only as `ShellCommand::bash()` on a
  script file. `System` sees no request, console input or session: services
  call it, interfaces do not.
- No subsystem references the profiler; `Observability` writes only through the
  log and headers.
- Only `Env` reads the environment, and nothing calls `putenv()`.
- `engine/` never references `App\Modules\`.

**Coding details the tools enforce.** Every file starts with
`declare(strict_types=1);`. Calls to PHP's own functions are fully qualified —
`\strlen()` — inside a namespace. Functions that exist only with some extensions
or SAPIs (`opcache_get_status`, `getallheaders`, `fastcgi_finish_request`) are
listed in `.php-cs-fixer.dist.php`, so the fixer gives the same answer on every
machine; add any new one there. Call them only behind `function_exists()`.

**Supported PHP.** `composer.json` says `^8.2`; `phpstan.neon` analyses from
80200 to 80500, and a test fails if the two disagree. Syntax newer than 8.2 is
reported on every run.

**Security-relevant defaults.** Messages from exceptions the framework did not
write are withheld outside debug; a factory that quotes another exception's
message must call `withheld()`, and a test checks. Secrets travel as `Secret`.
Anything that turns a path into a file goes through the one class responsible.

## Where the design decisions are written down

Each reference page ends with the reasoning behind its subsystem, so a change
that contradicts one is a discussion rather than a surprise. The ones most often
argued with:

| Decision | Written up in |
|---|---|
| No service providers; registration cannot read from the container | [Modules](../reference/modules.md#why-this-is-not-a-service-provider) |
| No middleware; security is listeners on lifecycle hooks | [Security](../reference/security.md#why-there-is-no-middleware) · [Routing](../reference/routing.md#there-is-no-middleware) |
| No gates or policies; grants are data, and a filter may only refuse | [Auth](../reference/auth.md#why-this-is-not-a-gate) |
| No `Command` base class | [CLI](../reference/console.md#no-command-base-class) |
| Ten global helpers, and why they are not facades | [Hooks and filters](../reference/hooks-and-filters.md#helpers-are-not-facades) |
| Rendering and recording errors are separate layers | [Logging](../reference/logging.md#error-rendering-does-not-know-this-layer-exists) |
| Building an asset URL and serving the file are separate | [Assets](../reference/assets.md#building-a-url-and-delivering-a-file-are-separate-jobs) |
| An asset request loads no module | [Assets](../reference/assets.md#an-asset-request-loads-no-module) |
| SQL is built in one place, and only names are interpolated | [Database](../reference/database.md#the-injection-boundary-is-one-file-wide) |
| Profiling costs nothing when it is off | [Observability](../reference/observability.md#how-off-costs-nothing) |
| Routes and dependency resolution are not cached | [Performance](../reference/performance.md#what-is-deliberately-not-cached) |
| A session writes what it changed, not what it read | [Sessions](../reference/sessions.md#the-request-writes-what-it-changed-not-what-it-read) |

## Checklists

When you add…

| | Also |
|---|---|
| **a class under `engine/`** | a row in [`STABILITY.md`](../../STABILITY.md), or a namespace row that covers it — decide whether it is public. `DocumentationTest` fails otherwise |
| **a hook or filter** | a row in the [lifecycle table](../reference/hooks-and-filters.md#lifecycle-extension-points). `DocumentationTest` compares the table with every `->do()` and `->apply()` in `engine/` |
| **a framework command** | its line in the [command list](../reference/console.md#the-frameworks-own-commands-are-not-special). It must answer a question, not generate code |
| **an environment variable** | an entry in `.env.example`, with its default and what it means. A test checks |
| **a configuration key** | a default in `Bootstrap::defaults()`, and documentation on the subsystem's reference page |
| **a file the web server must never serve** | nothing — keep it out of `public/`. A test allows only `index.php`, `.htaccess` and `assets/` there |
| **a store implementation** | add it to the store type's conformance test; a test fails if a store exists that it does not run |
| **a persisted format change** (queued jobs, sessions, caches) | the next release must still read what the previous one wrote |
| **anything user-visible** | a line under `[Unreleased]` in [`CHANGELOG.md`](../../CHANGELOG.md) |
| **a breaking change** | a section in [`UPGRADING.md`](../../UPGRADING.md) saying what changed, who is affected, and what to do |

## Documentation

- `README.md` is the front door; `docs/` holds everything else — see the
  [index](../README.md). A new page must be linked from the index: a test
  checks.
- Reference pages explain *what* and *why* for one subsystem; guides show *how*
  for one task, with code that runs.
- Every link and anchor across `README.md`, `docs/`, `STABILITY.md`,
  `CHANGELOG.md` and `UPGRADING.md` is checked by `DocumentationTest`. Rename a
  heading and the test lists the links to fix.
- Code in a guide should be code you ran. The guides' examples were built as
  real modules and tested before they were written down.

## Tests

- `tests/Unit` mirrors `engine/`. Pure classes, no application.
- `tests/Feature` boots a real application through `Tests\Support\TestCase`:
  `application()` loads the showcase modules from `tests/Fixtures/Showcase`,
  `shippedApplication()` loads exactly `modules/`, and `fixtureApplication()`
  loads the small modules in `tests/Fixtures/Modules`.
- `tests/Architecture` holds the rules above. A new invariant worth keeping is a
  new test there, with a docblock saying why the rule exists.
- The showcase (`Example`, `Example`) exercises every subsystem
  through one application. Extend it when a feature needs an end-to-end proof.

Test names are sentences: `test_a_guest_gets_401_and_an_account_gets_403`.

## Commits and releases

Keep one concern per change. Update the changelog in the same change as the code.
Releases are made by hand, following
[Versioning and releases](releases.md#versioning-and-releases); nothing tags or
publishes automatically.

The [development notes](development.md) list the implementation status of every
phase of the specification.
