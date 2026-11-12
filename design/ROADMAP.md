# LPanel roadmap

The milestones below are the order in which v1 gets built. Each one ends with
something that works on a real AlmaLinux 9 server and has automated tests. The
features themselves are listed in [FEATURES.md](FEATURES.md).

These rules apply to every milestone:

- Every system change goes through the agent (`lpa`). The panel never runs
  shell commands itself.
- Every change is recorded in the audit log.
- Every new screen works in light and dark mode and at phone width.
- Every new action is available in the REST API and the `lpanel` CLI, not only
  in the UI.
- Each milestone is tested on a real or containerised AlmaLinux 9 machine.
  Unit tests alone don't count.

## v1

### M0: Foundations
M0 is built in three parts.

**M0a: panel foundations. Done 2026-10-29.**
- Modules Shared, Panel, Audit and Identity.
- Logins for every tier in `panel_users`, with roles per tier.
- Each login opens only its own interface, enforced by the route guard and by
  the authorization filter.
- Sign-in and sign-out.
- Two-step sign-in (TOTP plus recovery codes).
- Root sign-in through the authd protocol, with the panel's own throttle.
  A local-only dev verifier stands in on Windows.
- Panel administrators, managed by root.
- The audit log, with a one-year prune.
- Admin panel and User panel shells, with i18n keys, RTL direction, a
  dark/light toggle and a responsive layout.
- 15 feature tests.

**M0b: agent. Done 2026-10-30.**
- `lpa`, a new Go module in `dist/lpa/`, runs as root on `/run/lpa/lpa.sock`
  (root:lpanel, 0660).
- It checks the caller with SO_PEERCRED and verifies an Ed25519 signature on
  every request.
- It refuses stale and replayed requests.
- It runs each operation that changes the system exactly once per id, with a
  file journal. Entries cut off by a crash become `interrupted`.
- It supports background operations through `op.status`, enforces timeouts
  and a busy limit, and recovers from panics.
- Its first operations are `system.ping` and `system.facts`.
- The panel's `Agent` module adds `AgentClient`, the `agent:keygen`,
  `agent:ping` and `agent:facts` commands, and a live server card on the Admin
  panel dashboard for root and administrators.
- Tests:
  - Go unit tests over a real socket.
  - PHP golden-vector and feature tests (27 in total).
  - `tests/e2e/agent.sh`, 19 checks in AlmaLinux 9.
- Decided along the way: the panel's own PHP is Remi `php83`, because EL9's
  AppStream PHP has no sodium.

**M0c: authd and installer. Done 2026-11-10.**
- `lpanel-authd` lives in `dist/authd/`. It came from the earlier attempt,
  with an EL PAM file and an EL9 build.
  - `dist/authd/test/run.sh` tests it against real PAM: right and wrong
    passwords, unknown users, and locked and expired accounts.
  - Fixed along the way: EL's `/etc/shadow` is mode 0000, so the unit needs
    `CAP_DAC_READ_SEARCH`.
- `dist/installer/install.sh` runs from a bundle made by `bundle.sh`. On
  AlmaLinux or Rocky 9 it installs:
  - Remi php83 and MariaDB
  - the panel's own PHP-FPM and nginx on :7443 and :7444 (ADR-0010)
  - `lpa` and authd, the keys, the self-signed TLS certificate, the
    migrations and the scheduler timer
  It is safe to rerun, and it keeps every secret.
- `tests/e2e/install.sh` runs 42 checks on a fresh systemd AlmaLinux 9
  container. They include signing in as root with the real root password
  through PAM and seeing live agent data on the dashboard.
- `design/THREAT_MODEL.md` maps each threat to its defence and its test, and
  lists the known gaps.

**M0 exit met, in a container, not yet on a VM.** On a fresh AlmaLinux 9
machine the installer runs, root signs in with the real password, and the
dashboard shows live data from the agent. Two-step sign-in setup is covered by
the feature tests. Remaining: verify SELinux in enforcing mode on a real VM.

"Log in as", reseller ACLs and sub-user grants depend on accounts, so they
moved to M1.

**Exit:** on a fresh AlmaLinux 9 VM the installer runs, root logs in with the
real password, sets up 2FA, sees the dashboard, and the dashboard shows
live data read from the agent.

### M1: Accounts, packages, resellers
M1 is built in three parts.

