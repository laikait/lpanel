# Changelog

Every release, newest first. The format is
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html) as described in
[Versioning and releases](docs/contributing/releases.md#versioning-and-releases).

Breaking changes are listed under **Changed** or **Removed** and each one has a
matching section in [`UPGRADING.md`](UPGRADING.md). What is public, and how
public, is in [`STABILITY.md`](STABILITY.md).

## [Unreleased]

### Added

- **`php laika module:make <Name>`.** Creates `modules/<Name>/module.php`, the
  skeleton [Modules](docs/reference/modules.md#start-a-new-module) documents, and
  nothing else. The name is a valid module id, optionally with one group folder
  (`Plugins/Billing`: id `Billing`, loaded once `modules/Plugins` is in
  `modules.paths`: the command creates `config/modules.php` when there is none,
  and prints the line to add when there is one). It never overwrites,
  and a folder differing only by case counts as taken. `--with=http,model,database,seeder,templates,assets,lang`
  (or `all`) also writes one starter file per part you name (with `http` and
  `database` together, the handler lists the rows of the module's table), and nothing for
  parts you do not. It is the framework's single
  generator: there is still no `make:controller` and the architecture test that
  pins the command list now includes it.
- **OpenAPI descriptions.** Routes with `'openapi' => true` or an array of
  details (summary, tags, path/query/header parameters, a `Schema` body,
  responses) are described as an OpenAPI 3.1 document, with the schemas their
  handlers validate with and the 401/403/429 answers the framework's guards give
  on their behalf. `php laika openapi:export` prints it, and `openapi.route`
  serves it as an ordinary, guardable route; `security:check` fails when that
  route is open in production. Unmarked routes never appear. See
  [OpenAPI](docs/reference/openapi.md).
- **An API docs page.** `openapi.docs` (e.g. `/docs`) serves Swagger UI over that
  description, behind the same `openapi.meta` access rule. Swagger UI 5.33.1
  (Apache-2.0) is bundled with its `LICENSE` and `NOTICE` and served by the
  framework itself, so the page works offline and under the default CSP, and
  calls no third party.
- **Console switches for the API docs.** `php laika openapi:docs --enable|--disable`
  turns the page and its description on or off, and `php laika openapi:auth
  --enable [--can=…]|--disable` decides whether they need a login, without
  editing config. Written to `system/Runtime/openapi.json` like `down`, per
  machine, and winning over `config/openapi.php`; `openapi.runtime => false`
  ignores them.
- **Template themes.** `templates/<theme>/` and `templates/<area>/<theme>/`, each
  with views and an `assets/` folder, registered in `templates.themes` or by a
  `templates.theme_provider` class (which may read the database) and never
  discovered. `asset()->template('admin/dark', 'css/app.css')` reads only that
  theme's `assets/`; a route or group with `meta(['theme' => 'admin/dark'])`
  searches the theme's views before `templates/`, layouts and error pages
  included; `templates.error_themes` picks the theme for errors raised before a
  route matched. Emails always render without a theme, so a queued and an
  inline one are the same. See [Templates](docs/reference/templates.md#themes).

- **Sub-directory translations.** `setLocale('admin/bn')` reads
  `lang/admin/bn.php`, then `lang/admin/en.php`, then the files of `lang/`;
  `Localization::directory()` returns the directory. Only a caller can choose
  one, never a cookie or header.
- **Stricter requests and uploads.** Invalid UTF-8 or NUL bytes in the query,
  body or cookies are refused with 400, strings are normalised to NFC, JSON
  nesting is capped at 32, and `security.trusted_hosts` restricts the `Host`
  header. `UploadPolicy` now checks inside `store()`/`storeOn()`, decodes images
  and limits their pixels, fails when `fileinfo` is missing, and gains
  `checkAll()`; `UploadedFile::displayName()` gives a display-safe name.

- **Signed links.** `Routing\UrlSigner::signed('route.name', $parameters,
  '+7 days')` makes a URL whose path and query are signed by `Signer`; a route
  with `meta(['signed' => true])` refuses a missing or changed signature with 403
  and an expired one with 410. See [Routing](docs/reference/routing.md#signed-links).
- **Cache locks.** `$cache->lock('name', 60)` with `get()`, `block($wait,
  $callback)` and `release()`; every lock expires, and only its holder's token
  releases it. Built on a new `Cache\AtomicStore` (`add()`, `forgetIf()`) that
  the file store implements with `flock`, the database store with its primary
  key, and the array store in memory. See [Cache](docs/reference/cache.md#locks).
- **Plural translations.** A message may be an array of CLDR plural forms
  (`one`, `few`, `other`… and `=0`-style exact counts), and the `count`
  parameter picks one by the language's own rules through ICU — six forms for
  Arabic, `0` as `one` in Bengali. See
  [Localization](docs/reference/localization.md#plurals).
- **Encrypted cookies and model attributes.** `Cookie::encrypted($encrypter,
  'cart', $value)` and `$request->decryptedCookie($encrypter, 'cart')`, bound to
  the cookie's name; and `#[Encrypted]` on a model's string property, encrypted
  by the repository on every write and decrypted on hydration under the purpose
  `<Model>.<property>`. A value that does not decrypt is a `ModelException`. See
  [Models](docs/reference/models.md#encrypted-attributes).
- **Storage.** `$storage->disk('uploads')` with `put`, `get`, `readStream`,
  `exists`, `delete`, `size`, `lastModified`, `files`, `url` and
  `temporaryUrl`, over three drivers: `local` (rooted, symlink-safe, atomic
  writes; temporary URLs are signed links to the Shared module's new
  `GET /files/{disk}` route), `s3` (S3, R2, Spaces, B2, MinIO through the HTTP
  client, with AWS Signature V4 written in, multipart uploads above 16 MB and
  presigned URLs), and `memory`, with `Storage::fake()` for tests.
  `UploadPolicy::storeOn()` puts an upload on any disk, and `Signer::hmac()`
  computes an HMAC under a protocol's own key. New `storage` configuration and
  `STORAGE_DISK` and `S3_*` variables. See [Storage](docs/reference/storage.md).
- **Password reset and email verification.** `Auth\PasswordReset`
  (`request()`, `check()`, `reset()`) and `Auth\EmailVerification` (`send()`,
  `verify()`) mail a link and check its token; the routes and pages stay the
  application's. Tokens are stateless and signed: a reset link works once,
  because it is bound to the password hash it replaces, and a verification link
  to the address it was sent to. Requests give the same answer for every address
  and are rate limited per address and per IP. The provider opts in with the new
  `PasswordResettable` and `EmailVerifiable` interfaces. Links are built from
  the new `APP_URL` (`Routing\AppUrl`), never from the `Host` header. Hooks
  `auth.password_reset_requested`, `auth.password_reset` and
  `auth.email_verified`. See [Authentication](docs/reference/auth.md#password-reset-and-email-verification).
- **Social login.** Sign in with Google, Microsoft, Apple, GitHub, Facebook or
  any OpenID Connect issuer, configured under `auth.social.providers`; the
  Shared module declares `/auth/{provider}` and its callback. The
  authorization-code flow with PKCE, `state` and `nonce`, kept in an encrypted
  cookie so Apple's cross-site POST works. The `id_token` is verified against
  the issuer's published keys (RS/ES 256–512, written in as `Auth\Social\Jwt`)
  and its claims checked. An existing account is linked by email only when the
  provider says the email is verified. Providers opt in with `SocialAccounts`;
  the `social.account` filter has the last word. See
  [Authentication](docs/reference/auth.md#signing-in-with-google-microsoft-apple-github-or-facebook).
- **Receiving webhooks.** `Security\Webhooks::receive('stripe', $request)`
  verifies a delivery against `security.webhooks` and returns its id, type and
  payload: Stripe, GitHub, Shopify, Standard Webhooks (Svix, Resend, Clerk) and a
  configurable HMAC, with secrets that may be rotated. A signed timestamp
  outside five minutes is refused, and an id already received comes back as a
  duplicate, through the cache's atomic `add()`. See
  [Security](docs/reference/security.md#receiving-webhooks).
- **Two-factor login.** `Auth\TwoFactor` puts an authenticator-app code
  between the password and the login: `attempt()` returns `LoggedIn`,
  `ChallengeRequired` or `Failed`, and `challenge()` takes the code or a
  recovery code, five tries within five minutes. Turning it on is `begin()`
  (a secret and its QR code) then `confirm()`, which returns ten recovery codes
  that are stored hashed. `Auth\Totp` implements RFC 6238 and passes its test
  vectors; a used code is refused. `Support\QrCode` draws QR codes as SVG
  with no library, matching an established encoder module for module. Providers
  opt in with `TwoFactorAccounts`. New `AuthManager::verify()`, `attempt()`
  without the login. See [Authentication](docs/reference/auth.md#two-factor-login).
- **Feature flags.** A new `features` configuration section: a flag is true,
  false, or rules (`accounts`, `roles`, `percent`), and any one rule switches it
  on. A percentage rollout is stable per person and only grows as it is raised.
  Use `Feature\Features::active()`, `feature()` in templates, or
  `meta(['feature' => …])` on a route, which answers 404 while the flag is off.
  The `feature.active` filter has the last word. Rules that do not parse fail
  loudly. See [Feature flags](docs/reference/features.md).
- **More health checks.** `GET /health` also checks mail (an SMTP greeting,
  EHLO and STARTTLS with no login, through the new `SmtpTransport::probe()`),
  storage (a file written and read back on the default disk or
  `Shared.health.disks`) and maintenance mode. Mail and storage answers are
  reused for five minutes. A new `warn` status covers a queue over
  `Shared.health.queue_backlog` and maintenance mode, and reports without
  failing. A module adds its own check with the `health.checks` filter. See
  [Running](docs/operations/running.md#watching-it).

- **Encryption.** `Security\Encrypter` hides a value and detects tampering:
  libsodium XChaCha20-Poly1305 with a fresh nonce each time, a purpose bound into
  every token, and a key derived from `APP_KEY` through HKDF so it is never the
  signing key. `decrypt()` returns `null` for anything wrong. `APP_PREVIOUS_KEYS`
  lets `APP_KEY` rotate (decrypt only), and `security:check` warns while one is
  set. See [Security](docs/reference/security.md#encryption).
- **An HTTP client.** `Http\Client\Client` sends requests to other services:
  JSON and form bodies, base URL, tokens, timeouts, redirects followed by the
  client (credentials dropped when they leave the host), retries for failures
  that might pass (never a POST unless asked), and `publicOnly()` to refuse
  private addresses for URLs a user supplied. curl when loaded, PHP's stream
  wrapper otherwise; `FakeTransport` for tests. `http.client.request` and
  `http.client.sent` are its extension points. The framework updater now uses
  it. See [HTTP client](docs/reference/http-client.md).
- **Mail.** `Mail\Mailer` sends a `Message` now or through the queue
  (`queue()`), with the body from text, HTML or a template view (`<view>` and an
  optional `<view>.text`), attachments, and line breaks in headers refused.
  Transports: SMTP (STARTTLS required when asked for, AUTH PLAIN/LOGIN),
  sendmail through the system command executor, `log` — the default, which
  sends nothing — and `array` for tests. `mail.message`, `mail.allowed` and
  `mail.sent` are its extension points, and `security:check` warns when
  production only logs mail. See [Mail](docs/reference/mail.md).
- **Maintenance mode.** `php laika down` makes every web request a 503 with
  `Retry-After` and a new `errors/503` page showing `--message`; assets are
  still served. `--allow` lets addresses or CIDR blocks in (through the
  trusted-proxy rules), and `--secret` gives a `/?lphp_bypass=<secret>` link
  that sets a cookie for one browser, valid for that maintenance window only.
  `php laika up` ends it; the console, workers and scheduler are unaffected, and
  `security:check` warns while the application is down. See
  [Running](docs/operations/running.md#maintenance-mode).

### Changed

- **Every public API is Stable.** Everything [`STABILITY.md`](STABILITY.md)
  marked Experimental is now Stable: changing it takes a deprecation in a minor
  release and a major version. Internal classes and persisted formats are
  unchanged, and the Experimental level stays available for new additions.
- **`@assets/…` is not a template name.** Its override folder would be
  `templates/assets/`, which holds static files. Breaking only for a module
  whose id is `assets`: see [`UPGRADING.md`](UPGRADING.md).

### Fixed

- **An asset's `?v=` follows the file without `cache:clear`.** The cached
  content hash is keyed by the file's size and modification time too, so a
  deploy that forgot to clear the cache no longer keeps sending the old token
  for a URL browsers cache as immutable for a year.
- **In debug, Whoops is the only error page.** `display_errors` is off in debug
  too, so PHP no longer prints a fatal (out of memory, timeout) ahead of Whoops
  or instead of it; out-of-memory and timeout fatals get the headroom to render
  and a 500 instead of a 200; the handler is installed before `.env` and
  `config/*.php` are read, so a file that throws there gets Whoops too; output a
  handler printed before failing is discarded; and when Whoops cannot render,
  the plain page says why (an unknown `APP_EDITOR`, for example). See
  [Errors](docs/reference/errors.md#the-debug-page-whoops).

### Security

- **Logins are throttled by the framework.** `AuthManager::attempt()` and
  `verify()` count failed passwords per account and address (`5/15m`), per
  address (`30/15m`) and per account (`100/1h`), and answer 429 past a limit
  before checking the password. Before, a login route without `rate_limit`
  allowed unlimited guessing. See [Authentication](docs/reference/auth.md#logging-in).
- **A missing account takes as long as a wrong password at any cost.** The
  fixed dummy hash was bcrypt cost 10, so with a higher cost (or PHP 8.4's
  default of 12) a missing account answered about four times faster, which told
  a stranger which accounts exist.
- **The request body is read no further than the limit**, so a chunked body
  with no `Content-Length` is refused with 413 rather than read whole.
- **A `Host` header that is not a host name is refused with 400**, whatever
  `trusted_hosts` says.
- **No store keeps a session id.** The database store's `id` column, the file
  store's names and every stored record hold the id's SHA-256.
- **Two-factor codes and recovery codes are claimed atomically**, so one code
  sent by two requests at once logs in once.
- **Webhook replays are caught with the default cache.** When the cache
  forgets between requests (the `array` store), delivery ids are remembered
  in `system/Cache/webhooks/` instead, so a replay is a duplicate rather than
  new. Before, de-duplication was silently off.
- **The HTTP MCP endpoint checks `Origin` against `APP_URL`** when it is set,
  which DNS rebinding cannot satisfy; `security:check` fails when the endpoint
  is on with neither `APP_URL` nor `trusted_hosts`.
- **HTML pages get a Content-Security-Policy** while debug is off and none is
  configured (scripts from this origin only), and every response gets
  `Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()`.
- **`X-Engine: LPHP` is no longer sent**, so responses do not name the framework.
- **`security:check` fails a production deployment without `APP_KEY`**, which
  leaves CSRF unsigned and cache and queue entries unsealed.
- **The local disk refuses paths Windows reads differently**: `:`, a segment
  ending in `.` or a space, and device names such as `CON`.
- **S3 objects are stored as `application/octet-stream` unless their type cannot
  run script**, so an uploaded page cannot run on the bucket's origin.
- **Smaller fixes:** uploads whose contents cannot be identified are refused;
  `Message::header()` refuses the headers the mailer writes, in any case; SMTP
  refuses to send a password unencrypted to another machine; the SSRF guard
  treats NAT64, 6to4 and IPv4-compatible addresses as non-public; the
  maintenance bypass limits wrong guesses, needs a 16-character secret and can
  no longer redirect off-site; static files get `nosniff` from
  `public/.htaccess`; the generated nginx block refuses dotfiles under
  `/assets/core/`; systemd unit values refuse line breaks; the development
  server never serves a Windows alias of a PHP file.
- **Social sign-in no longer skips two-factor.** `SocialLogin::login()` called
  `AuthManager::login()` directly, so an account with two-factor turned on was
  logged in by the provider alone — or by anybody who opened a provider account
  under the same verified email. It now goes through the new
  `TwoFactor::start()`; the new `SocialLogin::attempt()` answers a
  `TwoFactorResult`, and the shipped callback sends such an account to the route
  named by `auth.two_factor.route`. See
  [Authentication](docs/reference/auth.md#signing-in-with-google-microsoft-apple-github-or-facebook).
- **Logging in leaves no followable session pointer.** The id from before a
  login used to lead, for `session.grace` seconds, to the session the login
  made, handing it — and its new id — to whoever had planted that id.
  `AuthManager::login()` now regenerates with `followable: false`, and
  `Session::regenerate()` / `SessionManager::regenerate()` take that argument.
  `session.grace = 0` used to skip the time check and follow a pointer
  indefinitely; it now follows none.
- **The CSRF cookie is `__Host-XSRF-TOKEN` over HTTPS.** A signed token proves
  this application issued it, not to whom, so a sibling subdomain or a
  plain-HTTP man-in-the-middle could plant a token they had been issued
  themselves. `security.csrf.host_prefix = false` restores the old name.
- **Two-factor codes are limited per account**, ten in fifteen minutes across
  every session (`TwoFactor::LIMIT_PER_ACCOUNT`), because typing the password
  again reset the session's five tries. `auth.two_factor_throttled` is new.
- **Cache entries and queued jobs are sealed before they are unserialized.**
  With `APP_KEY` set, the file and database cache stores and the queue store an
  HMAC (new `Support\Sealer`, a key derived from `APP_KEY` per purpose) beside
  each `serialize()`d value, and refuse anything that does not carry a valid one
  — a cache miss, or a failed job — without unserializing it. Somebody able to
  write the cache directory, the cache table or the queue could otherwise run
  code through an object's `__wakeup` or `__destruct`.
- **A route with `'csrf' => false` ignores the session cookie.** Only a bearer
  token identifies a request there, because with the check off a session would
  let any site act as whoever is logged in. Not applied when CSRF is switched
  off everywhere.
- **`Client::publicOnly()` connects to the address it checked**
  (`ClientRequest::$address`, `CURLOPT_RESOLVE` and its stream equivalent), so
  DNS rebinding cannot swap in a private address between the check and the
  connection; a URL with a user part or a backslash is refused.
- **`security:check` warns** in production when operating-system commands may
  run any executable (`system.commands.allowed` is null).
- **Responses send `X-Engine: LPHP` and no `X-Powered-By`.** PHP's own
  `X-Powered-By: PHP/<version>` is removed, so a response no longer advertises
  the interpreter's version; `http.headers` changes or removes `X-Engine`.

## [3.0.1] - 2026-10-01

### Changed

- **`Request::ip()` reads `X-Forwarded-For` from the right.** The client is the
  first address, reading leftwards, that is not a trusted proxy, instead of
  whatever the client wrote at the left-hand end; an entry that is not an
  address stops the reading, and a port is dropped. With one proxy and one
  entry nothing changes. Breaking for a chain of proxies: see
  [`UPGRADING.md`](UPGRADING.md#requestip-reads-x-forwarded-for-from-the-right).

- **`chunk()` walks by key, not by offset.** Each batch starts after the last
  row of the one before, in the query's order with the key added last, so every
  batch costs the same and a callback that deletes or updates rows no longer
  makes the walk skip or repeat any. A chunk always has a total order now.
- **Modules are flat.** A module is any folder under `modules/` with a
  `module.php`, named whatever its author likes; its id is the folder name
  (`Billing`, `Shared`). The plugin and gateway kinds, `modules/Plugins/` and
  `modules/Gateways/` are gone, so `cache:warm` no longer records roots that do
  not exist. Templates are `@Billing/`, assets `/assets/module/Billing/` through
  `asset()->module()`, configuration `config/Billing.php`, translations
  `Billing.key`. `modules.paths` is a list of places to look, and an entry with
  its own `module.php` is a single module. Breaking: see
  [`UPGRADING.md`](UPGRADING.md#modules-are-flat-modulesname-no-plugins-or-gateways).

### Added

- **Updating the framework.** An application is a copy of the framework, so
  `composer update` never brought a new version. `php laika framework:update`
  does: it fetches a release from GitHub (or `--from` a zip), checks its
  SHA-256, and decides file by file from `framework.json` — the manifest every
  release now ships — what it may replace. Framework files you have not edited
  are replaced, added or deleted; an edited one stops the update with nothing
  changed (`--force` overrides it); `modules/`, `templates/`, `lang/`,
  `config/` and `public/assets/` are never replaced, only added to, with a
  release's changed copy written as `<file>.dist`; `composer.json` is merged; a
  file in no manifest is never touched. Everything replaced is backed up first,
  and `framework:rollback` restores it. `--check` and `--dry-run` change nothing.
  The release workflow now builds `lphp-vX.Y.Z.zip` from `git archive` with its
  `framework.json` and checksum, and `CHANGELOG.md` and `UPGRADING.md` ship to
  applications. See [Updating the framework](docs/operations/updating.md).

- **systemd units for the queue and the scheduler.** `system:systemd:generate`
  prints (or `--write`s) a `<name>-worker@.service` template, one worker per
  queue, and a `<name>-scheduler.service` with a minutely `.timer` that replaces
  the `schedule:run` cron line. `system:systemd:install` writes them to
  `/etc/systemd/system`, reloads systemd and starts them, as root and safely on
  every deployment; it refuses to start the timer while the cron line is
  installed (`--replace-cron`, `--no-scheduler`). `system:systemd:remove` stops,
  disables and deletes them. Built on `SystemdUnits` and `SystemdManager`.

- **IP addresses.** `App\Engine\Network`: `IpAddress` (IPv4 and IPv6 parsing,
  RFC 5952 formatting, public/private/loopback/link-local/multicast/reserved
  classification, IPv4-mapped conversion, masking and anonymizing, arithmetic,
  integer, binary and reverse-DNS forms), `Cidr` (bounds, hosts, size,
  membership, overlap, listing the addresses in a block, splitting into
  subnets, summarizing a range), `Netmask`, `IpSet` for allowlists, and the `Ip`
  one-liners. Pure PHP: no GMP or BCMath. `http.trusted_proxies` accepts CIDR
  blocks of either version, `Request` gains `ipAddress()` and `ips()`, and
  `security:check` fails on a trusted proxy entry it cannot read or on one that
  trusts every address.

- **Localization.** Translations as PHP files: the application's in `lang/`,
  each module's in its own `lang/`, keyed `shared.*`, `plugin.<Name>.*` and
  `gateway.<Name>.*`. The Twig `local` filter, `TemplateView::local()` and an
  injectable `Localization` service with `:name` parameters. The locale comes
  from the `language` cookie, then the visitor's country through
  `lang/countries.php`, then `Accept-Language`, then `en`; country detection
  reads a CDN header only from `http.trusted_proxies`
  (`localization.country_header`) and is replaceable through
  `CountryResolver`. A local MaxMind GeoLite2/GeoIP2 Country or City
  database (`localization.maxmind_database`, optional `maxmind-db/reader`)
  answers after the header. Localized responses carry `Vary`. See
  [Localization](docs/reference/localization.md).
- `Request::fromTrustedProxy()` is public.
- **`MEMORY_LIMIT`** (`app.memory_limit`) sets PHP's `memory_limit` at boot, in
  PHP's notation; a value PHP would not understand, or one below what the
  process already uses, stops the boot. `queue:work --memory=128M`
  (`QUEUE_MAX_MEMORY`) stops a worker between jobs at that size, and defaults to
  80% of `memory_limit`.
- **`GET /health`** in the Shared module: database, cache, queue and disk
  checks as JSON, 200 or 503, for load balancers and uptime monitors.
- **Cursor pagination.** `Query::cursor()` and `cursorInto()` return a
  `CursorPage` with `nextCursor()` and `previousCursor()`: keyset pagination
  that seeks through the index instead of skipping with `OFFSET`, costs the
  same at any depth and runs no count. See
  [Two ways to paginate](docs/reference/data.md#two-ways-to-paginate).
- **`MAX_EXECUTION_TIME`** (`app.max_execution_time`) sets how long a web
  request may run, in seconds. Console commands and queue workers keep their own
  limits.
- **Whoops debug page.** With `APP_DEBUG` on and `filp/whoops` installed (a dev
  dependency), a browser error renders as a Whoops page, with cookies,
  environment values and secret-looking fields masked and file links for
  `APP_EDITOR`. Without it, or if it fails, the built-in page renders as
  before. See [Errors](docs/reference/errors.md#the-debug-page-whoops).

## [2.1.2] - 2026-09-20

The first release. Every public API in it is **Experimental**: a minor release
may change it, always with an upgrade note. Nothing is Stable yet — see
[`STABILITY.md`](STABILITY.md) for why, and for what is expected to be promoted.

### Added

- **Modules.** Discovery of `shared`, `plugins/*` and `gateways/*`; `module.php`
  closures over `ModuleContext`; a Discover → Load → Resolve → Register → Boot
  lifecycle replayed by category; dependencies with version constraints,
  optional dependencies and `modules.disabled`.
- **Kernel.** One bootstrap for HTTP and the console; a pure `HttpKernel`; a
  container with autowiring and a write-only `ServiceRegistrar`; a two-tier router
  (hash map, then segment trie); a dispatcher taking three handler forms.
- **Hooks and filters** with deterministic ordering, snapshot iteration, a
  recursion cap and a debug-mode null guard, and ten closed global helpers.
- **Model, schema, data and database** as separate layers: change-tracked models
  with explicit relations and read models; typed schemas that validate,
  serialise and deserialise; repositories and lazy immutable queries over a
  five-method `DataSource`; `ArraySource` and `SqlSource`; `BulkWrites`;
  connections with savepoint-nested transactions.
- **Assets** through four URL namespaces, served from inside the denied module
  tree with traversal, extension and symlink checks, content-hash versioning and
  manifests; an asset request boots no module.
- **Templates**, engine-neutral, with Twig and PHP engines, module namespaces
  and a site override rule. `templates/` ships a Twig layout, a home page for
  `/` and error pages for 404 and every other status.
- **REST** conventions on the same kernel: content negotiation with quality
  values, one JSON error document, `ApiResponse`, and prefix versioning whose
  route metadata an application's own `dispatch.response` filter turns into
  `Deprecation` and `Sunset` headers.
- **Console** with declared arguments and options, typed binding, meaningful exit
  codes and generated help; 35 framework commands, none of which writes code.
- **Errors** rendered by audience (browser, API, console) with disclosure rules,
  and **logging** as a listener, with redaction, retiring writers and file,
  stream, syslog and database destinations.
- **Configuration** from defaults, `config/*.php` and the environment, with a
  cache that notices environment changes; a **cache** with array, file,
  database and null stores.
- **Queue and worker** with sync, memory, file and database stores, retries with capped
  exponential backoff and a failed list; a **scheduler** driven by one cron line,
  with overlap locks.
- **Security**: opt-out CSRF with signed tokens, rate limiting, request size
  limits, upload policies, security headers, `Secret`, `security:check`.
- **Sessions** without `$_SESSION`: lazy, change-logged commits, regeneration
  with a grace window, file, database and memory stores.
- **Authentication and authorization**: a two-lookup `UserProvider`, session and
  bearer-token authentication, capabilities and roles as data, and a decision
  filter that may refuse but never grant.
- **Performance**: `cache:warm` as the production boot path, and `composer bench`
  covering every subject in specification §50.
- **Observability**: request and correlation ids across the queue, log
  enrichment, an opt-in profiler reporting to the log and `Server-Timing`, and
  slow-query warnings.
- **Documentation**: `docs/` — a getting-started tutorial, task guides for
  application developers, a reference page per subsystem, operations and
  troubleshooting pages, and a contributors' guide — plus `STABILITY.md`,
  `UPGRADING.md` and this file.
- **`nginx:make`** writes an nginx server block to `nginx.conf`, rooted at
  `public/`.
- **System operations** (`engine/System`): structured command execution with no
  shell, explicit bash scripts, background processes, owned crontab blocks,
  systemd services, a policy-bound server filesystem, permissions, and system
  information. Deny by default throughout: command allowlists, service and
  filesystem policies, `system.*` capabilities, a concurrency limit and an HTTP
  timeout cap. Every change and refusal is audited to the `audit` log channel.
  Configured under `system.*`; six `system:*` console commands. See
  [System operations](docs/reference/system.md).
- **MCP** (`engine/MCP`): Model Context Protocol tools, resources and prompts
  that modules declare in `module.php` with `$module->mcp()`, served over STDIO
  (`mcp:stdio --user=`) and, when `mcp.transports.http` is on, over HTTP with
  bearer tokens only. Permissions are auth capabilities, and a refused
  capability is indistinguishable from a missing one. Tool input is checked
  against a strict JSON Schema subset that fails closed. Results are plain data
  built explicitly. `mcp.*` hooks and filters never run ahead of the security
  checks. The `mcp` log channel records calls without their payloads.
  `mcp:list` shows what is exposed. Configured in `config/mcp.php`, where
  everything is opt-in except STDIO, which opens nothing. See
  [MCP](docs/reference/mcp.md).
- **A dialect per database.** `MySqlGrammar`, `PostgresGrammar`,
  `SqliteGrammar` and `SqlServerGrammar` each override only where their database
  differs — quoting, paging, savepoints, the placeholder limit — and any other
  driver gets standard SQL. `Connection::supports(Capability::Savepoints)` says
  what a database can do. Only the savepoint capability exists so far; each
  later feature adds its own. The dialect tests run on every database a test run
  can reach: SQLite always, and MySQL, PostgreSQL or SQL Server when
  `DB_TEST_*_DSN` names one. CI starts all three.
- **A SQL query builder.** `$connection->table('invoices')` returns an immutable
  `QueryBuilder` with `select()`, `where()`/`orWhere()` (including groups in a
  closure), `whereNull()`, `whereIn()`, `whereBetween()` and their negations,
  `orderBy()`, `limit()` and `offset()`. It runs nothing until `get()`,
  `first()`, `cursor()`, `count()` or `exists()`. Values are always bound. Names
  such as `orders.customer_id` or `total AS amount` are checked part by part.
  Operators and directions come from a fixed list. `RawExpression` is the one
  place hand-written SQL goes, with its own bindings. `Data\Query` is
  unchanged: AND only and no joins, the same on every `DataSource`.
- **Writes through the query builder.** `insert($row, $key)` returns the
  generated key; `insertMany()` counts what it wrote; `update()` and `delete()`
  refuse to run without a condition (`updateAll()` and `deleteAll()` say "every
  row" explicitly); `upsert()` where the database has one. A write refuses order,
  limit, offset and aliases, which not every database can honour. Rows of a
  many-row write must name the same columns. `RawExpression` values are
  accepted in single-row writes. New capabilities `Returning` and `Upsert`.
- **Joins, grouping and aggregates in the query builder.** `join()`,
  `leftJoin()` and `rightJoin()` (where `Capability::RightJoin` allows), with ON
  conditions in a `JoinClause`. Also `whereColumn()`, `groupBy()`, `having()` and
  `orHaving()`. `Aggregate` is COUNT, SUM, AVG, MIN or MAX of a checked column:
  selectable, usable in `having()`, and available as the `sum()`, `avg()`,
  `min()` and `max()` terminals. `count()` on a grouped query counts the groups.
- **Transaction isolation levels and retry.** `transaction($callback,
  isolation: IsolationLevel::Serializable, retries: 3)`. Each dialect sets the
  level where its database needs it (before `BEGIN` on MySQL and SQL Server,
  resetting SQL Server's session afterwards; inside the transaction on
  PostgreSQL). A level the database cannot give is refused, never substituted.
  Retries fire only on deadlocks and serialization failures (SQLSTATE 40001 or
  40P01, and MySQL 1213), with a short jittered backoff.
  `Connection::isRetryable()` makes the same judgement. Both options belong to
  the outermost transaction only.
- **Migrations.** Each module keeps its own in `Database/Migrations/`, one file
  per change, named `YYYY_MM_DD_HHMMSS_what_it_does.php` and returning a
  `Migration` (or a `Reversible`, with a `down()`) written with the table
  builder. `migrate` runs what is pending, in module dependency order and then
  file order, as one batch; `--pretend` prints each migration's SQL for this
  database and runs nothing. `migrate:status` lists every migration, and
  `migrate:rollback` undoes the last batch (or `--batches=N`) newest first,
  refusing before it starts if anything in range has no `down()` or no file,
  and asking for `--force` in production. Where the database rolls structure
  back, a migration and its record commit together; MySQL's partial failures
  are reported as such. A lock in the database lets only one run migrate at a
  time, across machines. The tracking table is `database.migrations.table`
  (`migrations`). In a test, `$this->migrate($app)` runs them. The getting-started
  tutorial and the storing-data, testing and users guides now create their tables
  with migrations, and migrations are gone from "What is not built".
- **Seeders.** A module's `Database/Seeders/*.php` files each return a
  `Seeder`, whose `run(Connection $db)` writes through the query builder.
  `db:seed` runs them in module order and then file order, or one module's with
  `--module=<id>`, and asks for `--force` in production. Every seeder is loaded
  before any runs, and each runs in its own transaction. Nothing records that a
  seeder ran, so each should look before it inserts.
- **`migrate` creates the session table.** While `session.store` is
  `database`, the framework's own migration runs before any module's and makes
  the table under `session.table`. A table made earlier by hand is kept and
  recorded. `session:table` and `DatabaseStore::ddl()` are gone. The store now
  writes through the query builder and runs on all four databases, SQL Server
  included; its conformance suite runs on each in CI.
- **A database log writer.** Naming `database` in `logging.writers` writes each
  record as a row: `logged_at` in UTC, the level as its RFC 5424 code and its
  name, the channel, the message and the context as JSON. Its table comes from
  `migrate` (`logging.database.table`, `logs`, on `LOG_CONNECTION`), and
  `retention_days` deletes older rows. On MySQL, PostgreSQL and SQL Server it
  writes through a connection of its own, so an application rolling back does
  not take the record of why with it; on SQLite, with one writer at a time, it
  shares the application's. Tested on all four databases, in CI too.
- **Database cache and queue stores.** `CACHE_STORE=database` keeps entries in a
  table every machine shares, and `QUEUE_STORE=database` keeps jobs in one that
  workers on any number of machines take from. Their tables come from `migrate`,
  like the session table: `cache.table` (`cache`) and `queue.table` (`jobs`), on
  `cache.connection` and `queue.connection`. A job is claimed by a locked read
  and then an `UPDATE` that repeats "not reserved", so two workers never take the
  same one. Both are built on the query builder, pass their conformance suites
  on MySQL, PostgreSQL, SQLite and SQL Server, and run on each in CI.
  `cache:clear --expired` sweeps the database store as it does the file store,
  through the new `PrunableStore` interface.
- **Row locks in the query builder.** `lockForUpdate()` keeps the rows read
  locked until the transaction ends: `FOR UPDATE` on MySQL and PostgreSQL,
  `UPDLOCK` on SQL Server, nothing on SQLite. It is refused outside a transaction
  and on grouped queries.
- **String keys in the table builder.** `->primary()` makes a column the table's
  key, for a table whose key is not a counted `id()`.
- **A table builder.** `$connection->tables()->create('invoices', fn (Table $t) => ...)`
  describes a table once with chained methods (`id`, `integer`, `bigInteger`,
  `decimal`, `string`, `text`, `boolean`, `date`, `dateTime`, `binary`,
  `timestamps`; `nullable`, `default`, `unique`, `index`; foreign keys with
  their actions). Each dialect writes its own DDL, and what some database
  cannot do is refused before anything runs. MySQL tables are InnoDB and
  utf8mb4, and SQL Server's unique indexes admit many NULLs, as elsewhere.
  `drop()`, and `raw()` for hand-written SQL scoped to named drivers. New
  capability: `TransactionalDdl`.
  `alter()` changes a table that exists: it adds columns, indexes and foreign
  keys, and drops or renames columns and drops indexes and keys by the names
  the builder gave them, in a fixed order (drops, then renames, then additions).
  A column added to a table with rows needs `nullable()` or a default. What
  SQLite's `ALTER TABLE` cannot do (dropping a foreign key, or adding one to a
  column that is already there) is refused with nothing run, never done by
  rebuilding the table. Renaming a column needs MySQL 8.0 or MariaDB 10.5.2;
  on an older server the rename is refused with nothing run, not left to fail
  as a syntax error.
- **`database.*` hooks.** An application now fires
  `database.query.failed`, `database.transaction.committed`,
  `database.transaction.rolled_back` (with its cause) and
  `database.transaction.retrying` (with the attempt about to run). The database
  layer announces these through `Connection::listen()` and
  `ConnectionManager::listen()`, and the bootstrap turns them into hooks, so the
  layer still works without the application. Hooks fire once the event has
  happened: a listener that throws cannot undo a commit, trigger a retry, or
  replace a database failure.
- **The statement observer counts.** `observe()` callbacks also receive how many
  values were bound and how many rows came back or changed, never the values.
  Observers written for three arguments still work. The slow-query warning logs
  both counts.
- **A database connection may be configured by its parts.** In
  `config/database.php`, `driver`, `host`, `port`, `database` and `charset` are
  assembled into the DSN for mysql, pgsql, sqlite and sqlsrv; a `dsn` still wins
  when given, and is required for any other driver. A part containing `;` (or,
  for a host, `,`) is refused rather than escaped.

### Changed

- **`templates/` is the site's views, with no switchable themes.** A name is a
  path under it: `render('customer/profile')` is `templates/customer/profile.twig`,
  and a module override is `templates/plugin.<Name>/…`. The site's static files
  are `templates/assets/`, still served as `/assets/template/…`, and are never a
  view. `APP_TEMPLATE` and `templates.active` are gone.

- **The document root is `public/`.** `index.php` and the application's own
  `assets/` moved into it; everything else — `engine/`, `modules/`, `config/`,
  `vendor/`, `.env` — is out of reach of any URL, so the lists of directories and
  files that `.htaccess`, the development router and `nginx:make` used to deny
  are gone. Where the document root cannot be changed, the project's `.htaccess`
  forwards every request into `public/`, and URLs keep their old form.
  `composer serve` runs `php -S 127.0.0.1:8080 -t public server`, and
  `security:check` fails if `public/` holds PHP besides `index.php`.
- **The console is `laika` at the project root**, not `bin/console`: run
  `php laika <command>`. Every message, help screen and generated cron line
  names the new path.
- **Paths in any language route reliably.** The request path and every declared
  route path are normalised to NFC, so both spellings of `é` or Bengali `য়`
  reach the same route; route constraints are matched as UTF-8, so `\p{L}` means
  a letter in any script; `url()` percent-encodes fixed segments outside ASCII as
  it already did parameters. `ext-intl` is now required.
- **PHP 8.2 or newer is required.** Support for 8.1, which reached end of life
  in December 2025, is dropped.
- **Module directories are `modules/Shared`, `modules/Plugins` and
  `modules/Gateways`**, autoloaded through one PSR-4 root, `App\Modules\` →
  `modules/`. Directory names now match their namespace segment, which Linux
  requires.
- **The shipped `shared` module is the front page and nothing else.** It
  answers `/` and binds `DataSource`. The demo accounts (`ada` and `grace`,
  password `secret`, and a fixed API token that logged in as an administrator),
  `POST /login`, `POST /logout`, `GET /me`, `GET /users`, the `member` and
  `administrator` roles, the `X-Engine` and API version headers, and the
  `money.php` template are gone. A fresh installation has no accounts: nobody
  can log in until an application binds its own `UserProvider`. The demo lives
  on in `tests/Fixtures/Showcase/Shared`, where the tests use it.
- **`composer stan` analyses `modules/`**, at the same level 8 as `engine/` and
  `tests/`. It never did, so an application's own modules were not
  type-checked by the gate at all.
- **A statement's observed time includes reading its rows.** For `select()`,
  `selectOne()` and `scalar()`, the observer and the slow-query warning now
  time until the rows are fetched, not only until the statement ran. A cursor
  is still timed until it runs.
- **Any path may be a route**, including `/templates` or `/config/app`: with the
  document root at `public/`, no path names a file outside it, and a real file
  answers exactly like a missing one.

### Fixed

- **Binary data could not be written on SQL Server.** A stream was sent as
  text, and SQL Server refuses text in a `VARBINARY` column. It is now bound
  with the driver's binary encoding, and comes back byte for byte, as on the
  other three databases.
- **A new visitor's first form was refused as a CSRF mismatch.** With no
  `XSRF-TOKEN` cookie yet, every call to `Csrf::token()` signed a new token, so
  a handler that put one in its form and the guard that set the cookie handed
  the browser two different ones. `token()` now issues one token per request,
  which the form and the cookie share, and forgets it when the next request
  begins. After a login, a page rendered in the same response carries the
  rotated token that its cookie does.
- **Inserts on PostgreSQL could report another table's key, or abort the
  transaction.** PDO's last-insert id there is `LASTVAL()`: the last value of
  whichever sequence the session used. So it was stale after an insert into a
  table without a sequence, and inside a transaction that had used none, it
  raised an error that aborted the whole transaction. Repositories on
  PostgreSQL were affected. The generated key now comes back from the INSERT
  itself (`RETURNING`, and `OUTPUT INSERTED` on SQL Server).
  `Connection::insert()` never asks PostgreSQL for `LASTVAL()`: without
  `RETURNING` it returns null.
- **SQL Server queries with a limit or offset failed.** The grammar wrote
  `LIMIT`, which SQL Server does not have; it now writes `OFFSET … FETCH`, with
  `ORDER BY (SELECT NULL)` when the query has no order of its own.
- **Nested transactions failed on SQL Server**, which saves a transaction rather
  than setting a savepoint and has no release. Savepoint statements are now the
  grammar's, in each driver's dialect. Oracle is no longer claimed to support
  them; its savepoints have no release either, and none are written for it.
- **A failing rollback replaced the exception that caused it.** The callback's
  exception now always propagates from `transaction()`. A failed rollback closes
  the connection, so the database discards what was not committed. Any outer
  level still open is refused every statement and commit until it too has
  rolled back, where before it could go on writing in autocommit mode.
- **`transaction()` committed the wrong level after an unbalanced callback.** A
  callback that called `begin()` without finishing it, or finished a transaction
  it had not begun, is now rolled back and reported.
- **`disconnect()` inside a transaction discarded it silently.** It still
  closes, and then throws, naming the connection and the depth.
  `disconnectAll()` closes every connection before reporting.
- **Floats lost digits and dates could not be bound.** A float was written at
  PHP's `precision`, so `0.1 + 0.2` was stored as `0.3`; it is now bound with the
  digits it needs to read back unchanged, under any locale. `DateTimeInterface`
  values bind as `Y-m-d H:i:s[.u]`, stream resources as binary large objects,
  and anything else unbindable is refused by position and type, never by value.
- **`composer serve` routed some paths to the home page.** For a path naming a
  real directory (`/templates`) or looking like a file (`/customers.json`), PHP's
  built-in server reported the path as the script name, so the whole path became
  the base path. The development router now sets `SCRIPT_NAME` to `/index.php`.

### Decided against the specification

- **Twig is a required dependency and the default engine**, where §25 and
  invariant 14 call it optional. The project owner's decision: the default
  template's pages are Twig. PHP templates still render through the same
  manager, and the manager itself does not depend on Twig.
- **`index.php` and the application's `assets/` live in `public/`**, where the
  specification's layout puts them at the project root. The project owner's
  decision: a document root holding nothing but the front controller cannot
  expose source through a forgotten deny rule, and a server that cannot change its
  document root still works through the forwarding `.htaccess`.

[Unreleased]: https://github.com/laikait/lphp/compare/v3.0.1...main
[3.0.1]: https://github.com/laikait/lphp/releases/tag/v3.0.1
[2.1.2]: https://github.com/laikait/lphp/releases/tag/v2.1.2
