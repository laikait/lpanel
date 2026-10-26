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
DB_DSN=sqlite:C:/xampp/htdocs/lpanel/system/Runtime/dev.sqlite
# There is no PAM on Windows, so root is checked against a hash instead.
# Refused unless APP_ENV is local or testing.
LPANEL_ROOT_VERIFIER=dev
LPANEL_DEV_ROOT_PASSWORD_HASH=   # php laika auth:hash 'a dev-only password'
```

Then:

```bash
php laika migrate
composer serve            # http://127.0.0.1:8080, or http://localhost/lpanel/ under XAMPP
```

Sign in as `root` with the password you hashed.

## Check it

```bash
composer check                       # coding standard, PHPStan level 8, PHPUnit
helpers/authd/test/run.sh            # lpanel-authd against real PAM, in Docker
php laika security:check             # deployment checks
```

Tests use an in-memory SQLite database and the dev root verifier
(`tests/Support/ApplicationTestCase.php`). `tests/Support/Browser.php` keeps
cookies across requests and sends the CSRF token as a page would.

## Where things are

| Path | What |
|---|---|
| `modules/Audit` | `audit_events`, `AuditLog`, auth hooks → audit, `audit:prune`, `/api/v1/audit` |
| `modules/Identity` | users, roles, `PanelUserProvider`, root verifiers, login/2FA pages, `/api/v1/session`, `/api/v1/users` |
| `modules/Dashboard` | `/` |
| `helpers/authd` | `lpanel-authd` (Go + PAM), its units, PAM file and tests |
| `config/system.php` | `engine/System` off (ADR-0005) |
| `public/assets/vendor` | Bootstrap, jQuery ([VENDORED_ASSETS.md](VENDORED_ASSETS.md)) |
