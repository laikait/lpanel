# LPanel architecture

LPanel is installed on each hosting server and manages only that server. It is
made of two programs and a small helper:

| Part | Language | Runs as | Job |
|---|---|---|---|
| **Panel** (this repo) | PHP 8.3 on LPHP | `lpanel` (unprivileged) | Web UI, REST API, CLI, its own database, decisions and permissions |
| **Agent** (`lpa`, in `dist/lpa/`) | Go | root | The only thing that changes the system: users, configs, services, files |
| **authd** (`lpanel-authd`) | Go | root, socket-activated | Checks the root password through PAM; nothing else |

```
 Browser / API client / billing system
        │ HTTPS :7443 (Admin panel)  :7444 (User panel)  :7445 (webmail)
        ▼
 ┌──────────────────────────────┐
 │ panel nginx (own instance)   │   separate from the customers' web server
 │ panel PHP-FPM (user lpanel)  │
 │   LPHP modules               │──── MariaDB "lpanel" schema (panel state)
 └──────────┬───────────────────┘
            │ Unix socket /run/lpa/lpa.sock
            │ (SO_PEERCRED = lpanel uid, every request signed)
            ▼
 ┌──────────────────────────────┐        ┌──────────────────────────────┐
 │ lpa (root)                   │        │ lpanel-authd (root)           │
 │ operation registry + journal │        │ PAM "lpanel" service          │
 └──────────┬───────────────────┘        └──────────────────────────────┘
            ▼
 Apache / Nginx · PHP-FPM pools · MariaDB · PowerDNS · Exim · Dovecot · Rspamd
 ClamAV · Pure-FTPd · restic · firewalld · ModSecurity · quotas · systemd
```

## Rules

1. **The panel decides, the agent does.** The panel checks who is asking and
   whether they're allowed. It then sends the agent a named operation with
   typed arguments, such as `account.create` or `domain.add`. The agent checks
   the arguments again, because it does not trust the panel to have done so.
2. **The agent has no shell API.** It only runs operations from a fixed
   registry. Each operation declares its arguments, timeout and whether it is
   safe to retry.
3. **Exactly once.** Every operation carries an idempotency key. The agent
   journals it, so a retry after a crash or timeout does not repeat the work.
4. **Configs are generated, staged and tested.** Every config goes through the
   same steps: render → write to a staging path → syntax-test the candidate →
   swap → reload → probe → roll back if the probe fails. Edits made by hand are
   detected and reported, never silently overwritten.
5. **Panel state lives in the panel's database. System state lives on the
   system.** A scheduled reconcile job compares the two and reports drift.
6. **Customer isolation by default.** Each account is a Linux user with its
   own PHP-FPM pool. Home folders are 0711, `/proc` is mounted with `hidepid`,
   each account gets a private `/tmp`, and kernel quotas apply.

## Panel (this repo)

The panel is built on LPHP 3.2.0 modules, with one module per feature area
under `modules/`. The framework in `engine/` is not edited.

| Module | Covers |
|---|---|
| `Identity` | users, tiers and their roles, sessions and session epochs, 2FA, API tokens, "log in as" |
| `Audit` | audit log |
| `Agent` | socket client, request signing |
| `Packages` | packages: limits and feature lists (the `feature.*` capabilities) |
| `Accounts` | accounts and their agent operations (the `Provisioner`), quotas, bandwidth |
| `Resellers` | resellers: allowance, overselling, ACL; later nameservers and branding |
| `Subusers` | sub-users and their grants; the gate on `feature.*` |
| `Web` | domains, vhosts, PHP versions, redirects, error pages |
| `Ssl` | AutoSSL, certificates |
| `Databases` | MariaDB DBs/users, phpMyAdmin SSO |
| `Dns` | PowerDNS zones and templates |
| `Mail` | mailboxes, forwarders, filters, DKIM, queue |
| `Files` | file manager, FTP, SSH keys |
| `Backups` | schedules, destinations, restore |
| `Security` | firewall, brute force, ModSecurity |
| `Monitor` | dashboard, services, stats, alerts |
| `System` | updates, settings, license |

There are two interfaces over one set of routes and one API:

| Interface | Port | Who |
|---|---|---|
| **Admin panel** | :7443 | Root admins and panel admins see everything. Resellers see what their ACL allows, with their own branding. |
| **User panel** | :7444 | Account owners see their account. Sub-users see what the owner granted. |

Webmail is served on :7445. All three ports can be changed at install time.

## Agent (`lpa`)

The agent's source is in this repo at [`dist/lpa/`](../dist/lpa/README.md), as
its own Go module with no third-party dependencies. It builds to a single
static binary, run by systemd as `lpa serve`, listening on `/run/lpa/lpa.sock`.
Its README covers the wire format, the checks in order, the journal and the
operation list.

