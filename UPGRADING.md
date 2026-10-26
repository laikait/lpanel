# Upgrading

What to change in an application when moving between versions, newest first.
Every breaking change listed in [`CHANGELOG.md`](CHANGELOG.md) has a section
here; what counts as breaking is set by [`STABILITY.md`](STABILITY.md).
Internal classes can change without an entry. The one exception is a persisted
format — a queued job, a session record — which always gets one.

Each entry says **what changed**, **who is affected** and **what to do**, in that
order. An entry that cannot say who is affected is not finished.

## To the next release, from 3.0.1

### HTML pages get a Content-Security-Policy

- **Changed:** with debug off and `security.headers.csp` unset, HTML responses
  carry `default-src 'self'; script-src 'self'; style-src 'self'
  'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'self';
  frame-ancestors 'self'; form-action 'self'`.
- **Affected:** pages with inline `<script>`, `onclick=` handlers, scripts or
  fonts from a CDN, or forms that post to another site. The browser blocks them
  and says so in its console.
- **Do:** set your own policy in `security.headers.csp` naming those sources, or
  `''` for none (not recommended; `security:check` warns).

### X-Engine is not sent

- **Changed:** responses no longer carry `X-Engine: LPHP`.
- **Affected:** a monitor or proxy rule that looks for that header.
- **Do:** `http.headers => ['X-Engine' => 'LPHP']` sends it again.

### Logins are throttled

- **Changed:** `AuthManager::attempt()` and `verify()` throw a 429
  `HttpException` after too many failed passwords (see `auth.throttle.*`).
- **Affected:** a login handler that catches every exception and shows "wrong
  password", and test suites that log in with a wrong password more than five
  times from one address.
- **Do:** let the 429 through (the error handler renders it), and in tests use
  `auth.throttle.enabled => false` or a fresh counter store.

### Database sessions are logged out once

- **Changed:** the database session store keys rows by the SHA-256 of the
  session id, not the id.
- **Affected:** every application with `session.store` = `database`. Sessions
  from before the upgrade are not found, so their users sign in again. File
  sessions keep working.
- **Do:** nothing; `php laika session:gc` removes the old rows as they expire.

### Webhook ids are remembered in a file when the cache cannot

- **Changed:** with a cache that forgets between requests (the default `array`
  store, or none), webhook delivery ids are kept in `system/Cache/webhooks/`,
  so a replay is reported as a duplicate. Deliveries are never refused for it.
- **Affected:** applications receiving webhooks with the default cache.
- **Do:** make `system/Cache/` writable, as it already is for `CACHE_STORE=file`.

### Other refusals

- **Changed:**
  - `php laika down --secret` needs at least 16 characters;
  - `security:check` exits 1 in production without `APP_KEY`;
  - the local disk refuses paths with `:`, a segment ending in `.` or a space,
    or a Windows device name;
  - `Message::header()` refuses the headers the mailer writes, in any case;
  - SMTP refuses to authenticate without TLS to a host other than this machine;
  - a `Host` header that is not a host name is refused with 400.
- **Affected:** deploy scripts with a short bypass secret or no `APP_KEY`;
  stored file names of those shapes; mail sent with `header('from', …)`; SMTP
  configured with `encryption=none` and a user name.
- **Do:** use a longer secret (`php laika security:key` prints one) and set
  `APP_KEY`; rename such files; use `from()`, `to()` and `subject()`; set
  `mail.smtp.encryption` to `tls`.

### A module called "assets" cannot be rendered as @assets/…

- **Changed:** a template name in the `assets` namespace is refused, because
  its override folder is `templates/assets/`, which holds static files. Names
  that reach a registered theme's `assets/` through `templates/`, like
  `admin/dark/assets/…`, are refused for the same reason.
- **Affected:** only a module whose id is `assets`. Every other module,
  including one with a `Templates/assets/` folder, is unchanged.
- **Do:** rename the module's folder, and its `@assets/…` names with it.

### The CSRF cookie is __Host-XSRF-TOKEN over HTTPS

- **Changed:** over HTTPS the CSRF cookie is set and read as
  `__Host-XSRF-TOKEN`; plain HTTP keeps `XSRF-TOKEN`. A `XSRF-TOKEN` cookie
  sent over HTTPS is ignored, which is the point: no subdomain can set the new
  one.