**M1a: packages and accounts. Done 2026-11-10.**
- Packages module: limits (disk, files, bandwidth, domains, subdomains,
  mailboxes, databases, FTP) and a feature list. Packages belong to the server
  or to one reseller. Changing a package re-applies its quota to every account
  on it.
- New agent operations: `account.create`, `account.modify`, `account.suspend`,
  `account.unsuspend`, `account.terminate` (in the background) and
  `account.info`.
  - They make the Linux user and group, a 0711 home with `public_html`, the
    kernel quota through `quotactl(2)`, and the shell (`ssh` feature → bash,
    otherwise nologin).
  - Suspending expires the account, so PAM refuses every login, and ends its
    processes.
  - The agent only touches users it made (comment marker, uid ≥ 1000, home
    under `homes_base`).
  - A failed create undoes itself. Removing a home never follows links.
- Accounts module:
  - Create, change package, suspend, unsuspend, reset the owner's password,
    terminate.
  - Resellers see and act on only their own accounts and packages.
  - Suspending also suspends the owner's and sub-users' panel logins.
  - Every change goes through the `Provisioner`: recorded first, sent with a
    stable operation id, and finished by `accounts:settle` if the agent was
    unreachable or the answer was lost.
- REST API with bearer tokens (the API tokens page, `lpanel identity:token`):
  - `/admin/api/v1/packages` and `/admin/api/v1/accounts`.
  - The session cookie is never accepted on API routes.
- `lpanel` command for root: `packages:*` and `accounts:*`.
- User panel dashboard card with the account's disk use; Admin panel
  accounts card.
- Tests:
  - 41 PHP tests.
  - Go unit tests for accounts and quota.
  - `tests/e2e/agent.sh`: 37 checks, on an ext4 `/home` with real user quotas.
  - `tests/e2e/install.sh`: 62 checks, now without `--allow-no-quota`. A
    reseller-style flow runs over the CLI, the API and the User panel, and the
    kernel stops a write past the quota.
- Fixed along the way:
  - `insert()` returns the new id only when asked (`'id'`), so
    `PanelUsers::create()` had been returning `''`.
  - The agent's `UMask=0077` would have made `public_html` 0700.

**M1b: resellers and sub-users. Done 2026-11-11.**
- **Resellers module.** An allowance per reseller: how many accounts, total
  disk and total bandwidth, with overselling allowed or not.
  - It is enforced when an account is created or moved to another package,
    whoever does it, and when a reseller's package is created or grown.
    Unlimited packages are refused under a limited allowance.
  - An ACL per reseller: create, change, suspend, terminate, log in as, make
    packages. It is applied in the authorization filter, so pages, API and
    navigation all follow it.
  - A resellers page for root and administrators, an allowance card on the
    reseller's dashboard, `/admin/api/v1/resellers`, and
    `lpanel resellers:create|list|limits`.
  - Account actions are now separate capabilities (`accounts.create`,
    `.modify`, `.suspend`, `.terminate`, `.login_as`).
- **Subusers module.** The owner's "Users" page in the User panel: sub-users
  named `owner.name`, each granted some of the package's features.
  - Features are capabilities (`feature.email`…): an owner holds those of
    their package, a sub-user only those granted and still in the package.
  - Later feature screens protect their routes with them.
- **Log in as.**
  - Root and administrators become a reseller or an owner; a reseller becomes
    their own owners.
  - Within the Admin panel the session changes login and "Return" restores
    it. Into the User panel (another port) the Admin panel hands over a
    one-use ticket that lasts a minute.
  - A banner shows on every page, and changes are audited as on behalf of
    the real person. The target's password, two-factor and API tokens cannot
    be changed meanwhile.
- **Session epochs.** A new password, a password reset, disabling,
  suspending, or "Sign out everywhere else" on the security page ends every
  other session of that login.
- **Fixed along the way:** browsers share cookies between ports, so both
  interfaces had one session and signing in to one signed the browser out of
  the other. `config/session.php` now names the cookie per interface.
- **Tests:**
  - 60 PHP tests.
  - `tests/e2e/install.sh`: 72 checks. They now cover one browser in both
    interfaces, "log in as" across ports, and a reseller's account limit
    through the CLI.

**M1c: SELinux and XFS. Built 2026-11-10; the VM run is still to do.**
- **XFS quotas.** Both e2e scripts take `HOME_FS=xfs`, which mounts `/home` as
  XFS with `uquota`, EL9's own filesystem. The agent needed no change: the
  kernel enforces the limits on XFS the same way (agent e2e 37/37 on XFS).
