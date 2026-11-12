# Developing LPanel

## Run it locally (Windows + XAMPP)

1. Create the databases: `lpanel` (and `lpanel_test` if you want one).
2. Copy `.env.example` to `.env` and set:

   ```
   APP_ENV=local
   APP_DEBUG=true
   APP_URL=http://localhost/lpanel
   APP_KEY=…                      # php laika security:key --bare
   DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=lpanel;charset=utf8mb4
   DB_USERNAME=root
   SESSION_STORE=database
   LPANEL_ROOT_VERIFIER=dev       # no authd on Windows
   LPANEL_DEV_ROOT_PASSWORD=root-dev-password
   ```

3. Run `php laika migrate`, then `php laika dev:seed`, then
   `php laika dev:seed-hosting` for sample packages and accounts. These are
   database rows only: there is no agent on Windows, so no Linux users are
   made.
4. Open the Admin panel at http://localhost/lpanel/admin and the User panel at
   http://localhost/lpanel/panel.

## Development logins

| Interface | Username | Password | Kind |
|---|---|---|---|
| Admin panel | `root` | `root-dev-password` (from `.env`) | root |
| Admin panel | `admin` | `admin-password-123` | panel administrator |
| Admin panel | `reseller` | `reseller-password-123` | reseller |
| User panel | `demo` | `demo-password-123` | account owner (owned by `reseller`) |
| User panel | `demo.dev` | `subuser-password-123` | sub-user of `demo` |

`dev:seed-hosting` adds the owners `bakery`, `oldblog`, `agency` and `newsite`
(password `<username>-password-123`) and gives `demo` an account. Run
`php laika web:settle` afterwards to give each account its main domain. With
no agent, the sites stay "waiting for the server", which is expected.
`dev:seed`, `dev:seed-hosting` and the `dev` root verifier refuse to work
unless `APP_ENV=local`.

## Modules

| Module | Holds |
|---|---|
| `Shared` | DataSource binding, `/health` |
| `Panel` | the two shells (`@Panel/shell`, `@Panel/auth`), CSS/JS/icons, `PanelPage`, dashboards, the `panel.nav` and `panel.dashboard` filters |
| `Audit` | `audit_log`, `AuditLog::record()`, audit pages, sign-in failure listeners |
| `Identity` | `panel_users`, sign-in/out, two-factor, root via authd, staff, tiers and roles (all role lists live here), API tokens |
| `Agent` | `AgentClient` (signed requests to lpa), `AgentKey`, `agent:*` commands, the server card |
| `Packages` | packages, their limits and feature lists, `packages:*`, `/admin/api/v1/packages` |
| `Accounts` | accounts, the `Provisioner` (every account change through the agent), `accounts:*`, `/admin/api/v1/accounts`, the dashboard cards |
| `Resellers` | reseller allowance and ACL (enforced through filters), the resellers page, `resellers:*`, `/admin/api/v1/resellers` |
| `Subusers` | the owner's Users page, sub-user grants, the gate on `feature.*` |
| `Web` | domains, each account's sites on the server (`WebSync`, `web.apply`), the User panel's Domains page, `/admin/api/v1/accounts/{user}/domains`, `domains:*` and `web:*` |

Who may do what is decided in layers:
- Roles are listed in `Identity/module.php`.
- Routes name a capability (`can`).
- Filters on `authorization.decision` can only take capabilities away: each
  login's own interface (Identity), a reseller's ACL (Resellers), and the
  package's features plus a sub-user's grants (Subusers).

A screen for a hosting feature protects its routes with `feature.<name>`;
the Domains page uses `feature.domains`.

Another module's card can appear on the Admin panel's account page through
the `accounts.show` filter (`list $cards, array $row, Identity $identity`),
as `panel.dashboard` does for dashboards. When an account operation is done,
the `accounts.changed` hook (`string $op, array $row`) runs; the Web module
uses it to give a new account its site and to follow suspensions.

"Log in as": `POST /admin/login-as/{login id}`. Into the User panel it hands
over a ticket (`/panel/login-as?ticket=…`); `LoginAs::otherInterface()` swaps
:7443 and :7444 on a server and stays on the same site locally.

The REST API lives under each interface's prefix (`/admin/api/v1/…`). Its
routes set `csrf => false`, which makes the framework ignore the session
cookie there, so only a bearer token (`Authorization: Bearer lpat_…`) works.
Make a token on the API tokens page or with `lpanel identity:token <login> <name>`.