- **Affected:** a script that reads the cookie by name to echo it in
  `X-XSRF-TOKEN` (axios's `xsrfCookieName`, Angular's `HttpClientXsrfModule`),
  and a test that sends `Csrf::COOKIE` with an HTTPS request. Forms that post
  `_token` are not affected. Each browser is issued the new cookie on its next
  page, so a form rendered before the deployment fails once.
- **Do:** read `Csrf::SECURE_COOKIE` on HTTPS, or the name
  `Csrf::cookieName($request)` gives. Only if a client cannot be changed, set
  `security.csrf.host_prefix` to `false`, which gives the protection up.

### Social sign-in asks for the second factor

- **Changed:** an account with two-factor turned on is no longer logged in by
  a social sign-in. `SocialLogin::login()` returns null for it and leaves it
  waiting for its code; `SocialLogin::attempt()` says `ChallengeRequired`. The
  shipped callback redirects it to the route named by `auth.two_factor.route`
  (default `two_factor.challenge`), and refuses it with 403 when there is none.
- **Affected:** an application with both social sign-in and two-factor turned
  on, and a handler of its own that calls `SocialLogin::login()`.
- **Do:** name your code form's route `two_factor.challenge`, or set
  `auth.two_factor.route` to its name. In your own handler, call `attempt()` and
  treat `ChallengeRequired` as `TwoFactor::attempt()` does.

### A login leaves no session pointer, and SESSION_GRACE=0 means none

- **Changed:** `AuthManager::login()` regenerates the session id with
  `followable: false`, so the id from before the login stops working at once,
  instead of for `session.grace` seconds. `session.grace = 0` now means no
  window at all; it used to mean an unlimited one.
- **Affected:** a page that logs in while other requests are in flight with the
  old id — each of them starts an empty session — and a deployment that set
  `SESSION_GRACE=0`.
- **Do:** nothing, usually. Wait for a login request to finish before sending
  the next authenticated one.

### Queued jobs and cache entries are sealed with APP_KEY

- **Changed:** with `APP_KEY` set, every queued job and every entry in the
  `file` and `database` cache stores carries an HMAC, and one without a valid
  HMAC is never unserialized. A job fails with "was not written by this
  application with its current APP_KEY"; a cache entry is a miss.
- **Affected:** jobs already waiting in a `file` or `database` queue when you
  deploy — they were queued unsealed, and fail — and every cached value, which
  is recomputed once. The same happens whenever `APP_KEY` is set or rotated
  later. Code that called `QueuedJob::job()` itself must pass the sealer.
- **Do:** let the workers drain the queue (`php laika queue:status` shows it
  empty) before deploying, and again before changing `APP_KEY`. Failed jobs can
  be dispatched again from their source, not retried.

### A route with 'csrf' => false ignores the session cookie

- **Changed:** on a route whose meta says `'csrf' => false`, the session cookie
  no longer identifies the visitor; only a bearer token does. A login made
  during the request still counts.
- **Affected:** a page of your own that calls a CSRF-exempt route — usually an
  API group — with the session cookie instead of a token. It now gets `401`,
  where it used to be logged in and unprotected against cross-site requests.
- **Do:** take `'csrf' => false` off that group and send the token in
  `X-CSRF-TOKEN`, or have that client use a bearer token.

## To 3.0.1, from 2.1.2

### Request::ip() reads X-Forwarded-For from the right

- **Changed:** behind a trusted proxy, `Request::ip()` walks `X-Forwarded-For`
  from the right and returns the first address that is not itself a trusted
  proxy. It used to return the leftmost entry, which the client writes and can
  forge. `http.trusted_proxies` now also accepts CIDR blocks.
- **Affected:** an application behind more than one proxy — a CDN in front of a
  load balancer, say — that lists only the proxy nearest to it. The CDN's address
  is now what `ip()` returns, because nothing says it may be believed.
- **Do:** list every proxy in front of the application, as addresses or blocks:
  `['10.0.0.0/8', '173.245.48.0/20']`. Then run `php laika security:check`,
  which fails on an entry it cannot read.

### chunk() walks by key

- **Changed:** `Query::chunk()` starts each batch after the last row of the one
  before, instead of at an offset. The order is the query's `orderBy()` columns
  with the key added last; with no order it is the key ascending.
- **Affected:** a `chunk()` over a query with no `orderBy()` that relied on the
  database's unspecified order (it is now the key's order), and a `chunk()`
  ordered by a column that can be null, which is refused once a batch ends on a
  null.