- **SELinux policy module** (`dist/selinux/`). The first check against EL9's
  real policy found that, with SELinux enforcing, the panel could have
  reached neither the agent nor authd: no rule lets `httpd_t` (the panel's
  nginx and PHP-FPM) connect to `unconfined_service_t` or write a
  `var_run_t` socket. The module:
  - runs `lpa` and `lpanel-authd` in their own domains (`lpanel_agent_t`,
    `lpanel_authd_t`). They are still unconfined, because changing the
    system is their job.
  - lets `httpd_t` connect to those two domains only, through sockets of
    their own type (`lpanel_sock_t`).
  - labels every path the panel uses: code read-only, `system/` writable,
    logs, run-time sockets and nginx's temporary files.
  - `dist/selinux/build.sh` builds it in AlmaLinux 9 and checks it against the
    compiled policy (38 checks), including the access it must not grant.
  - The installer loads it whenever SELinux is on, enforcing or permissive.
- **The agent labels new homes** with `restorecon` when SELinux is on, so
  `public_html` is `httpd_user_content_t` and ready for M2.
- **`tests/vm/run.sh root@host`** runs the whole install e2e on a fresh
  AlmaLinux or Rocky 9 machine with SELinux enforcing. It then checks the
  domains and labels, and fails on any denial during the run.
- **Still to do: run it on a VM.** This development machine could not host
  one: nested virtualization is off in WSL2, and Hyper-V needs an
  administrator. The run is the M0 and M1 exit on a real machine.
- **Bandwidth metering moved to M2.** It reads the web server's logs, and
  there is no web server before M2.

**Exit:** a reseller creates an account from a package. The disk quota is
enforced by the kernel. Suspend and terminate work and appear in the audit
log.

### M2: Web
M2 is built in four parts.

**M2a: Apache, PHP-FPM and domains. Built 2026-11-11.**
- **Agent** (`internal/web`): `web.apply` takes an account's whole web state
  (sites, aliases, folders, PHP versions, suspended). So a retry is always
  safe, and the same state twice changes nothing.
  - One Apache file per account, with a VirtualHost per site.
  - One PHP-FPM pool per account for each PHP version its sites use, running
    as the account's own user. Remi's 8.2 and 8.3 come installed.
  - The staged pipeline:
    1. test each pool (`php-fpm -t`) and the whole Apache tree with the
       candidate (`httpd -t` with `Define LPANEL_SITES <copy>`), before
       anything live changes;
    2. swap the files in;
    3. reload PHP, then Apache;
    4. probe every site for its new revision header;
    5. roll back if the probe fails.
  - Also `web.remove`, run by `account.terminate` before the user goes, and
    `web.info`.
  - Document roots are `public_html` or folders under it, made without
    following links.
  - A suspended account's sites answer 503 and run no PHP.
- **Panel** (`Web` module): domains of four kinds.
  - **Main:** made with the account.
  - **Website:** an addon domain with its own folder.
  - **Subdomain:** under one of the account's domains.
  - **Alias:** another name for a site.
  - Every site also answers to `www.`, and optionally to `*.`. Names are
    unique on the server, including against new accounts.
  - Limits come from the package: `domains` (the main one included) and
    `subdomains`.
  - A new `domains` feature lets owners give sub-users the Domains page.
    Packages made earlier get it through a migration.
  - Every change records the account's whole state with a new operation id
    before sending it. If the server refuses a change, it is undone in the
    panel. If the agent cannot be reached, `web:settle` sends it later.
  - Where it shows: the User panel's Domains page, a Websites card on the
    Admin panel's account page, `/admin/api/v1/accounts/{user}/domains`, and
    `lpanel domains:list|add|set|remove`, `web:info`, `web:settle` and
    `web:sync`.
- **Installer:** Apache, Remi PHP 8.2 and 8.3 for sites, ports 80 and 443
  opened.
- **SELinux:** `dist/selinux/setup.sh` holds every setting, and the
  installer and the build check both run it.
  - Accounts' `public_html`, `tmp` and `logs` are writable web content.
  - The booleans customers' sites need are on.
  - The build check now has 53 checks.
- **Tests:**
  - 69 PHP tests.
  - Go tests for the pipeline, its refusals and the no-follow folder maker.
  - `tests/e2e/install.sh`: 93 checks. On a real Apache, they cover PHP 8.3
    and 8.2 side by side, each running as the account's own user, for every
    domain kind. Also: one account's PHP cannot read another's files,
    suspended sites answer 503, a broken Apache config elsewhere stops a
    change while every site keeps serving, and a planted link is refused.