Each subsystem is a package under `internal/` (so far `sysinfo`, `accounts`
and `quota`; later `web`, `php`, `mysql`, `dns`, `mail`, `ftp`, `backup`,
`firewall`), registered as operations in `internal/ops`. Each reads the system
through paths it is given, so tests run against fixture files without root.
Operations that need the system's own tools (`useradd`, `usermod`, `userdel`,
`pkill`) run them through `internal/sysexec`: a fixed list of absolute paths,
arguments as a list, never a shell, and a fixed minimal environment.

The panel never calls an operation that changes the system and forgets it.
`Accounts\Provisioner` writes the operation, its arguments and a new id to the
account's row first, then sends it. An answer it never received is asked for
again by `accounts:settle` (every minute) with the same id, and the agent's
journal returns the first outcome. The same pattern is used for every later
subsystem.

The panel's side is the `Agent` module. It provides `AgentClient`, the signing
key and `agent:*` commands. `tests/Unit/AgentProtocolTest.php` signs the Go
golden vectors, and `tests/e2e/agent.sh` runs both halves together in
AlmaLinux 9.

Code from the earlier attempt, still to review and reuse where it fits:

- `C:\xampp\htdocs\lpa\internal\websites`: the staged config pipeline, and the
  Apache and Nginx renderers (M2).
- The ACME client code there (M3).
- `helpers/authd`: in this repo's git history at commit `ee0adbd` (M0c).

Already carried over in M0b: `sysinfo`, `fsutil` and the SO_PEERCRED check.
authd came over in M0c.

## The panel's own PHP

On EL9 the panel runs on Remi's `php83`, installed under `/opt/remi/php83`,
beside whatever PHP versions customers' sites use. AppStream's PHP has no
sodium extension on EL9, and the panel signs agent requests with it. Keeping
the panel's PHP separate also means a customer-facing PHP upgrade never
touches the panel.

## SELinux

LPanel runs with SELinux enforcing. Its policy module is in
[`dist/selinux/`](../dist/selinux/lpanel.te), and the installer loads it
whenever SELinux is on.

| Process | Domain |
|---|---|
| the panel's nginx and PHP-FPM | `httpd_t` (their binaries are `httpd_exec_t`) |
| `lpa` | `lpanel_agent_t`, unconfined |
| `lpanel-authd` | `lpanel_authd_t`, unconfined |
| the scheduler and `lpanel` commands (php83 CLI) | `unconfined_service_t` / the caller's |

`httpd_t` may connect to `lpanel_agent_t` and `lpanel_authd_t` only, through
`lpanel_sock_t` sockets. That is the reason the two root programs have
domains of their own: the panel can reach them without being allowed to
reach every unconfined service. The module also labels the panel's paths
(see the table below). The agent runs `restorecon` on each new home, so
`public_html` becomes `httpd_user_content_t`.

## Layout on an AlmaLinux 9 server

| Path | What |
|---|---|
| `/usr/local/lpanel/` | panel code, root-owned and read-only to the panel |
| `/usr/local/lpanel/.env` | panel settings and secrets (APP_KEY, database password), 0640 root:lpanel |
| `/usr/local/lpanel/system/` | the only part of the code tree the panel writes (cache, logs, sessions) |
| `/etc/lpanel/agent.key` | the panel's signing key for agent requests, 0600 lpanel |
| `/etc/lpanel/tls/` | the panel's TLS certificate and key (key 0600 root) |
| `/etc/lpanel/php-fpm.conf` | the panel's own PHP-FPM master (`lpanel-php-fpm.service`) |
| `/etc/lpanel/nginx/` | the panel's own nginx (`lpanel-nginx.service`, ports 7443 and 7444) |
| `/etc/lpanel/authd.json`, `/etc/pam.d/lpanel` | root sign-in: who may be checked, and the PAM stack used |
| `/var/lib/lpanel/`, `/var/log/lpanel/` | panel data and logs |
| `/usr/local/lpa/bin/lpa`, `/usr/local/lpa/bin/lpanel-authd` | the two root programs (`lpanel_agent_exec_t`, `lpanel_authd_exec_t`) |
| `/etc/lpa/lpa.json`, `/etc/lpa/panel.pub` | agent config, and the panel's public key |
| `/var/lib/lpa/journal/` | agent journal |
| `/run/lpa/lpa.sock`, `/run/lpanel-authd/authd.sock` | the two sockets, 0660 root:lpanel |
| `/home/<user>/` | account homes (`public_html`, `mail`, `logs`, `tmp`, `.lpanel`) |
| `/etc/httpd/conf.d/lpanel/` or `/etc/nginx/lpanel/` | generated vhosts for customers' sites (M2) |
| `/etc/opt/remi/php<XY>/php-fpm.d/<user>.conf` | per-account pools, in each Remi PHP version's own pool folder (M2) |

Services: `lpa`, `lpanel-authd.socket` (socket-activated), `lpanel-php-fpm`,
`lpanel-nginx`, `lpanel-scheduler.timer` (every minute), and `mariadb`. The
packages' own `nginx` and `php83-php-fpm` services stay off. The installer is
`dist/installer/install.sh`, run from a bundle made by
`dist/installer/bundle.sh`.

## Decisions

All decisions are recorded in [adr/](adr/).
