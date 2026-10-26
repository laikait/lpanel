# LPHP discovery

Verified facts about the LPHP copy this repository is built on, with the file
that proves each one. Plan §2 requires this before any LPanel module is
written. Anything not listed here is **unverified**: check the source before
relying on it.

Discovered: 2026-10-26, in `C:\xampp\htdocs\lpanel` (no git history yet).

## 1. Which LPHP this is

| Fact | Evidence |
|---|---|
| Engine version is **3.1.3** | `engine/Core/Application.php:37` (`VERSION = '3.1.3'`); `php laika help` prints "App Framework 3.1.3" |
| Docs are stale: they say 2.1.2 | `docs/README.md` ("Everything here describes version 2.1.2"), `README.md` status line |
| Latest released CHANGELOG entry is 3.0.1, with an `[Unreleased]` section above it | `CHANGELOG.md:12`, `CHANGELOG.md:280` |
| **`STABILITY.md` is missing** | Referenced by README, `docs/reference/system.md`, `CHANGELOG.md`; the deleted `DocumentationTest` failed on it |
| **`framework.json` (the release manifest) is missing** | `engine/Update/Manifest.php:37`; `docs/operations/updating.md:120` says `framework:update` then needs `--baseline=<framework.json>` |
| Every public API is **Experimental** | `README.md` status block |
| PHP `^8.2` with `intl`, `json`, `mbstring`, `pdo`; `sodium` suggested | `composer.json` `require` / `suggest` |
| Dev machine: PHP 8.3.33 ZTS (XAMPP, Windows); `intl`, `pdo_sqlite`, `sodium`, `fileinfo` enabled | `php -v`, `php -m` |

**Consequences**

- Ask the LPHP maintainers for the matching `STABILITY.md` and `framework.json` (3.1.3). Without them, the stability levels can't be confirmed and `framework:update` can't run safely.
- Treat every engine API as Experimental: wrap anything LPanel depends on heavily behind an LPanel-owned class, so an API change touches one file.

## 2. Ownership: this repository is a copy of LPHP

`engine/Update/Manifest.php:20-31` sorts files into three roles, and the updater acts on each one differently:

| Role | Paths | What `framework:update` does |
|---|---|---|
| owned | `engine/`, `laika`, `server`, `public/index.php`, `docs/`, `phpunit.xml`, `.env.example`, the framework's tests | replaces, adds, deletes — only if unedited |
| seed | `modules/`, `templates/`, `lang/`, `config/`, `public/assets/` | adds when new, never replaces |
| merged | `composer.json` | merged key by key |
| none | anything else (e.g. `design/`, our tests) | never touched |

Paths it never writes: `vendor/`, `system/`, `.git/`, `.env`, `.env.local` (`Manifest.php:54-57`).

**Rules for LPanel**

- Never edit `engine/`. Plan §17.4 rule is now enforceable, because the updater depends on it.
- LPanel code lives in `modules/`, `templates/`, `config/` and `public/assets/`; design docs live in `design/`.
- `phpunit.xml` is owned, and was edited in the 2026-10-26 cleanup (architecture suite removed). An update will see it as locally changed. Recheck on the first framework update.
- Deleting the framework's tests means those owned files are "missing". **Unverified:** whether an update re-adds them. Check before the first framework update.

## 3. Modules

| Fact | Evidence |
|---|---|
| A module is `modules/<Name>/module.php` returning `static function (ModuleContext $module): void` | `modules/Shared/module.php`; `docs/reference/modules.md` |
| `Shared` loads first, can't be disabled, may not depend on any module | `engine/Module/ModuleKind.php`, `ModuleManager.php:320`, `DependencyResolver.php:106-110` |
| Other modules load by name, reordered by `requires()` | `engine/Module/ModuleRegistry.php:12`, `DependencyResolver.php` |
| `ModuleContext` methods: `name`, `version`, `description`, `requires`, `optionally`, `services`, `routes`, `commands`, `schedules`, `access`, `mcp`, `templates`, `config`, `hook`, `filter`, `onBoot` | `engine/Module/ModuleContext.php:117-385` |
| Migrations are discovered from `<Module>/Database/Migrations/`, seeders from `Database/Seeders/` — no registration | `docs/reference/database.md:532-534` |
| Templates: `<Module>/Templates/` becomes the Twig namespace `@<Module>/…` automatically | `docs/reference/templates.md:90-106` |
| Assets: `<Module>/assets/` is served at `/assets/module/<Module>/…` automatically; `public/assets/` at `/assets/core/` | `docs/reference/assets.md:34-66` |
| Jobs: any class implementing `Job` with a `handle()` method; nothing registers it | `docs/reference/queue.md:9-47` |
| Modules can be disabled with `modules.disabled` | `engine/Bootstrap/Bootstrap.php:2564-2569` |