**M2b: site features. Built 2026-11-11.**
- Every feature stays part of the account's whole web state, so
  `web.apply` remains one idempotent operation through the same staged
  pipeline.
- **Per site:**
  - Redirects: 301 or 302, from a path and everything under it.
  - Which address visitors end up on: with `www.`, without, or either.
  - Custom error pages for 400, 401, 403, 404, 500 and 503.
  - Password-protected folders, or the whole site.
  - Hotlink protection by file kind, with other sites allowed.
- **Protected folders:**
  - The panel stores only bcrypt hashes. The agent writes them to
    `/etc/httpd/lpanel/htpasswd/` as 0640 root:apache.
  - Changing a password reloads nothing: Apache reads the file on each
    request.
- **The php.ini editor.** Owners set 14 directives per PHP version their
  sites use, for example `memory_limit`, upload sizes, time limits, the time
  zone and error display. Each value must match a strict rule, in both the
  panel and the agent. The settings go into that version's pool.
- **Tests:** PHP 77; `tests/e2e/install.sh` 105 checks (each feature on a
  real Apache, as a visitor sees it); agent e2e 37.
- **Checks.** Every value that ends up in Apache's or PHP-FPM's
  configuration is checked twice, in the panel and in the agent. No quote,
  space, newline or directive of its own gets through.
- **Where it shows:**
  - each domain has its own page in the User panel, and there is a new
    "PHP settings" page (feature `php_settings`);
  - the API under `/admin/api/v1/accounts/{user}/domains/{domain}/…` and
    `/admin/api/v1/accounts/{user}/php`;
  - `lpanel domains:show|redirect|unredirect|error-page|hotlink|protect|unprotect|protect-login`
    and `php:settings`.

**M2c: PHP versions and bandwidth. Built 2026-11-11.**
- **PHP versions** (Admin panel → Server → PHP versions; capability
  `server.php`, held by root and administrators, never resellers):
  - install 7.4 to 8.5 from Remi with the usual extensions, add or remove
    any of about twenty extensions, and remove a version no site uses;
  - choose the version new sites get (`web_settings.default_php`; else the
    newest installed);
  - the version the panel runs on (`panel_php`) and the extensions it needs
    stay; versions past end of life are marked and asked about.
  - dnf takes minutes, so the agent runs these in the background. A job is
    recorded with its operation id before it is sent (`php_jobs`);
    `web:settle` and every look at the page ask again. One at a time.
- **Bandwidth:**
  - the agent counts bytes sent and received per account and day from the
    access logs (`web.traffic`), by inode and offset, so log rotation
    neither loses nor repeats any; the panel stores the running totals
    (`web_traffic`) every 15 minutes (`web:traffic`);
  - each package says what happens when the month's bandwidth is used:
    `block` (the sites answer 503 and say why, until the month ends or the
    limit is raised; the default) or `warn`;
  - both panels show this month's use against the limit; raising a
    package's limit, or moving the account, starts its sites at once.
- **Log rotation:** `/etc/logrotate.d/lpanel` rotates accounts' Apache logs
  daily (renamed, compressed a day later, 14 kept) and the panel's own
  weekly.
- **Where it shows:** the API (`/admin/api/v1/php…`,
  `/admin/api/v1/accounts/{user}/bandwidth`) and `lpanel php:list|install|
  remove|extensions|default`, `web:traffic`, `web:bandwidth`.
