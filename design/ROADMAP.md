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

**M3: certificates. Built 2026-11-12.**
- **AutoSSL** (`ssl:autossl`, every five minutes; the Admin panel's SSL/TLS
  page sets it up, capability `server.ssl`):
  - every site of every active account gets a certificate covering its own
    name, its www. and its aliases' names, from Let's Encrypt, or ZeroSSL
    when Let's Encrypt does not issue (ZeroSSL needs its EAB key id and HMAC
    key; either may be first);
  - before ordering, the agent checks each name: it must resolve and the
    site must answer a token of its own over HTTP. A name that fails is left
    out and the reason shown, so the authorities' limits are spent only on
    names that point here;
  - **renewal:** a certificate is renewed in its last third (at most 30 days
    before it ends). A name it does not cover yet is tried again a day later;
  - **failures** wait 1, 2, 4… up to 24 hours before the next try. A failed
    domain check is not retried at the other authority.
- **Serving HTTPS** on all three stacks:
  - Apache alone has a `:443` VirtualHost per site;
  - nginx, alone or in front of Apache, listens on 443 in each site's server
    block, and Apache behind it learns HTTPS from `X-Forwarded-Proto`;
  - plain HTTP goes to HTTPS unless the owner turns that off. Domain checks
    are answered before every other rule, even for a suspended site;
  - names nobody serves get the hostname's certificate (Apache) or no
    handshake at all (nginx), never a customer's;
  - an expired certificate is not served, so the site falls back to HTTP.
- **The owner's choices** (User panel → SSL/TLS, feature `ssl`): AutoSSL and
  HTTPS on or off per site; "get a certificate now"; installing their own
  certificate (with its key, or without one when it answers a request made
  here); and a request (CSR, ECDSA or RSA) whose key never leaves the server.
- **The hostname's certificate** comes from AutoSSL too, or is installed by
  hand. It serves the panel's ports 7443 and 7444, and is tested with the
  panel's nginx, probed, and rolled back on failure. Mail and FTP will use it
  in M6 and M7.
- **Where it shows:** the API (`/admin/api/v1/accounts/{user}/ssl…`,
  `/admin/api/v1/ssl…`) and `lpanel ssl:list|issue|install|csr|remove|options|autossl|hostname|settings`.
