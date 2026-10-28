# Developing LPanel locally

For engineers working on the panel on their own machine (XAMPP or `php -S`).
Nothing here is for a server: production root login uses `lpanel-authd`
(ADR-0010), and packaging is milestone M5.

## Run it

`.env` in the project root (never committed):

```dotenv
APP_ENV=local
APP_DEBUG=1
APP_KEY=        # php -r 'require "vendor/autoload.php"; echo App\Engine\Security\Signer::generate();'
APP_URL=http://127.0.0.1:8080   # reset links in emails are built from this
DB_DSN=sqlite:C:/xampp/htdocs/lpanel/system/Runtime/dev.sqlite
# There is no PAM on Windows, so root is checked against a hash instead.
# Refused unless APP_ENV is local or testing.
LPANEL_ROOT_VERIFIER=dev
LPANEL_DEV_ROOT_PASSWORD_HASH=   # php laika auth:hash 'a dev-only password'
# Emails (password reset) go to the log instead of being sent. Without a
# sender address no email can be built at all.
MAIL_TRANSPORT=log
MAIL_FROM_ADDRESS=panel@lpanel.test
MAIL_FROM_NAME=LPanel
# LPHP writes no logs unless told where (config/logging.php).
LOG_WRITERS=file
```

Then:

```bash
php laika migrate
composer serve            # http://127.0.0.1:8080, or http://localhost/lpanel/ under XAMPP
```

Sign in as `root` with the password you hashed. For sample accounts:

```bash
php laika dev:seed        # refuses unless APP_ENV is local or testing
```

| Login | Password | Role |
|---|---|---|
| `root` | whatever you hashed into `LPANEL_DEV_ROOT_PASSWORD_HASH` | root |
| `admin` | `admin-password-123` | administrator |
| `customer` | `customer-password-123` | customer |

These are public, which is why `dev:seed` will not run on a server. Root has
no API tokens and no reset links (ADR-0011), so automation and "forgot
password" need a panel account like these. With `MAIL_TRANSPORT=log` a reset
email lands in `system/Logs/app-<date>.log`, with the whole message at debug
level, instead of in a mailbox.

## Check it

```bash
composer check                       # coding standard, PHPStan level 8, PHPUnit (SQLite)
test/mariadb/run.sh                  # the same tests on MariaDB 10.11 and 11.8, in Docker
test/e2e/run.sh                      # the real lpa agent against panel Nginx + PHP-FPM, in Docker (LPA_DIR=../lpa): pairing, operations, rotation, revocation
helpers/authd/test/run.sh            # lpanel-authd against real PAM, in Docker
packaging/build-deb.sh 0.1.0         # dist/lpanel_0.1.0_amd64.deb, built in Docker
test/packages/run.sh                 # the package on all four OSes, with a real agent and lpa-exec making a real account (LPA_DIR=../lpa)
php laika security:check             # deployment checks
```

Tests use an in-memory SQLite database, the dev root verifier and the `array`
mail transport (`tests/Support/ApplicationTestCase.php`; `mailTo()` reads what
was sent). With `LPANEL_TEST_DB_DSN` set they run on that MariaDB instead, which
is emptied before each test: point it only at a scratch database.
`tests/Support/Browser.php` keeps cookies across requests, sends the CSRF token
as a page would, and makes bearer-token calls with `bearer()`.

## Servers and agents

Agents reach the panel through panel Nginx, which hands their TLS client
certificate to PHP. XAMPP's Apache does not, so a real agent cannot pair with
a panel served by XAMPP. Use `test/e2e/run.sh`, which builds `lpa`, runs
panel Nginx and PHP-FPM in Docker, and pairs, rotates and revokes an agent.
The screens work locally anyway. To try them:

```dotenv
LPANEL_AGENT_URL=https://panel.example.test:8443
LPANEL_TLS_CERTIFICATE=C:/xampp/htdocs/lpanel/tests/Fixtures/lpa/certs/panel.pem
```

Then run `php laika servers:keygen`, which writes `system/Keys/operations.key`
(git-ignored). The PHP tests stand in for an agent with
`tests/Support/FakeAgent.php`, which signs exactly as the Go agent does. Both
sides check the golden vectors in `tests/Fixtures/lpa/vectors.json`, a copy
of the lpa repository's `internal/protocol/testdata/vectors.json`: copy it
again whenever the agent regenerates it.

## Operations

