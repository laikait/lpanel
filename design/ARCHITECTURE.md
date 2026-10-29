# LPanel architecture

LPanel is installed on each hosting server and manages only that server. It is
made of two programs and a small helper:

| Part | Language | Runs as | Job |
|---|---|---|---|
| **Panel** (this repo) | PHP 8.3 on LPHP | `lpanel` (unprivileged) | Web UI, REST API, CLI, its own database, decisions and permissions |
| **Agent** (`lpa`, separate repo) | Go | root | The only thing that changes the system: users, configs, services, files |
| **authd** (`lpanel-authd`) | Go | root, socket-activated | Checks the root password through PAM; nothing else |

```
 Browser / API client / billing system
        │ HTTPS :2087 (WHM, reseller)  :2083 (cPanel)  :2096 (webmail)
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
| `Identity` | users, tiers, sessions, 2FA, API tokens, "log in as" |
| `Access` | roles, reseller ACLs, sub-user grants, feature lists |
| `Audit` | audit log |
| `Agent` | socket client, request signing, operation records and their state machine |
| `Accounts` | accounts, packages, quotas, bandwidth |
| `Resellers` | resellers, overselling, nameservers, branding |
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

There are three UI skins over one set of routes:

| Skin | Port |
|---|---|
| WHM (admin) | :2087 |
| Reseller | :2087, limited by the reseller's ACL |
| cPanel (user) | :2083 |

Sub-users get the cPanel skin, limited by their grants.

## Agent (`lpa`)

The agent is a single static Go binary run as a systemd service that
listens on a Unix socket. It has one package per subsystem, for example
`accounts`, `web/apache`, `web/nginx`, `php`, `mysql`, `dns`, `mail`, `ftp`,
`backup`, `firewall` and `quota`. Each package implements the operations it
owns and has a pretend-system backend, so tests can run without root.

Code from the earlier attempt, to review and reuse where it fits:

- `internal/execd`: socket server and peer credential checks.
- `internal/websites`: the staged config pipeline, and the Apache and Nginx
  renderers.
- The ACME client code.
- `helpers/authd`: in this repo's git history at commit `ee0adbd`.

## Layout on an AlmaLinux 9 server

| Path | What |
|---|---|
| `/usr/local/lpanel/` | panel code |
| `/etc/lpanel/` | panel config, signing keys |
| `/var/lib/lpanel/` | panel data (uploads, generated files) |
| `/var/log/lpanel/` | panel logs |
| `/usr/local/lpa/bin/lpa` | agent binary |
| `/var/lib/lpa/` | agent journal |
| `/etc/lpa/` | agent config |
| `/home/<user>/` | account homes (`public_html`, `mail`, `logs`, `tmp`, `.lpanel`) |
| `/etc/httpd/conf.d/lpanel/` or `/etc/nginx/lpanel/` | generated vhosts |
| `/etc/opt/remi/php<XY>/php-fpm.d/<user>.conf` | per-account pools, in each Remi PHP version's own pool folder |

## Decisions

All decisions are recorded in [adr/](adr/).