## 4. HTTP, routing, JSON APIs

| Fact | Evidence |
|---|---|
| `RouteCollector`: `get/post/put/patch/delete/options/head/match/any`, `group($prefix, $routes, name:, meta:)` | `engine/Routing/RouteCollector.php:30-96` |
| `Route`: `name`, `where`, `whereMany`, `defaults`, `meta` | `engine/Routing/Route.php:50-91` |
| Route meta keys seen in docs: `auth`, `can`, `csrf`, `rate_limit`, `signed`, `api`, `version`, `feature`, `maintenance`, `openapi`, `theme`, `deprecated` | `docs/guides/json-apis.md:21-60`, `docs/reference/auth.md:12-28` |
| Handlers: invokable class, `[Class, 'method']`, or closure; return `Response`, string (HTML) or array (JSON); parameters are injected | `modules/Shared/module.php` (old version), `docs/reference/routing.md` |
| Last route declared for a method + path wins | old `modules/Shared/module.php` comments |
| One error shape for everything: `{"error":{"status","title","message",…}}`; deliberately not RFC 9457 | `docs/reference/rest.md:107-150` |
| Validation: `Schema::validate()` → `ValidationResult`; `ErrorDocument::validation(...)->toResponse()`; `deserialize()` **drops unknown fields** (stops mass assignment) | `docs/guides/json-apis.md:108-155` |
| `$request->requirePayload()` gives 415 for a non-JSON body; `$request->json()` | same |
| `ApiResponse::collection($items, $meta)`, `ApiResponse::created($body, $url)`; offset and cursor pagination on queries | `docs/guides/json-apis.md:157-202` |
| `HttpException::notFound/badRequest/unauthorized/forbidden/tooManyRequests`; outside debug, other exception messages become "Internal Server Error" | `docs/guides/json-apis.md:204-223` |
| Rate limit as route meta (`'60/1m'`), giving 429 + `Retry-After` | `docs/guides/json-apis.md:45-50` |

## 5. Security primitives

| Fact | Evidence |
|---|---|
| CSRF is **on by default** for POST/PUT/PATCH/DELETE; opt out per route/group with `'csrf' => false`. On an opted-out route, **only a bearer token authenticates** — the session cookie identifies nobody | `docs/reference/security.md:42-67` |
| A bearer token does **not** exempt a request from CSRF: `Guard::enforceCsrf()` skips only when CSRF is disabled or the route has `csrf => false` | `engine/Security/Guard.php:136-147` |
| CSRF token is `__Host-XSRF-TOKEN` over HTTPS; checks the token and the `Origin` header | `docs/reference/security.md:69-100` |
| CSRF token is bound to a browser, not a session (not built) | `docs/what-is-not-built.md` |
| Session cookie: HttpOnly (not configurable), SameSite=Lax, Secure follows the scheme | `docs/reference/sessions.md:178-191` |
| A successful login regenerates the session id and rotates the CSRF token; the session stores only the user id, so roles are re-read on each request | `docs/reference/auth.md:175-191` |
| A default CSP header is sent (`SecurityHeaders::DEFAULT_CSP`); no HSTS by default | `engine/Security/SecurityHeaders.php`, `docs/what-is-not-built.md` |
| `Signer` (HMAC with purpose/context), `Encrypter` (XChaCha20-Poly1305, key rotation via previous keys), `Secret` (redacts itself in dumps and JSON) | `engine/Security/Signer.php`, `Encrypter.php`, `Secret.php` |
| `RateLimiter::attempt($key, '5/15m')` | `engine/Security/RateLimiter.php` |
| **No Ed25519 / public-key signing in the engine** | `grep sodium_crypto_sign engine` → nothing |
| `php laika security:check` audits the deployment | `php laika help` |

## 6. Authentication and authorization