- **Tests:** PHP 87; `tests/e2e/install.sh` 123 checks (installs PHP 8.4
  from Remi, runs a site on it with and without an extension, removes it,
  and compares the bytes counted with Apache's own log across a rotation);
  agent e2e 37.
- **Not yet:** a reseller's total bandwidth across their accounts, and
  emailing an owner who goes over (M10 alerts).

**M2d: the other two stacks. Built 2026-11-12.**
- **Choosing:** `install.sh --web=apache|nginx|hybrid`, for the whole server
  (lpa.json `web`). Running the installer again with another `--web`
  switches: it sets the services up, then re-sends every account
  (`web:sync --all`), and the agent writes the new stack's files and removes
  the old one's. The Admin panel's PHP page says which stack runs.
- **nginx alone:** a server block per site, PHP through the account's pool.
  Every site feature has its nginx form: the www. choice, redirects and
  hotlink protection in the rewrite phase, error pages, protected folders
  with `auth_basic` (logins in `/etc/nginx/lpanel/htpasswd`, group nginx).
  Hidden files are refused, links are followed only to their owner's files,
  and an app's own addresses reach its `index.php`.
- **nginx in front of Apache (hybrid):** nginx on :80 serves static files
  itself and passes the rest to Apache on 127.0.0.1:8080, which keeps
  `.htaccess`. Protected folders go to Apache whole. Apache sees the
  visitor's address (`mod_remoteip`), never one the visitor claims.
  Bandwidth is counted once, from nginx's logs.
- **Pipeline:** `nginx -t` over nginx's own configuration with the staged
  sites; Apache reloaded before nginx; the probe asks each server. A pool
  whose socket group changes is restarted, since a reload keeps the old
  socket.
- **Tests:** Go (every stack's rendering, tests and switching); PHP 88;
  `tests/e2e/install.sh` with `WEB=apache` 130 checks, `WEB=nginx` 131,
  `WEB=hybrid` 135. Each run also switches to another stack at the end
  (apache → hybrid, nginx → apache, hybrid → nginx) and checks every site
  and feature came along.

**Exit:** each of the three web stacks serves a PHP site for every domain type.
A deliberately broken config is rolled back automatically.

### M3: SSL
- AutoSSL (Let's Encrypt HTTP-01, ZeroSSL fallback).
- Custom certificate upload and CSR generation.
- Panel hostname certificate and service certificates.

**Exit:** a new domain gets a certificate on its own; renewals and failure
backoff are tested against Pebble.

### M4: Databases
- MariaDB databases, users and grants with the account prefix.
- phpMyAdmin SSO and the remote access list.
- DB size counted in the quota.

### M5: DNS
- PowerDNS, automatic zones, zone editor and templates.
- Reseller nameservers.
- DNS-01 wildcard certificates.

### M6: Email
- Exim + Dovecot: mailboxes, forwarders, autoresponders, catch-all, Sieve
  filters.
- Rspamd + ClamAV.
- Automatic DKIM/SPF/DMARC, and the deliverability check page.
- Outbound limits, queue manager, delivery tracking.
- Roundcube SSO, autodiscover and autoconfig.

**Exit:** a mailbox sends and receives over IMAP/SMTP with TLS. DKIM-signed
mail passes the checks of an external mail test service.

### M7: Files and access
- File manager, Pure-FTPd/FTPS, jailed SSH/SFTP, SSH key manager, disk usage
  explorer.

### M8: Backups
- Restic schedules and retention; local, S3, B2, Wasabi and SFTP destinations.
- User downloads and self-service restore; admin restore.

**Exit:** a full account is restored onto a clean server from S3 and works.

### M9: Security
- Firewall manager, IP and country lists.
- Built-in brute-force blocking.
- ModSecurity + OWASP CRS for each domain.
- Account isolation hardening (`hidepid`, private `/tmp`, symlink protection).

### M10: Operations
- Server dashboard and service watchdog.
- Service manager with safe config editing.
- Cron editor and background task viewer.
- OS, panel and PHP updates.
- GoAccess stats, logs, and alerts (email, Telegram, Slack, webhook).

### M11: Release
- REST API completeness pass and the OpenAPI document; CLI parity; webhooks.
- White-label polish.
- Translations: English, Bangla, Arabic.
- License server, plus activation and grace period in the panel.
- Installer hardening for AlmaLinux/Rocky 8, 9 and 10.
- Upgrade path tests, and documentation.

**Exit:** v1.0.0 is tagged. A fresh install and an upgrade from the previous
release candidate both pass the full package test suite on every
AlmaLinux/Rocky version.

## v1.x (after v1, roughly in this order)
1. Importing account backups from other control panels (the most widely used
   format first), and SSH-based migration from any server.
2. Ubuntu and Debian support.
3. cgroups v2 resource limits and usage graphs.
4. Malware scanner.
5. Git deploy.
6. Cloudflare DNS, DNSSEC, Google Drive and Dropbox backup destinations,
   SnappyMail, AWStats, account username change, panel served on a reseller's
   custom domain.

## v2
Node.js/Python/Docker apps, PostgreSQL, Redis/Memcached, the WordPress toolkit,
the app installer, caching and CDN, OpenLiteSpeed/LiteSpeed, CloudLinux,
the CageFS-like jail, DNS cluster and other DNS APIs, server-to-server
transfer, web terminal, and the plugin system.