Work for the agents lives in `modules/Operations` (AGENT_PROTOCOL.md §6.3).
To try it without an agent, the tests are the quickest route:
`OperationsAgentTest` drives claim, lease and result with `FakeAgent`, and
`tests/Support/OperationsTestCase.php` has helpers that verify what a claim
signed. Against a real agent, `test/e2e/run.sh` runs every operation.

From a shell on the panel host:

```bash
php laika operations:run web-01 system.metrics --wait     # exit 0 only if it succeeded
php laika operations:list --server=web-01
php laika operations:show <id> --json
```

**Adding or changing an operation is a change on both sides.** Change the
agent's registry, run `go test ./internal/ops -run TestTheCatalogueFileIsTheRegistry -update`
in the lpa repository, copy `internal/ops/testdata/catalogue.json` to
`tests/Fixtures/lpa/catalogue.json`, and change
`modules/Operations/Catalogue/Catalogue.php` until `OperationCatalogueTest` passes.

Other modules add cards to a server's page through the
`servers.detail.panels` filter (see `ServersPage`). Revoking an agent fires
`servers.revoked` inside the revoking transaction.

## Hosting accounts

`modules/Hosting` (M6a, [ADR-0013](adr/0013-hosting-accounts-on-a-server.md)) asks the agent for
`account.create`, `account.suspend`, `account.unsuspend` and `account.delete`, and settles each
account when its operation finishes: Operations fires `operations.finished` inside the
operation's own transaction, and `AccountActions::settle()` listens. Only the account's current
operation (`operation_uid`) can settle it, so a late result of an older one changes nothing.

- Every account id from a person goes through `AccountAccess::find()`. Another customer's id is
  answered exactly like an unknown one (404) and audited. Keep it that way for every new route;
  `HostingAccountsTest` tries them all (THREAT_MODEL test 1).
- Routes take `accounts.own` as the floor; `AccountActions` checks `accounts.manage` itself and
  audits the refusal. That is why a customer's POST is a 403 with an audit record, not a bare
  route refusal.
- Without a real agent, `HostingAccountsTest` drives everything with `FakeAgent`. With one,
  `test/packages/run.sh` does it on a real server, with the real `lpa-exec`.

```bash
php laika accounts:create web-01 carol "Acme Ltd" --wait
php laika accounts:list --status=current --json
php laika accounts:suspend lpq3x7k2ma --wait
php laika accounts:delete lpq3x7k2ma --confirm=lpq3x7k2ma --wait
```

## Websites

`modules/Websites` (M6b, [ADR-0014](adr/0014-websites-through-the-hosting-nginx.md)) asks for
`website.create`, `website.update`, `website.suspend`, `website.unsuspend`, `website.delete` and
`websites.check`, and settles each on `operations.finished`, like Hosting.

- Every website id goes through `WebsiteAccess::find()` (audited 404). `WebsitesTest` tries every
  route (THREAT_MODEL test 1).
- `WebsiteSettings::normalize()` mirrors the agent's grammar so forms say which field is wrong;
  the patterns live in `Catalogue` (`DOMAIN`, `DOCUMENT_ROOT`, …). Change them with
  `lpa/internal/websites/settings.go`, never alone.
- A customer (`websites.configure`) changes PHP, index files, redirects and headers of its own
  websites; domains, the folder, suspend, delete and restore are `websites.manage`.
- An edited website (`config_state` not ok) refuses updates, suspend/unsuspend, and its account's
  suspension (`hosting.account.blockers`), until an administrator restores LPanel's version.
- Hosting offers two filters other modules use: `hosting.account.blockers` (reasons an account
  action can't happen yet) and `hosting.account.panels` (cards on an account's page).

```bash
php laika websites:create lpq3x7k2ma example.com,www.example.com --wait
php laika websites:list --json
php laika websites:check                  # hourly from the scheduler
php laika websites:restore example.com --wait
php laika websites:delete example.com --confirm=example.com --wait
```

**The query builder is immutable.** `$query->where(…)` returns a new builder.
Write `$query = $query->where(…)`, or the condition is silently dropped. This
bit the audit filters and the users store once; `AuditTest` and
`UsersPageTest` now guard both.

## The package

`packaging/` builds the `lpanel` `.deb` ([ADR-0012](adr/0012-panel-package-php-fpm-and-agent-port.md)):