- **Do:** nothing, usually — the walk is now stable while rows change. Order a
  chunk by columns that are never null, or add `whereNotNull()`.

### Modules are flat: modules/<Name>, no plugins or gateways

- **Changed:** a module is any folder directly under `modules/` with a
  `module.php`, and its id is the folder name. `modules/Plugins/` and
  `modules/Gateways/` are gone, and with them the plugin and gateway kinds.
  `Shared` is the one fixed name and still registers first.

  | | 2.1.2 | Now |
  |---|---|---|
  | folder | `modules/Plugins/Billing/` | `modules/Billing/` |
  | id | `plugins/Billing`, `shared` | `Billing`, `Shared` |
  | `requires()`, `modules.disabled`, `--module=` | `'plugins/Billing'` | `'Billing'` |
  | namespace | `App\Modules\Plugins\Billing\` | `App\Modules\Billing\` |
  | templates | `@plugin.Billing/…`, `@shared/…` | `@Billing/…`, `@Shared/…` |
  | template override | `templates/plugin.Billing/` | `templates/Billing/` |
  | assets | `/assets/plugin/Billing/…`, `asset()->plugin()` / `->gateway()` | `/assets/module/Billing/…`, `asset()->module()` |
  | configuration | `config/plugins/Billing.php`, `shared.*` | `config/Billing.php`, `Shared.*` |
  | translations | `plugin.Billing.key` | `Billing.key` |
  | migration ids | `plugins/Billing:2026_…` | `Billing:2026_…` |
  | `modules.paths` | `['shared' => …, 'plugins' => …, 'gateways' => …]` | `['modules']`, a list |

  A folder name must start with a capital letter and hold only letters, digits
  and underscores; anything else with a `module.php` stops the boot. Two
  folders of the same name in different `modules.paths` entries also stop it.
  A gateway may no longer be told apart from a plugin by kind, so a plugin and
  a gateway that shared a name must now differ.
- **Affected:** every application with a module of its own, and anything that
  names one: `requires()`, `modules.disabled`, config files, templates, asset
  URLs, translation keys, and the `migrations` table.
- **Do:**
  1. Move each `modules/Plugins/<Name>/` and `modules/Gateways/<Name>/` to
     `modules/<Name>/`, renaming one if a plugin and a gateway share a name.
     Change `App\Modules\Plugins\` and `App\Modules\Gateways\` to
     `App\Modules\` and run `composer dump-autoload`.
  2. Replace the ids and names in the table above in your code, templates and
     `config/`. Move `config/plugins/<Name>.php` to `config/<Name>.php`, and
     `templates/plugin.<Name>/` to `templates/<Name>/`.
  3. If `config/modules.php` sets `paths`, make it a list:
     `['paths' => ['modules']]`.
  4. Rename recorded migrations so they are not run again (the table is
     `database.migrations.table`, `migrations` by default):

     ```sql
     UPDATE migrations SET
         migration = REPLACE(REPLACE(REPLACE(migration, 'plugins/', ''), 'gateways/', ''), 'shared:', 'Shared:'),
         module = CASE WHEN module = 'shared' THEN 'Shared'
                       ELSE REPLACE(REPLACE(module, 'plugins/', ''), 'gateways/', '') END;
     ```
  5. Run `php laika cache:clear`, then `php laika module:list` to check the
     ids.

## To 2.1.2, from the tree committed as "Phase 23-25"

2.1.2 is the first release, so there is no earlier release to upgrade from.
These notes are for code written against the repository as it stood at that
commit.

### templates/ is the site's views; no more APP_TEMPLATE

- **Changed:** views are found in `templates/` itself, not in
  `templates/<name>/views/`. `render('customer/profile')` is
  `templates/customer/profile.twig`. The site's static files are
  `templates/assets/`, still served as `/assets/template/…`.
  `APP_TEMPLATE` and `templates.active` are gone, and so is
  `asset()->template('<name>', …)` reaching `templates/<name>/assets/`.
- **Affected:** every application with its own pages in `templates/default/`
  or another template directory; a module override in
  `templates/<name>/views/plugin.<Name>/`.
- **Do:** move everything in `templates/<name>/views/` up into `templates/`, and
  `templates/<name>/assets/` to `templates/assets/`. Delete `templates/<name>/`
  and `APP_TEMPLATE` from `.env`. Names in `render()` and in Twig's
  `extends` and `include` do not change. Keep views out of `templates/assets/`: a name
  starting `assets/` is refused.

### PHP 8.2 is the minimum

- **Changed:** `composer.json` requires `php` `^8.2`; it was `^8.1`.
- **Affected:** a host running PHP 8.1. Composer refuses to install there, and
  the development server fails on every request.
- **Do:** upgrade the host to PHP 8.2 or newer before upgrading the framework.

### The document root is public/

- **Changed:** `index.php` is `public/index.php`, and the application's own
  assets moved from `assets/` to `public/assets/`. The root `.htaccess` now only
  forwards into `public/`; the front-controller rules are in `public/.htaccess`.
  `composer serve` runs `php -S 127.0.0.1:8080 -t public server`. `nginx:make`
  writes `root <app>/public`.
- **Affected:** every deployment. A virtual host whose `DocumentRoot` is the
  project directory keeps working only while `.htaccess` is read; a custom
  `nginx.conf`; anything that runs `php -S` by hand; files an application added to
  `assets/`; a custom `.htaccess` rule.
- **Do:** point `DocumentRoot` (or nginx `root`) at `public/` and regenerate
  `nginx.conf` with `php laika nginx:make --force`. Move your own files from
  `assets/` to `public/assets/` — `asset()->core()` URLs do not change. Move any
  custom rewrite rule into `public/.htaccess`. Then run the curl checks in
  [Deployment and security](docs/operations/deployment.md).

### The console is `laika`, at the project root

- **Changed:** `bin/console` is now `laika`, next to `composer.json`.
- **Affected:** every crontab line, systemd unit, supervisor program, deploy
  script or CI step that runs `php bin/console`.
- **Do:** replace `php bin/console` with `php laika` in each of them.
  `composer console` is unchanged.

### ext-intl is required, and route constraints are UTF-8

- **Changed:** `composer.json` requires `ext-intl`; request and route paths are
  normalised to NFC with it. Route constraints are now matched with the `u`
  modifier, and `url()` percent-encodes non-ASCII bytes in fixed segments.
- **Affected:** a host without intl — Composer refuses to install there, and XAMPP
  ships it switched off. A constraint that relied on matching bytes rather than
  characters, such as `.{6}` meant as six bytes, or one containing raw non-UTF-8
  bytes. A test that compared a generated URL containing a raw non-ASCII fixed
  segment.
- **Do:** enable intl (`extension=intl` in `php.ini`, or `apt install
  php8.3-intl`). Write constraints in characters; add `\p{M}` to letter classes
  for scripts with combining vowel signs. Compare URLs in their encoded form.

### Module directories are capitalised

- **Changed:** `modules/shared`, `modules/plugins` and `modules/gateways` are
  `modules/Shared`, `modules/Plugins` and `modules/Gateways`, the defaults of
  `modules.paths` say so, and `composer.json` autoloads all three through one
  root, `App\Modules\` → `modules/`. Module ids (`shared`, `plugins/Billing`),
  template and asset namespaces are unchanged.
- **Affected:** every application with a module, and any `config/modules.php`
  that sets `paths`. On Linux, classes in a lowercase directory are no longer
  found.
- **Do:** rename the directories. On a case-insensitive filesystem (Windows,
  macOS) take two steps, or git records nothing:
  `git mv modules/plugins modules/_Plugins && git mv modules/_Plugins modules/Plugins`.
  Update `paths` if you set it, copy the new `autoload` block into
  `composer.json`, and run `composer dump-autoload`.

### A fresh install ships no demo modules

- **Changed:** `modules/plugins/Example` and `modules/gateways/Example` are no
  longer in `modules/`. They live in `tests/Fixtures/Showcase/`, under
  `App\Tests\Fixtures\Showcase\…`, and `config/plugins/Example.php` went with
  them. The routes they answered (`/customers`, `/api/v1/customers`, `/visits`,
  `/ping`) and the `customer:*` commands are gone from a fresh install.
- **Affected:** anything that called those routes or commands, or imported
  `App\Modules\Plugins\Example\…` or `App\Modules\Gateways\Example\…`.
- **Do:** treat them as a reference to copy, not a dependency. Copying one back
  into `modules/` means renaming its namespace to match its new directory.

### `/` is answered by the shared module

- **Changed:** `GET /` renders the default template's `home` page, declared by
  `modules/Shared` under the route name `home`.
- **Affected:** a module that declares `/` **and** names that route `home` —
  route names are unique, so boot stops with a duplicate-name error.
- **Do:** rename your route. Your `/` still wins: every other module registers
  after `shared`, and the router keeps the last route declared for a path.

### `composer stan` checks your modules

- **Changed:** `phpstan.neon` analyses `modules/` at level 8, alongside
  `engine/` and `tests/`.
- **Affected:** an application whose modules have type errors. `composer stan`
  and `composer check` now report them, and fail, where they passed before.
- **Do:** fix what is reported. There is no baseline to hide it in, by design.
  To defer it, remove `modules` from `paths` in `phpstan.neon` until you can.

### The shared module ships no accounts, login routes or headers

- **Changed:** `modules/Shared` is now one file. It answers `/` and binds
  `DataSource`, and nothing else. Removed: `AccountProvider` (the `ada` and
  `grace` accounts and the fixed API token), `SessionEndpoints` with
  `POST /login`, `POST /logout` and `GET /me`, `GET /users`, the `User` model,
  `UserRepository`, `PaginationSchema`, the `user.list` and `user.impersonate`
  capabilities, the `member` and `administrator` roles, the `X-Engine` and API
  version headers (`ResponseFilters`, `ApiFilters`), the `money.php` template and
  the `shared.currency` and `shared.locale` settings.
- **Affected:** an application still logging in with the demo accounts or
  calling those routes; a module that imports `App\Modules\Shared\…` classes or
  requires `user.*` capabilities or those roles; a client or monitor that reads
  `X-Engine`. With no provider bound, nobody can log in and every protected
  route refuses.
- **Do:** bind your own `UserProvider` and write your login routes, as in
  [Users and permissions](docs/guides/users-and-permissions.md). If you kept
  local changes in `modules/Shared`, merge them into the new `module.php`.
  To keep anything removed, copy it from `tests/Fixtures/Showcase/Shared` and
  rename its namespace to match where you put it.

### Twig is required, and wins over PHP

- **Changed:** `twig/twig` moved from `require-dev` to `require`, and the Twig
  engine is registered before the PHP engine. Where one directory holds both
  `page.twig` and `page.php`, the Twig file now renders. The default template's
  `layout`, `errors/404` and `errors/error` are `.twig` files; the `.php`
  versions are gone.
- **Removed:** `TwigTemplateEngine::isAvailable()` and
  `TemplateException::twigIsNotInstalled()`.
- **Changed:** `TemplateManager::extensions()` lists extensions in engine order
  (`html.twig`, `twig`, `php`, `phtml`) rather than longest first.
- **Affected:** an install run with `composer install --no-dev` from an old lock
  file; a theme or module with both a `.twig` and a `.php` file for one name; a
  PHP page that rendered the old `layout.php` with `content`.
- **Do:** run `composer install` so the lock file picks up Twig. Delete whichever
  of a duplicate pair you do not mean. A PHP page can keep passing `content` to
  `layout` — `layout.twig` prints it when no block replaces it.

### `modules.cache` is gone; `cache:warm` replaces it

- **Removed:** the `modules.cache` setting. A request never writes the module
  discovery cache any more.
- **Affected:** a deployment that set `modules.cache` to `true`. The setting is
  now ignored, so that deployment scans module directories on every request.
- **Do:** add `php laika cache:warm` after `cache:clear` in the deployment.
  It refuses to run with `APP_DEBUG` on. Delete an old `system/Cache/modules.php`:
  its format changed, and it would be ignored anyway.

### A module can no longer filter `asset.response`

- **Changed:** declaring `$module->filter('asset.response', …)` stops boot with a
  message naming the module. An asset request loads no module, so such a filter
  could only ever have run in a test.
- **Affected:** a module that put access control or headers on assets.
- **Do:** serve a file that needs a permission check from a route, with
  `meta(['can' => …])`. Headers for every asset belong in the web server's
  configuration or in an engine listener attached at bootstrap.

### Module lifecycle additions

- **Added:** `ModuleContext::requires()` and `optionally()`, the
  `modules.disabled` setting, and a Resolve stage between Load and Register.
  `version()` now accepts only `MAJOR.MINOR.PATCH`.
- **Affected:** a module whose `version()` is `1.0` or `v1.2.3`; a module that
  uses another module's classes without declaring it (an architecture test now
  fails on that); code that called `ModuleRegistry::ids()` and expected disabled
  modules in the list.
- **Do:** write full versions; add `$module->requires('<id>', '^x.y')` where a
  test names the missing declaration; use `ModuleRegistry::installed()` to
  include disabled modules.

### Observability additions

- **Added:** `X-Request-Id` on every response and `X-Correlation-Id` when it
  differs; `trace`, `request_id` and `correlation_id` on every log record; the
  `observability.*` settings with `APP_PROFILE` and `SLOW_QUERY_MS`.
- **Changed, persisted:** a queued job's envelope gained `correlationId`. Jobs
  queued by the older code still load; the field is read as absent.
- **Affected:** a log pipeline that rejects unknown context keys; a proxy that
  already sets `X-Request-Id` and expects it to survive — incoming ids are
  ignored unless `observability.trust_incoming_ids` is on.
- **Do:** allow the three keys, or turn on `trust_incoming_ids` behind a gateway
  that sets the ids.

### Database behaviour that was wrong, now refused or corrected

- **Changed:**
  - `Connection::disconnect()` inside a transaction still closes, then throws.
    `ConnectionManager::disconnectAll()` closes every connection before throwing
    for the first that had one open.
  - On PostgreSQL, `Connection::insert()` returns the key only when the
    statement has a `RETURNING` clause, and null otherwise. It used to return
    `LASTVAL()`, which could be another table's key.
  - A float is bound with every digit it needs to read back unchanged: `0.1 + 0.2`
    is now stored as `0.30000000000000004`, not `0.3`.
  - Nested transactions on Oracle (`oci`) are refused, as on any driver without
    a grammar of its own.
- **Affected:**
  - a worker or script that called `disconnect()` with a transaction still open,
    which lost that work without a word;
  - code on PostgreSQL that calls `Connection::insert()` with hand-written SQL
    and reads the key it returns. Repositories are not affected: their inserts
    now write `RETURNING` themselves;
  - a test that compared a stored float with its fourteen-digit rounding;
  - an application on Oracle that nested `transaction()` calls.
- **Do:**
  - commit or roll back before disconnecting;
  - add `RETURNING id` to the INSERT, or use `$connection->table('t')->insert($row, 'id')`;
  - round deliberately where a rounded value is meant, or store money in a
    DECIMAL column;
  - on Oracle, flatten the nesting into one transaction.

### Database additions

- **Added:** the SQL query builder (`Connection::table()`), a grammar per
  dialect, `Capability`, transaction isolation levels and retries,
  connections configured by parts, and the `database.*` hooks. The statement
  observer also receives the number of bound values and of rows.
- **Affected:**
  - an observer that declares three parameters still works, because PHP drops
    the extra arguments. One that collects them all (`mixed ...$arguments`) now
    receives five;
  - the observer's time now runs until a read's rows have been fetched, so a
    slow-query threshold may catch a statement it missed before.
- **Do:** nothing for most applications. Count on five arguments in a variadic
  observer, and recheck a slow-query threshold that was tuned tightly.

### Migrations and seeders

- **Added:** the table builder (`Connection::tables()`, with `create()`,
  `alter()` and `drop()`), module migrations in `Database/Migrations/`, and
  seeders in `Database/Seeders/`. The commands are `migrate`, `migrate:status`,
  `migrate:rollback` and `db:seed`. `TestCase::migrate()` runs the migrations in
  a test.
- **Affected:**
  - an application that created its tables by hand. Its databases already hold
    tables that no migration recorded, so a migration that creates one of them
    fails with "already exists";
  - an application whose own table is called `migrations`. The runner keeps
    its records in a table of that name.
- **Do:**
  - write a migration for each table a module owns. Where a database may
    already have the table, make it `if (!$tables->exists('notes')) { ... }`,
    so the migration is recorded without failing. Then retire the install
    command or script that made the table;
  - if `migrations` is taken, set `database.migrations.table` in
    `config/database.php`.

### `session:table` is gone; `migrate` creates the session table

- **Removed:** `php laika session:table`, and `DatabaseStore::ddl()`.
- **Added:** while `session.store` is `database`, `migrate` runs the framework's
  own migration, `framework:2026_09_19_000000_create_sessions`, before any
  module's. The store also runs on SQL Server now: its statements go through the
  query builder, and its row lock through the new `lockForUpdate()`.
- **Affected:** a deploy script that ran `session:table`. A sessions table made
  from its statement stays as it is: the migration sees the table and records
  itself without touching it.
- **Do:** replace `session:table` in deploy scripts with `migrate`. When
  `session.connection` names a database other than the default, run
  `migrate --connection=<that connection>` as well.
