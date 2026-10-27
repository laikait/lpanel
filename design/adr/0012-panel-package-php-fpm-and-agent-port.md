# ADR-0012: The panel package: its own PHP-FPM, a separate agent port, a .deb with local MariaDB

- **Status:** Accepted (product owner, 2026-10-27)
- **Context:** M5 packages the panel. Three choices shape the package:
  - **PHP-FPM.** [ADR-0003](0003-separate-panel-nginx-instance.md) gave the panel its own Nginx, so that hosting work can never break it. From M6, LPA writes customer PHP-FPM pools and reloads PHP-FPM. One bad pool fails `php-fpm -t` and the reload, which would take a panel pool in the same master down with it.
  - **Agent port.** Agents pin the panel's TLS key (SPKI). If browsers and agents share a listener, replacing the browser certificate (for example with one from Let's Encrypt, MVP_SCOPE Q1) changes the key and breaks every agent. Also, `ssl_verify_client optional_no_ca` on a browser port makes some browsers offer a "choose a certificate" dialog.
  - **Shipping.** The agent ships as a `.deb` tested on all four OSes ([ADR-0004](0004-os-matrix.md)). The panel needs Nginx, PHP and MariaDB ([ADR-0008](0008-panel-database-mariadb.md)).

## Decision

1. **The panel runs its own PHP-FPM master,** `lpanel-php-fpm.service`. It uses the distro's `php-fpm<version>` binary (linked to `/usr/lib/lpanel/php-fpm` by `lpanel-setup`) with its own `/etc/lpanel/php-fpm.conf`, and runs as the `lpanel` user. Its socket is `/run/lpanel-php-fpm/php-fpm.sock`, 0660 `lpanel:lpanel-web`. Customer pools stay in the distro's PHP-FPM.
2. **Two listeners on panel Nginx:**
   - `8443` for people: the screens and the API, with `panel.crt`, which the administrator may replace. `/agent/` answers 404 there.
   - `8444` for agents: `/agent/v1/*` only, `ssl_verify_client optional_no_ca`, with `agent.crt`, whose key agents pin. That key is made once and never replaced.

   Both ports are set in `/etc/lpanel/lpanel.conf`.
3. **A `lpanel` `.deb`** (amd64, because it carries `lpanel-authd`, which is built against PAM on the oldest glibc in the matrix):
   - It depends on the distro's `nginx`, `php-fpm`, `php-cli`, `php-mysql`, `php-intl`, `php-mbstring` and `mariadb-server`.
   - The application, with its production Composer dependencies, is in `/opt/lpanel`, owned by root. Servers never run Composer.
   - `postinst` runs `lpanel-setup`. That script makes, once each:
     - the users `lpanel` and `lpanel-web`;
     - the two certificates;
     - `APP_KEY`, the database passwords and the operation-signing key;
     - the database `lpanel` with the accounts `lpanel_app` (DML) and `lpanel_migrate` (DDL).

     On every run it renders the Nginx and PHP-FPM configuration, migrates as `lpanel_migrate`, and warms the caches.

## Layout

| Path | What | Owner, mode |
|---|---|---|
| `/opt/lpanel` | the application (read-only to the panel) | root, 0755/0644 |
| `/opt/lpanel/system` → `/var/lib/lpanel` | sessions, caches, logs, locks | `lpanel`, 0750 |
| `/opt/lpanel/.env` → `/etc/lpanel/lpanel.env` | `APP_KEY`, `lpanel_app`'s password, settings | root:lpanel, 0640 |
| `/etc/lpanel/migrate.env` | `lpanel_migrate`'s password | root, 0600 |
| `/etc/lpanel/keys/operations.key` | operation-signing key | root:lpanel, 0640 |
| `/etc/lpanel/tls/{panel,agent}.{crt,key}` | the two certificates | keys root 0600 |
| `/etc/lpanel/lpanel.conf` | host name and ports, the administrator's | root, 0644 |
| `/etc/lpanel/nginx/nginx.conf`, `/etc/lpanel/php-fpm.conf` | rendered by `lpanel-setup`; not edited by hand | root, 0644 |
| `/var/log/lpanel/{nginx,php}` | logs; rotated by `/etc/logrotate.d/lpanel` | |
| `/var/cache/lpanel-nginx` | panel Nginx's temp paths, not the hosting Nginx's | `lpanel-web`, 0700 |

Units: `lpanel-nginx.service`, `lpanel-php-fpm.service`, `lpanel-scheduler.timer` (`schedule:run` every minute), and `lpanel-authd.socket` with `lpanel-authd.service`. There is no queue worker: the queue is `sync` until something needs deferring.

Upgrade order (ARCHITECTURE.md §6):
1. `prerm` stops the scheduler and runs `lpanel down`.
2. The files are replaced.
3. `lpanel-setup` runs `cache:clear`, then `migrate` as `lpanel_migrate`, then `cache:warm` and `up`. The cached configuration holds `lpanel_app`'s credentials, so it is cleared first. The migration account is given only to that one command, in its environment.
4. PHP-FPM is restarted and Nginx reloaded.

`remove` keeps `/etc/lpanel` and `/var/lib/lpanel`. `purge` deletes them, which unpairs every agent. Both keep the database, which is data, and print how to drop it.

## Consequences

- Hosting Nginx and PHP-FPM can be broken, reloaded or stopped without affecting the panel. `test/packages/run.sh` checks this on all four OSes, and checks that nothing under `/etc/nginx`, `/etc/php` or `/etc/mysql` changes.
- The browser certificate can be replaced freely (MVP_SCOPE Q1, option a). The agent key cannot: losing it means pairing every server again, so it belongs in backups (M11).
- Two more ports are open by default. A firewall needs 8443 open to administrators and 8444 open to managed servers only.
- Two PHP-FPM masters cost about 20 MB of RAM. Accepted.