On a server, root runs the panel's commands as `lpanel <command>`
(`/usr/local/sbin/lpanel` runs `laika` as the panel's user). Try `lpanel help`.

The agent itself is the Go module in [`dist/lpa/`](../dist/lpa/README.md).

## The agent on a developer's machine

PHP on Windows has no Unix-socket transport, so the panel cannot reach a real
agent from XAMPP. The dashboard shows the "agent not reachable" warning. That
is expected. To work on agent features:

- **PHP side.** Feature tests answer agent calls from `Tests\Support\FakeAgent`
  through `$this->agent($app, new FakeAgent([...]))`. It verifies signatures
  as the real agent does. An answer may be a closure that returns a whole
  response. `Tests\Support\FakeServer` uses that to play the account
  operations with the agent's journal, so tests can take the agent down, lose
  a reply, or fail the next operation.
- **Go side.** `cd dist/lpa && go test ./...` runs on Windows, including the
  real-socket server tests.
- **Both together.** Build with `dist/lpa/build.sh`, then run
  `tests/e2e/agent.sh`. It needs Docker and runs the real binary with the real
  CLI in AlmaLinux 9, with real users and socket permissions.

When you change an operation's arguments or results, change the Go operation,
its test, and the PHP caller together. When you change the signing rule, run
`go test ./internal/protocol -update` and then the PHP tests.

The rules a new screen follows:

- Render it through `PanelPage::render()`, so it gets the shell, the
  navigation, notices, the theme and the text direction.
- Put user-facing text in the module's `lang/en.php` and print it with `|local`.
- Add its sidebar entry with the `panel.nav` filter.
- Record every change with `AuditLog::record()`.
- Protect routes under `/admin` or `/panel` with a capability. The Identity
  module additionally makes sure each login only opens its own interface.

## Checks

```bash
php vendor/bin/phpunit                                   # feature tests, SQLite in memory
php vendor/bin/phpstan analyse --memory-limit=1G modules tests
php vendor/bin/php-cs-fixer fix --dry-run --config=.php-cs-fixer.dist.php --path-mode=override modules
(cd dist/lpa && gofmt -l . && go vet ./... && GOOS=linux go vet ./... && go test ./...)
tests/e2e/agent.sh                                       # Docker; after dist/lpa/build.sh; 37 checks
```

Both e2e scripts mount a filesystem image with user quotas on `/home` inside
the container (it needs `--privileged`), so disk limits are enforced by the
kernel during the tests rather than assumed. It is ext4 by default;
`HOME_FS=xfs tests/e2e/agent.sh` (or `install.sh`) uses XFS with `uquota`,
EL9's own filesystem. `tests/e2e/quota-home.sh` does the mounting.

## Building and testing an install

These all need Docker. They take a few minutes because each one installs
packages in a fresh container.

```bash
dist/lpa/build.sh            # lpa, cross-compiled (no cgo)
dist/authd/test/run.sh       # lpanel-authd: tests against real PAM, then builds in AlmaLinux 9 (cgo)
dist/selinux/build.sh        # the SELinux module, built and checked against EL9's policy (53 checks)
dist/installer/bundle.sh     # dist/build/lpanel-<version>/ and .tar.gz
tests/e2e/install.sh         # installs the bundle on a fresh systemd AlmaLinux 9 container; 169–174 checks per stack
KEEP=1 tests/e2e/install.sh  # the same, and leaves the container running to look inside
WEB=nginx tests/e2e/install.sh   # the same on another web stack (nginx or hybrid); each run also
                                 # switches to another stack at the end and checks every site moved
```

The bundle includes phpMyAdmin: `bundle.sh` downloads its release once into
`dist/build/cache/` and checks it against a pinned SHA-256. The database
checks run against the container's real MariaDB and phpMyAdmin.

The certificate checks run against Pebble, Let's Encrypt's test authority.
`tests/e2e/install.sh` builds it once into `dist/build/pebble/` (it needs Go
and the network the first time). Inside the container, one Pebble stands in
for Let's Encrypt and one for ZeroSSL (requiring EAB). `/etc/hosts` makes the
test names point at the container, and `lpa.json`'s `acme` is pointed at
the two Pebbles (with `roots` set to Pebble's test CA). AutoSSL is turned off
right after the install and back on in the SSL section. Otherwise the
scheduler would ask the real Let's Encrypt for names that point nowhere,
and back off for an hour.

To poke at an installed panel by hand, start the same container and keep it:

```bash
MSYS_NO_PATHCONV=1 docker run -d --name lp --hostname panel.test --privileged --cgroupns=host \
  -v /sys/fs/cgroup:/sys/fs/cgroup:rw -p 7443:7443 -p 7444:7444 almalinux/9-init
docker cp dist/build/lpanel-0.1.0-dev/. lp:/bundle/
docker exec lp bash -c 'echo "root:pick-a-password" | chpasswd && /bundle/install.sh --allow-no-quota'
# then open https://localhost:7443 and sign in as root
```

`--allow-no-quota` exists for containers without a quota filesystem. It writes
`"quota": "off"` to `/etc/lpa/lpa.json`, so accounts work but have no disk
limits. A real install without quotas is refused (ADR-0008). To get quotas in
the container too, mount a quota ext4 image on `/home` first, as
`tests/e2e/install-inside.sh` does.

## SELinux

Containers cannot run SELinux: the kernel is shared and has none on. So the
policy is tested in two steps.

1. **`dist/selinux/build.sh`, with Docker.** It compiles `lpanel.te` and
   `lpanel.fc` against EL9's own targeted policy and loads the module into a
   policy store. Then it asks the compiled policy about every access the panel
   needs and every access it must not get, and what label each path gets.
   When the panel starts using a new path, socket or service, add its label
   to `lpanel.fc` and a check to `check-inside.sh`.
2. **`tests/vm/run.sh`, on a VM.** It runs the real thing with SELinux
   enforcing.

EL9 looks up `/run/...` paths as `/var/run/...`, so `lpanel.fc` names them
that way. Remi's php-fpm is `httpd_exec_t` only because Remi's package adds
the `/opt/remi/php83/root = /` equivalence.

## Testing on a VM

`tests/vm/run.sh root@<address> [ssh options]` copies the bundle to a fresh
AlmaLinux or Rocky 9 machine and runs the install e2e there. It adds the
SELinux checks and fails on any denial. The machine must:
- have SELinux enforcing (the script says how to turn it on);
- let root in over SSH with a key;
- be throwaway: the run sets a test root password, and mounts an XFS `uquota`
  image over `/home` unless `/home` already has user quotas.

It needs about 2 GB of memory and 10 GB of disk. Any of these will do:
- **A cloud server**, from the provider's AlmaLinux 9 image. Some providers
  ship it with SELinux off.
- **Hyper-V**, from an administrator PowerShell. Use a Generation 2 VM from
  the AlmaLinux 9 minimal ISO, and set its Secure Boot template to
  "Microsoft UEFI Certificate Authority".
- **QEMU/KVM inside WSL2.** This needs `nestedVirtualization=true` in
  `%USERPROFILE%\.wslconfig`, then `wsl --shutdown`. It only works when
  `/dev/kvm` appears afterwards.

## Gotchas

- Headless Chrome on this machine will not make a window narrower than about
  490px, so `--window-size=390,…` only crops a wider page and it looks cut
  off. For a true phone-width screenshot, load the page in a
  `<iframe style="width:390px">` and screenshot that.
- Lang placeholders are `:name`, not `{name}`.
- PHP-FPM's graceful reload keeps each pool's listening socket as it was, so
  a change to `listen.owner`/`listen.group`/`listen.mode` needs a restart
  (the agent restarts that version then, and reloads otherwise).
- The e2e containers have no `hostname` command; use `ip -4 -o addr`.
- An empty PHP array encodes as `[]`, and the agent's Go decoder refuses `[]`
  for a map field (`error_pages`, `php_settings`). Cast such fields to
  `(object)`. Stored args you send again must be decoded as objects
  (`json_decode($json, false)`), never `true`, or `{}` comes back as `[]`.
  `FakeAgent` checks the `web.apply` map fields on the raw line, so a feature
  test catches this. Add any new map field there too.
- The agent runs with `UMask=0077`, so any folder it makes with `MkdirAll` is
  0700 unless it sets the mode itself. Apache's parent process reads configs as
  root, but its workers open files at request time as `apache` (htpasswd
  logins), so every folder on that path must be reachable by `apache`. The
  symptom is a 500 where a 401 was expected.
- `TemplateManager::render()` takes `@Module/name` without `.twig`. Twig's own
  `{% extends %}` and `{% include %}` take the full file name. To render
  another module's card by its `@Module/name`, use
  `{{ view.render(card.template, card.data|merge({lp: lp}))|raw }}` as the
  dashboard does. An `{% include %}` without `.twig` happened to work with
  debug on and failed with it off.
- Never pass a template variable named `view`: it is the framework's helper.
- Route `defaults()` do not reach handler parameters that are not in the path.
  Derive the interface with `PanelPage::areaOf($request)`.
- Instance listeners are registered in `onBoot()`, because
  `[Class::class, 'method']` means a static call.
- The CSP forbids inline scripts. Behaviour goes in
  `modules/Panel/assets/js/panel.js`.
- `insert($row)` returns the new id only as `insert($row, 'id')`. An update
  or delete without a `where()` is refused; use `updateAll()` when every row
  is meant.
- Browsers keep cookies by host, not port. `config/session.php` gives each
  interface its own session cookie by looking at the request path, so do not
  run `config:cache`. Feature tests boot one application for many requests,
  so there both interfaces share one cookie; the install e2e checks the real
  separation.
- `SessionUsers` decorates the user provider: the framework calls `byId()`
  once per request for the session's login, which is where session epochs and
  `impersonator` are applied. Code that needs the plain row uses `PanelUsers`.
- Git Bash rewrites arguments that look like paths (`/old` becomes
  `C:/Program Files/Git/old`) before passing them to Windows programs such
  as `curl.exe`. With `MSYS_NO_PATHCONV=1` that stops, but then a curl cookie
  jar must be a Windows path (`C:/…/jar.txt`), or curl cannot save it and
  every form fails its CSRF check.