- **Tests:** Go (key and chain checks, the store, issuing with fallback and
  the domain check, the hostname's rollback, HTTPS on each stack); PHP 97;
  `tests/e2e/install.sh` against two Pebbles, standing in for Let's Encrypt
  and for ZeroSSL with EAB:
  - issuing, HTTPS and the move to it, PHP seeing HTTPS, and every site
    feature over HTTPS;
  - left-out names, failure and backoff, the hostname on 7443, and renewal;
  - ZeroSSL taking over when Let's Encrypt is down, the owner's own
    certificate, and CSRs;
  - all of it on each stack, and after switching stacks: `WEB=apache` 145
    checks, `WEB=nginx` 146, `WEB=hybrid` 150.
- **Not yet:** wildcard certificates (DNS-01, M5); mail and FTP certificates
  with SNI per mail domain (M6, M7); expiry alerts (M10).

### M4: Databases
- MariaDB databases, users and grants with the account prefix.
- phpMyAdmin SSO and the remote access list.
- DB size counted in the quota.

**M4: databases. Built 2026-11-12.**
- **Databases and users** (User panel → Databases, feature `databases`; the
  package's `databases` limit counts them):
  - every name carries the account's prefix (`bob_shop`, `bob_app`), within
    MariaDB's and MySQL's limits (64 characters for a database, 32 for a user);
  - users get every privilege on a database, or a chosen set from a fixed
    list; never GRANT OPTION or anything server-wide. Grants name the
    database with `_` escaped, so `bob_shop` never also means `bobxshop`;
  - the panel keeps only MariaDB's own password hash, never the password;
  - renaming moves the tables in one `RENAME TABLE`. A database with views,
    routines, triggers or events is refused rather than half moved;
  - dropping and renaming ask the server first, and only once earlier changes
    have reached it, so a late update can never bring a dropped database back.
- **Remote access:** hosts the account's users may connect from: addresses,
  IPv4 networks (`203.0.113.0/24` becomes MariaDB's address/netmask), host
  names and `%` patterns.
- **Suspension** locks the account's database users and ends their
  connections. **Termination** drops every database and user before the
  system user goes.
- **Space:** `db:usage` (every 15 minutes) measures each database's folder.
  The home's quota becomes the package's space less the databases', so files
  and databases together stay within the package. Both panels show the total.
- **phpMyAdmin 5.2.3** on the User panel's port at `/phpmyadmin/`, in a
  PHP-FPM pool of its own as the `lpanel-pma` user. "Open phpMyAdmin" signs
  in with no second password:
  - the agent makes the account's own `lpsso-<account>` user with a fresh
    random password and every privilege on the account's databases only;
  - the panel hands it over in a one-time ticket that lives a minute;
  - that user is removed eight hours after its last sign-on.
- **Admin panel → Databases** (capability `server.databases`): MariaDB's
  version and state, its connections (an account's can be ended; root's,
  the panel's and MariaDB's own cannot), and every account's databases with
  their sizes.
- **Where it shows:** the API (`/admin/api/v1/accounts/{user}/databases…`,
  `/admin/api/v1/databases…`) and `lpanel db:list|create|drop|rename|user-create|user-password|user-drop|grant|revoke|host-add|host-remove|usage|settle|sync|info|kill`.
  Passwords are read from standard input.
- **Tests:** Go (SQL built only from checked names, hashes, privileges and
  hosts; escaped grants; renames; suspension; single sign-on; kill rules;
  sizes); PHP 106; `tests/e2e/install.sh` against the real MariaDB and
  phpMyAdmin:
  - users, privileges, passwords, remote hosts, renames and drops;
  - a site's PHP reaching its database; the `_` escape;
  - ending a connection; quota with and without data; suspension;
  - phpMyAdmin's sign-on, used once, showing only the account's databases;
  - termination; and the audit log;
  - `WEB=apache` 169 checks, `WEB=nginx` 170, `WEB=hybrid` 174; agent e2e 37.
- **Not yet:**
  - the firewall opening port 3306 to an account's remote hosts only (M9);
  - MariaDB's service settings and root password (M10, with the service
    manager);
  - PostgreSQL (v2).

### M5: DNS
- PowerDNS, automatic zones, zone editor and templates.
- Reseller nameservers.
- DNS-01 wildcard certificates.

**M5: DNS. Built 2026-11-15.**
- **PowerDNS** with its own `pdns` database. Its HTTP API listens on
  127.0.0.1 with a key only the agent holds; the panel never reaches it.
- **Zones:** every main, addon and alias domain gets one, made from a
  template. A subdomain's address, and `*.` for a domain that answers every
  subdomain, go into the zone above it. A removed domain takes its zone or
  its records with it; a terminated account takes all its zones. Records
  the owner changed or removed are not made again by later changes.
- **The zone editor** (User panel → DNS, feature `dns`): A, AAAA, CNAME, MX,
  TXT, SRV, CAA and NS for subdomains, with each mistake explained beside
  its field; reset to the template. Administrators also edit any zone and
  add PTR records.
  - The agent checks every record again, quotes TXT itself, and writes only
    what changed. The serial follows the date (`2026111501`).
  - Changes the server refuses are undone. Changes it cannot take yet wait
    for `dns:settle` (every minute), as removals do.
- **Nameservers:**
  - the server's (Admin panel → DNS, capability `server.dns`) and each
    reseller's own (capability `dns.own`) become the zones' NS records and
    SOA. Changing them changes every zone that uses them at once;
  - each nameserver shows whether its name leads to this server, with the
    glue to register. A server zone for the nameservers' own domain gets
    their address records by itself;
  - each zone shows whether its domain's registry names these nameservers.
- **Templates:** the server's and each reseller's, one record a line, with
  `{domain}`, `{ip}` and `{ip6}`.
- **Server zones:** for domains no account holds, such as the nameservers'
  own or reverse zones (`in-addr.arpa`).
- **Wildcard certificates:** AutoSSL asks for `*.example.com` when the
  domain's zone is here. The agent first checks that the world's DNS reaches
  this server, then answers the DNS-01 check in the zone, beside the owner's
  own records, and removes it afterwards.
- **Where it shows:** the API (`/admin/api/v1/accounts/{user}/dns…`,
  `/admin/api/v1/dns…`, `/admin/api/v1/dns/nameservers`) and
  `lpanel dns:zones|records|add|set|remove|reset|zone-create|zone-remove|settings|nameservers|template|info|settle|sync`.
- **Tests:** Go (records and their checks, TXT quoting, idempotent changes,
  serials, ownership, the check's records, delegation, wildcard issuing);
  PHP 116; `tests/e2e/install.sh` against the real PowerDNS, with `dig`,
  and a Pebble that resolves through it for the wildcard certificate:
  `WEB=apache` 191 checks, `WEB=nginx` 192, `WEB=hybrid` 196; agent e2e 37.
- **Fixed on the way:** the agent sent ZeroSSL's EAB credentials to every
  authority when registering, so a new Let's Encrypt account failed once
  ZeroSSL's were set. They now go to ZeroSSL only.
- **Not yet:**
  - DNSSEC (v1.x);
  - response rate limiting on port 53 (M9, with the firewall);
  - Cloudflare and other DNS APIs (v1.x and v2).

### M6: Email
- Exim + Dovecot: mailboxes, forwarders, autoresponders, catch-all, Sieve
  filters.
- Rspamd + ClamAV.
- Automatic DKIM/SPF/DMARC, and the deliverability check page.
- Outbound limits, queue manager, delivery tracking.
- Roundcube SSO, autodiscover and autoconfig.

**Exit:** a mailbox sends and receives over IMAP/SMTP with TLS. DKIM-signed
mail passes the checks of an external mail test service.

M6 is in three parts: M6a (mail flow and mailboxes), M6b (filtering, the
owner's filters, sending limits, the queue and tracking), M6c (webmail,
autoconfiguration, a certificate for each mail domain, deliverability).

**M6a: mail flow and mailboxes. Built 2026-11-16.**
- **Exim 4.100 (EPEL) and Dovecot 2.3 (AppStream)**, with configurations the
  installer writes whole. Accounts' mail is data the agent writes, one file
  a domain, read on every lookup: no change reloads a server.
  - SMTP 25; submission 465 (TLS) and 587 (STARTTLS), a login required;
    IMAP 993/143 and POP3 995/110; passwords only over TLS.
  - Local mail goes from Exim to Dovecot over LMTP; Exim checks logins
    through Dovecot. Only localhost relays.
  - The hostname certificate serves both; the agent copies it whenever it
    changes and reloads them.
- **Mail domains:** every domain of an account (main, addon, alias and
  subdomains) receives mail. Mail for a domain may be "delivered by another
  server" (its MX is elsewhere).
- **Mailboxes:** a password (only its bcrypt hash leaves the panel) and a
  quota (0: none of its own). Mail is kept in the account's home, as the
  account, so it counts against the package's disk space. `info+tag@`
  reaches `info@`. The package's `mailboxes` limit applies.
- **Forwarders** (a copy stays when the address is also a mailbox),
  **catch-alls** (refuse, discard, or an address) and **autoresponders**
  (Sieve, once per sender in 1–30 days, with optional dates).
- **DKIM, SPF, DMARC:** the agent makes each domain's DKIM key; the panel
  publishes it with `v=DMARC1; p=none` (and SPF for subdomains) in the
  account's zone. The owner may change or remove them, and they come back
  when a zone is reset. When the domain's DNS is elsewhere, the User panel
  lists the records to add there.
- **Sending is checked:** a mailbox sends only as its own account's domains
  (envelope and `From:`). Mail is DKIM-signed only when the `From:` domain
  is the sender's own account's, including a site's own mail through the
  sendmail command. A suspended account still receives mail; its mailboxes
  cannot log in or send.
- **Deleting:** a deleted mailbox's mail is deleted once the server no
  longer has the mailbox; `mail:settle` (every minute) finishes what the
  agent could not take at once. A terminated account's mail files go
  before its user.
- **Usage:** `mail:usage` (every 15 minutes) measures each mailbox.
- **Admin panel → Email** (capability `server.mail`): Exim's and Dovecot's
  state, the queue, every account's mail domains, and the **relay host** for
  outgoing mail (for servers that may not send on port 25).
- **Where it shows:** User panel → Email (feature `email`), the API
  (`/admin/api/v1/accounts/{user}/email…`, `/admin/api/v1/email…`), and
  `lpanel mail:list|create|password|quota|delete|forward|unforward|responder|domain|settings|info|usage|settle|sync`.
  Passwords and autoresponder messages are read from standard input.
- **Tests:** Go (every file, idempotence, refused input, ownership,
  suspension, Sieve quoting, purges that cannot leave the home, usage, the
  relay host); PHP 128; SELinux check 71 (mail labels and EL's rules for
  Exim and Dovecot); `tests/e2e/install.sh` against the real servers
  (`swaks`, IMAP, POP3, DKIM verified against PowerDNS): `WEB=apache`
  216 checks, `WEB=nginx` 217, `WEB=hybrid` 221; agent e2e 37.
- **Not yet:** see M6b and M6c below.

**M6b: filtering, filters, limits, the queue. Built 2026-11-17.**
- **Spam filtering (Rspamd 4.3, its own signed repository).** Exim asks
  Rspamd (127.0.0.1:11333) about every message from outside while
  `/etc/exim/lpanel/filter` is there. What scores the server's rejection
  score (15 by default) is refused while the sender is still connected; the
  rest carries its score in `X-LPanel-Spam-Score` (tenths, never below 0;
  any copy a sender wrote is removed). On from the first installation.
  - Rspamd's controller answers only on a 0600 socket; the milter proxy is
    off; it signs nothing (Exim does DKIM). No Redis yet, so no Bayes
    learning or greylisting.
- **Virus scanning (ClamAV, EPEL).** Off by default (it needs about 1.5 GB of
  memory). When on, Rspamd asks clamd over its group's socket and Exim
  refuses what carries a virus; clamd waits for freshclam's first download
  by restarting every minute.
- **Each account's spam settings** (User panel, Email, Spam filter): move
  spam to Junk from a score (6 by default), delete it from a higher one,
  allowed senders (never filed as spam) and blocked senders (thrown away),
  as addresses or @domains.
- **Each mailbox's filters**: From, To/Cc or Subject; contains, is, begins
  or ends with; move to a folder (made when missing), forward a copy, or
  delete; "then stop". In order, after the spam settings and before the
  autoresponder: the agent writes one Sieve script a mailbox and compiles
  it. A forwarded copy leaves with the mailbox's own address as its sender.
- **Sending limit** (the package's "Email recipients an hour"): Exim's
  ratelimit counts the account's recipients from its mailboxes and its
  sites together (and what its filters and autoresponders send); over it,
  SMTP is told to try later and the sendmail command is refused.
- **SRS**: mail a forwarder sends on leaves with an SRS address of the
  forwarding domain as its sender, so SPF passes at the destination; a
  bounce to it is decoded and goes back to the original sender.
- **Relay host login**: a user and password (PLAIN, only over TLS) for the
  relay host. The password goes to the agent once and is kept only in
  Exim's data, readable by root and Exim's group; never in the panel's
  database or audit log.
- **Queue manager** (Admin panel, Email, Queue): search; see a message and
  Exim's log of it; deliver now, freeze, thaw, remove; try every message
  now. Deliveries run in the background (`systemd-run`).
- **Delivery tracking** (both panels): what became of each message
  (delivered, retrying, bounced, refused, waiting), read from Exim's log
  and its rotations, over 1 to 30 days. An account sees only mail from or to
  its domains, and of mail to it only its own recipients; refusals of spam
  name their recipients in the log for that.
- **Where it shows:** the Email pages have tabs (User panel: Mailboxes,
  Spam filter, Track delivery; Admin panel: Overview, Queue, Track
  delivery); the API (`…/email/spam`, `…/mailboxes/{address}/filters`,
  `…/filters/{id}`, `…/track`, `/admin/api/v1/email/filtering`, `…/queue`,
  `…/queue/{id}`, `…/track`); `lpanel mail:spam|filter|filtering|queue|track`,
  `mail:settings --relay-user` (password on standard input),
  `packages:create --mail-per-hour`. Every change is audited (`mail.spam`,
  `mail.filter_*`, `mail.filtering`, `mail.queue`, `mail.settings`).
- **Tests:** Go (scripts and their quoting, refused filters and senders, the
  limit file, the relay login's escaping, filtering's order of starting
  services, the queue's parsing and ids, tracking's per-account view); PHP
  134; SELinux check 78 (Rspamd's port is `spamd_port_t`, which EL's policy
  lets Exim reach); `tests/e2e/install.sh` against the real servers (GTUBE,
  an e2e-only Rspamd rule for Junk, an EICAR test signature for ClamAV, a
  TLS relay host that records its login): `WEB=apache` 230 checks,
  `WEB=nginx` 231, `WEB=hybrid` 235; `tests/e2e/agent.sh` 37.
- **Not yet:** Bayes learning from what owners move to Junk (needs Redis);
  greylisting; per-mailbox spam settings; filters on headers other than
  From, To and Subject; a relay host on port 465 (TLS from the start).

**M6c: webmail, autoconfiguration, certificates, deliverability. Built 2026-11-17.**
- **Webmail: Roundcube 1.7.6** (its release pinned by checksum, its
  signature checked when pinned) at `/webmail/` on the User panel's port,
  in a PHP-FPM pool of its own as `lpanel-webmail`, with its own database
  (`lpanel_webmail`). Mailbox users sign in with their address and password.
  - **"Open webmail"** (User panel, each mailbox) signs in with no password:
    the agent gives a random sign-on password for the mailbox (`mail.sso`),
    good for eight hours and the same while it is good, which Dovecot
    accepts beside the mailbox's own; the panel hands it to Roundcube in a
    one-time ticket (its `lpanel_sso` plugin). It ends when the mailbox goes,
    the account is suspended, the mailbox's password changes, or its time is
    up (`mail:settle`).
  - `webmail.<domain>` on every mail domain's site goes to webmail.
- **Mail clients set themselves up:** every site of an account with email
  passes Thunderbird's autoconfig and Outlook's autodiscover on to the panel
  (on 127.0.0.1:7449, which serves nothing else), at any of its names; the
  panel answers with the servers and ports for the domain asked about.
  `autoconfig.` and `autodiscover.<domain>` are in the zone and the site.
- **A setup page for each mailbox** (User panel, Email, Set up): username,
  server, every port and its security, copy buttons, and an Apple profile
  (iPhone, iPad, Mac) to download.
- **A certificate for each mail domain:** each mail domain's site answers to
  `mail.<domain>` too, so AutoSSL puts it in the site's certificate; Exim
  and Dovecot then serve that certificate for `mail.<domain>` by SNI, and
  mail clients are told to use that name. Until then they are told the
  server's hostname. A renewal reaches mail by itself (`web.applied`).
- **Deliverability** (both panels, Email, Deliverability): for each mail
  domain, SPF evaluated for the server's address (include, a, mx, ip4/6,
  redirect, the ten-lookup limit), the DKIM key against the server's, DMARC
  and its policy, MX pointing here; for the server, its address's reverse
  name (and that it leads back) and five blocklists. Each finding says what
  to do. The Admin panel checks the server, and any account by name.
- **Where it shows:** the API (`…/email/mailboxes/{address}/webmail` gives a
  one-time address, `…/mailboxes/{address}/setup`, `…/email/checks`,
  `/admin/api/v1/email/checks`) and `lpanel mail:webmail|setup|check`,
  `web:sync --all --changed`. Sign-ons are audited (`mail.webmail`), their
  passwords never.
- **Tests:** Go (sign-on passwords: hashing, reuse, expiry, suspension,
  removed mailboxes, refusals; SNI copies: coverage, validity, renewal,
  removal, Dovecot's reload; SPF evaluation and the checks; the sites'
  mail rules on all three stacks); PHP 139; SELinux check 82;
  `tests/e2e/install.sh` against the real servers (Roundcube's login and the
  sign-on, autoconfig and autodiscover through the sites, a Pebble
  certificate served by SNI on 993, 465 and 587, the checks against
  PowerDNS): `WEB=apache` 238 checks, `WEB=nginx` 239,
  `WEB=hybrid` 243; `tests/e2e/agent.sh` 37.
- **Not yet:** ManageSieve (owners' own Sieve scripts from Roundcube);
  webmail on a hostname of its own; DNS-based SRV records for autodiscover;
  checking deliverability on a schedule and alerting (M10).

### M7: Files and access
- File manager, Pure-FTPd/FTPS, jailed SSH/SFTP, SSH key manager, disk usage
  explorer.

M7 is in three parts: M7a (the file manager and the disk usage explorer),
M7b (FTP accounts on Pure-FTPd, with FTPS), M7c (jailed SSH and SFTP, and
the SSH key manager).

**M7a: the file manager and disk usage. Built 2026-11-17.**
- **The agent works as the account.** Every file operation runs in a
  worker, the agent's own binary started as the account's user (its uid,
  its group only, a fixed environment), which opens the home as an
  `os.Root`: every path stays beneath it, and a link that leads out is
  refused wherever it is in the path. The account's own permissions and disk
  quota apply, as to its SSH or PHP. The agent never reads or writes a
  customer's file as root.
- **Contents never travel in a request** (requests are small, signed and
  journalled). Uploads, saved files and downloads go through the panel's
  staging folder (`/var/lib/lpanel/files`, the panel's alone, also its PHP's
  upload folder) under random names; the agent opens one by name, takes only
  a plain file with one link that root does not own, and hands the worker the
  open descriptor.
- **The file manager** (User panel → Files, feature `file_manager`):
  - browse with a path bar, hidden files shown or not, links with where they
    lead (and a warning when it is out of the home);
  - new folder, new file, upload several files at once (64 MB, the panel's
    limit), from the form or dropped on the list, replace or not;
  - with what is selected: rename, move, copy (to a folder typed from the
    home or from here), permissions (the nine bits, never setuid),
    compress (`.zip`, `.tar.gz`, `.tar`), delete; extract an archive into a
    folder; download a file;
  - **the editor**: text up to 4 MB, Tab and Shift+Tab, Ctrl+S, a warning
    before leaving unsaved changes; line ends kept; saved over a temporary
    file renamed into place; a save over someone else's change since it was
    opened is refused (its SHA-256) and what was typed is kept;
  - `public_html`, `mail`, `tmp` and `logs` are the server's: what is in
    them is the owner's, but they cannot be deleted, renamed, moved or
    chmod'ed;
  - archives unpack at most 8 GB and 200,000 entries; links, devices and
    names that would climb out are left out, and files already there stay.
  - Every page works without JavaScript; on a phone the panels open as a
    sheet along the bottom.
- **Disk usage** (Files → Disk usage): what in a folder takes the space,
  largest first, with each folder's share and how many files are in it;
  open a folder to look inside; the account's files, mail and databases
  against the package's space.
- **A suspended account's owner** is signed out as before; administrators
  still look and clean up through the API and the CLI.
- **With SELinux on**, what is moved into `public_html`, `tmp`, `logs` or
  `mail` gets that folder's labels.
- **Where it shows:** the API (`/admin/api/v1/accounts/{user}/files`,
  `…/content`, `…/download`, `…/upload`, `…/usage`, `…/mkdir|create|rename|move|copy|delete|chmod|compress|extract`)
  and `lpanel files:list|read|write|upload|download|mkdir|rename|move|copy|delete|chmod|compress|extract|usage|sweep`
  (`files:write` reads standard input). Every change is audited
  (`files.*`, and downloads), never a file's contents.
- **Tests:** Go (paths and names; staged files: links, hard links, root's;
  links out of the home for every operation; saving and its conflict;
  copying, moving and the server's folders; archives in all three formats,
  zip-slip and a tar link followed by a file through it; disk usage); PHP
  147; `tests/e2e/install.sh` with the real agent, as the account's user
  (a root-owned folder, links to `/etc` and to another account, the quota on
  an upload, the panel's edit, upload and download, the site serving what
  was saved, the label of a moved file): `WEB=apache` 249 checks,
  `WEB=nginx` 250, `WEB=hybrid` 254; `tests/e2e/agent.sh`
  37.
- **Not yet:** uploads over 64 MB in pieces (FEATURES.md's "chunked");
  syntax highlighting in the editor (it needs an editor library served by
  the panel itself, as the CSP allows no other); archives packed or unpacked in
  the background for very large folders; a trash to undo a delete;
  recursive permissions; administrators opening the file manager itself
  (they log in as the owner).

**M7b: FTP. Built 2026-11-18.**
- **Pure-FTPd, virtual users only.** Each login is `name@domain` (one of
  the account's domains, unique on the server) and works as the account's
  own Unix user, shut in a folder of its home, so the account's permissions
  and disk quota hold as for its PHP. No anonymous logins, no system users,
  no PAM. Only a bcrypt hash is kept.
- **The agent** keeps one file of logins per account
  (`/etc/pure-ftpd/lpanel/users.d/<account>`, 0600 root), joins them and has
  `pure-pw` index them; Pure-FTPd reads the index at each login, so nothing
  reloads. A missing folder is made as the account's (through an `os.Root`:
  no link out). A login of another account is refused. A changed or removed
  login's sessions end at once; a suspended account's logins are taken out
  and its sessions end; a terminated account's go before its user.
- **FTPS**: Pure-FTPd serves the hostname certificate (copied whenever
  AutoSSL renews it). TLS is required for logins by default; the Admin panel
  chooses optional, required or strict (transfers too).
- **User panel → FTP** (feature `ftp`, the package's `ftp_accounts` limit):
  the logins with their folders; new login (name, domain, password with a
  generator, folder, `public_html` by default), change password, change
  folder, remove (the files stay); what to type into an FTP client; the
  sessions open now, which the owner can end.
- **Admin panel → FTP** (capability `server.ftp`): Pure-FTPd's state and
  version; settings (TLS, passive ports, the address given out behind NAT,
  clients at once and from one address), written whole into
  `pure-ftpd.conf`, with the old file put back if Pure-FTPd will not start;
  every session on the server with its account, to end; every login. The
  account page shows its logins.
- **Where it shows:** the API (`/admin/api/v1/accounts/{user}/ftp`,
  `…/ftp/{login}`, `…/ftp/sessions`; `/admin/api/v1/ftp`,
  `…/ftp/settings`, `…/ftp/sessions/{pid}`) and
  `lpanel ftp:list|create|password|folder|delete|sessions|kill|settings|settle|sync`
  (passwords on standard input). Every change is audited (`ftp.*`), never a
  password.
- **Installer:** Pure-FTPd from EPEL (vsftpd and proftpd are stopped),
  `ftp:settings --apply`, firewalld's ftp service and ports 30000–30100.
- **Tests:** Go (logins and their file, folders and links out, another
  account's login, sessions ended by changes and suspension, ending only
  listed sessions, the configuration and its roll-back, the certificate);
  PHP 156; SELinux check 86; `tests/e2e/install.sh` with the real
  Pure-FTPd (FTPS uploads owned by the account, the folder as the whole
  view, plain FTP and system users refused, new passwords and folders at
  once, the quota, a session listed and ended, suspension, the TLS setting,
  the panel and the API): `WEB=apache` 264 checks, `WEB=nginx`
  265, `WEB=hybrid` 269; `tests/e2e/agent.sh` 37.
- **Not yet:** FTP traffic in the bandwidth count; brute-force blocking
  (M9); a quota or bandwidth limit per login; an SELinux domain for
  Pure-FTPd (EL's policy has none, so it runs unconfined); FTP logins as
  sub-users' own (a sub-user with the `ftp` grant manages the account's).

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