| Path | What |
|---|---|
| `packaging/build-deb.sh` | Composer (`--no-dev`), `lpanel-authd` and the `.deb`, all in a Debian 12 container |
| `packaging/bin/lpanel-setup` | what `postinst` runs: makes users, certificates, secrets, the database and its accounts once; renders Nginx and PHP-FPM config, migrates and warms caches every time |
| `packaging/bin/lpanel` | `sudo lpanel <command>`: `php laika` as the `lpanel` user; `migrate` runs as `lpanel_migrate` |
| `packaging/templates` | the Nginx (8443 people, 8444 agents) and PHP-FPM templates |
| `packaging/systemd`, `packaging/deb`, `packaging/logrotate` | units, maintainer scripts, log rotation |

The application in `/opt/lpanel` is read-only. A change that needs a writable
path must go under `system/` (`/var/lib/lpanel`). Anything the panel's PHP must
read outside the tree has to be added to `open_basedir` in
`packaging/templates/php-fpm.conf.in`, or production will refuse it while
XAMPP allows it. `composer.lock` is committed, so packages are reproducible.

## The page shell

Every screen renders through `Shared\Ui\Pages` into `templates/layout.twig`.
Sign-in screens use `templates/auth.twig`, and both extend `templates/base.twig`.

- **Sidebar:** a module adds its own entries in `onBoot` with
  `Navigation::add(section, key, label, route, icon, can:)`. An entry is shown
  only to people with its capability. Pass `section:` to `Pages::render()` to
  mark the current entry.
- **Building blocks:** `templates/macros/ui.twig` has icons (inline SVG),
  avatar, badges, page header, empty state, alert, a password field with a
  show/hide button, and timestamps. Import it with
  `{% import "macros/ui.twig" as ui %}`.
- **Styles:** `public/assets/css/app.css` is the theme on top of Bootstrap.
  Colours are tokens on `:root` and redefined under `[data-bs-theme="dark"]`.
  Components are prefixed `lp-`.
- **Behaviour:** `public/assets/js/app.js` works through data attributes only,
  because the CSP runs no inline script:
  - `data-lp-confirm` on a form opens a confirm dialog;
  - `data-lp-copy`;
  - `data-lp-reveal`;
  - `data-lp-theme-toggle`;
  - `data-lp-autosubmit`;
  - `time[data-lp-time]` shows local and relative time.
- **Theme:** `public/assets/js/theme.js` sets light or dark before first paint.
- **Flash messages:** `Pages::flash('success' | 'danger' | 'warning' | 'info', …)`.

## Where things are

| Path | What |
|---|---|
| `modules/Shared/Ui` | `Pages` (renders into the shell), `Navigation` (the sidebar) |
| `modules/Audit` | `audit_events`, `AuditLog`, auth hooks → audit, `audit:prune`, `/audit`, `/api/v1/audit`, `/api/token/v1/audit` |
| `modules/Identity` | users, roles, `PanelUserProvider` (sessions, tokens, resets), root verifiers, `UserAdmin` (who may change whom), sign-in / reset / security / tokens / users screens, `/api/v1` and `/api/token/v1` |
| `modules/Servers` | servers, agent keys, enrollment, `/agent/v1` (`RequestVerifier` is the §3 checks), `/servers` screens, `/api/v1/servers`, `servers:*` commands |
| `modules/Websites` | `websites`, `website_domains`, `WebsiteSettings` (the grammar), `WebsiteAccess`, `WebsiteActions` (ask, settle, record checks), `/websites` screens, `/api/v1/websites`, `websites:*` commands, the hourly `websites:check` |
| `modules/Hosting` | `hosting_accounts`, `AccountAccess` (who may see which), `AccountActions` (ask the agent, settle on `operations.finished`), `/accounts` screens, `/api/v1/accounts`, `accounts:*` commands |
| `modules/Operations` | the catalogue (`Catalogue`, strict `Shape`s), `OperationStore` (the state machine), `OperationRequests` (checks before queueing), `/agent/v1/operations/*`, `/operations` screens and server-page cards, `/api/v1/operations`, `operations:*` commands |
| `modules/Dashboard` | `/`, and the placeholder sidebar entries for milestones not built yet |
| `templates/` | `base`, `layout`, `auth`, `macros/ui`, error pages, emails |
| `helpers/authd` | `lpanel-authd` (Go + PAM), its units, PAM file and tests |
| `test/mariadb` | the PHP 8.2 image and script for the MariaDB runs |
| `test/e2e` | panel Nginx (mTLS) + PHP-FPM image and the end-to-end script with the real agent |
| `config/system.php` | `engine/System` off (ADR-0005) |
| `public/assets/vendor` | Bootstrap, jQuery ([VENDORED_ASSETS.md](VENDORED_ASSETS.md)) |