| Fact | Evidence |
|---|---|
| No User model or users table. A module binds `UserProvider` (`describe`, `byId`, `byLogin`) | `docs/reference/auth.md:30-60` |
| API tokens: the provider also implements `TokenProvider::byToken()`; `Authorization: Bearer`; store `TokenAuthenticator::fingerprint()` (SHA-256), never the token | `docs/reference/auth.md:198-225` |
| Capabilities and roles declared in `$module->access()`; wildcard `invoice.*` stops at the dot; roles inherit roles | `docs/reference/auth.md:62-95` |
| Per-record checks: the `authorization.decision` filter may **refuse, never grant**; fails closed | `docs/reference/auth.md:97-113` |
| A route `can` naming an undeclared capability **stops the boot** | `docs/reference/auth.md:115-127` |
| Login throttling built in (`auth.throttle.*`: 5/15m per login+IP, 30/15m per IP, 100/1h per login) | `docs/reference/auth.md:159-173` |
| **TOTP two-factor login and recovery codes exist** (`TwoFactor::attempt/challenge/begin/confirm`, `TwoFactorAccounts` for storage) | `engine/Auth/TwoFactor.php`, `docs/reference/auth.md:317-364` |
| Password reset and email verification flows exist | `engine/Auth/PasswordReset.php`, `EmailVerification.php` |
| Apache drops `Authorization`; the engine recovers it from `getallheaders()` / `REDIRECT_HTTP_AUTHORIZATION` | `docs/reference/auth.md:227-237` |

> Correction to the 2026-10-26 plan review: it said LPHP has no MFA. It does
> (TOTP + recovery codes). LPanel still has to build the screens and the
> `TwoFactorAccounts` storage.

## 7. Data

| Fact | Evidence |
|---|---|
| Drivers: MySQL/MariaDB, PostgreSQL, SQLite, SQL Server (grammars) | `engine/Database/*Grammar.php` |
| Connection from `DB_DSN`/`DB_USERNAME`/`DB_PASSWORD`, or `config/database.php` | `docs/reference/database.md:13-63` |
| `Shared` binds `DataSource` → `SqlSource` when a DB is configured, else `ArraySource` (memory) | `modules/Shared/module.php` |
| Repositories extend `Repository` with `model()` + `collection()`; only protected tools (`query`, `persist`, `remove`, `hydrate`); no `find/all/save` | `docs/guides/storing-data.md:203-250` |
| `Query` has no `OR` and no joins (by design); joins go in a repository method over the SQL query builder | `docs/what-is-not-built.md` |
| `$connection->transaction(fn)` with savepoints for nesting | `docs/reference/database.md:118-152` |
| Migration file `YYYY_MM_DD_HHMMSS_name.php` returning `Migration`/`Reversible` with `up(Tables)`/`down(Tables)`; schema builder `$tables->create(...)`, `$table->id()`, `->index()`, `->foreign()->references()` | `docs/reference/database.md:536-560` |
| `migrate`, `migrate:status`, `migrate:rollback`, `db:seed`; a DB lock makes it one run at a time; **MySQL DDL isn't transactional** | `docs/reference/database.md:561-603` |
| Migrations are a deploy step; the app's DB account should not be able to create tables | same |

## 8. Background work

| Fact | Evidence |
|---|---|
| Queue stores: `sync` (default), `file`, `database`, `memory`; **no Redis** | `docs/reference/queue.md:49-73` |
| `$queue->push(new Job(...))`, `$queue->later($seconds, $job, queue:)` | `docs/reference/queue.md:24-27` |
| Constructor = data (serialized), `handle()` = injected collaborators. Pass ids, not models | `docs/reference/queue.md:30-40` |
| Retries with capped exponential backoff (5s→10min), optional jitter; failed list; `queue:failed --retry` | `docs/reference/queue.md:101-121` |
| Attempts are counted at **reservation**; a crashed worker's job returns when the reservation lapses; timeout is a reservation, not an interruption; no `pcntl` | `docs/reference/queue.md:123-144` |
| DB store claims with `lockForUpdate()` + a guarded UPDATE; safe across machines | `docs/reference/queue.md:146-170` |
| **The DB queue table defaults to `jobs`** (`queue.table`) | `docs/reference/queue.md:166` |
| Workers are bounded (`--max-jobs`, `--max-time`, `--memory`) and restarted by systemd; `system:systemd:install` writes the units | `docs/reference/queue.md:75-99` |
| Scheduler: `$module->schedules()`, one cron line calling `schedule:run`, locks against double runs | `docs/reference/scheduler.md`, `php laika help` |

**Consequence:** LPanel's own operation tracking must **not** use a table named
`jobs`. See [DATA_MODEL.md](DATA_MODEL.md) and
[ADR-0006](adr/0006-operations-separate-from-queue.md).

## 9. System operations (`engine/System`)

| Fact | Evidence |
|---|---|
| `CommandExecutor->run(new Command($exe, [$args], timeout:))`: argv only; `sh`/`bash`/`cmd`/`powershell` refused as executables; every command has a timeout and output cap; minimal environment | `docs/reference/system.md:33-70` |
| `ServiceManager` / `ServicePolicy` (config `system.services`), `SystemdManager`, `CronManager`, `SystemFilesystem` + `FilesystemPolicy` (allowed roots, `PathTraversalException`), `PermissionManager`, `SystemInfo` | `engine/System/**` |
| `system.*` capabilities; `SystemAuthorizer`; audit on the `system.audit` hook and the `audit` log channel, never with argument values or output | `docs/reference/system.md:178-228` |
| **`system.commands.allowed` defaults to `null`, meaning anything on PATH may run**; `system.enabled = false` stops every manager being built | `docs/reference/system.md:230-262` |
| Linux-first; services, cron and permissions refuse on Windows | `docs/reference/system.md:293` |

**Consequence:** LPanel does no system mutation itself (plan §3.2), so it ships
`config/system.php` with `'enabled' => false`. See
[ADR-0005](adr/0005-lpanel-disables-engine-system.md). LPA is written in Go
([ADR-0001](adr/0001-lpa-in-go.md)), so `engine/System` is a design reference
for LPA, not a dependency.

## 10. Console, deployment, testing

| Fact | Evidence |
|---|---|
| `php laika <command>`; full list from `php laika help` | `laika`, console output |
| A command is a plain class with `__invoke(...)`, declared in `$module->commands()` with `->describe/argument/option/flag` | `docs/reference/console.md:10-57` |
| `nginx:make --server-name --root --php --listen` writes a server block that serves `public/` only | `docs/operations/deployment.md:89-116` |
| `down`/`up` maintenance mode; `cache:warm` for the production boot path | `php laika help`, `docs/operations/deployment.md:188` |
| Tests: PHPUnit 11, `tests/Unit` + `tests/Feature`, namespace `App\Tests\`; base classes in `tests/Support` (`TestCase::shippedApplication()`, `ApplicationTestCase::app()` = SQLite memory + migrations) | `docs/guides/testing.md`, `tests/Support/*` |
| A request in a test: `$app->handle(Request::create('GET', '/health'))` | `tests/Feature/ShippedApplicationTest.php` |
| Quality gate: `composer check` = php-cs-fixer (PER-CS2.0) + PHPStan level 8 over `engine`, `modules`, `tests` + PHPUnit | `composer.json`, `phpstan.neon`, `.php-cs-fixer.dist.php` |
| A MySQL test DB is used when `DB_TEST_MYSQL_DSN` is set | `tests/Support/TestDatabases.php` |

## 11. Baseline test run (before the framework suite was removed)

Run on 2026-10-26 on the pristine copy: **4642 tests, 29 errors, 35 failures, 3 warnings, 62 skipped.**
Every failure was environmental, on Windows/XAMPP:

- OpenSSL can't make keys (`SocialLoginTest`, `JwtTest`, `ProvidersTest`)
- no `/dev/null` (`SmtpTransportTest`, `S3DiskTest`, `TransportTest`)
- no `mysqldump`/`pg_dump` on PATH (backup driver tests)
- CRLF vs LF (`SystemdUnitsTest`, `SystemConsoleTest`, localization)
- a Unicode temp-file name (`DiskConformanceTest`)
- the missing `STABILITY.md` (`DocumentationTest`)

None pointed at an engine defect. Full output was kept in the session scratchpad (not in the repository).

**Consequence:** run the engine's suite on Linux at least once (WSL2/Docker) before the first release, against a framework checkout. Windows is not a valid test host for anything system-related.

## 12. Gaps and how LPanel fills them

| Gap | Plan |
|---|---|
| No Ed25519 signing | Use `ext-sodium` (`sodium_crypto_sign_*`) directly, in an LPanel-owned class (Servers module) |
| No users, roles storage or login screens | Identity module: `UserProvider` + `TokenProvider` + `TwoFactorAccounts` over our own tables |
| No domain job state machine | Operations module ([ADR-0006](adr/0006-operations-separate-from-queue.md)) |
| No long-lived connections | Agent polls the panel ([ADR-0002](adr/0002-agent-pull-transport.md)) |
| No HSTS by default | Set it in the panel Nginx config once TLS is settled |
| Missing `STABILITY.md` / `framework.json` | Request them from LPHP; until then, assume Experimental |
