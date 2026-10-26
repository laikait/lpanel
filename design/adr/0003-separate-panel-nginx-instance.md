# ADR-0003: The panel runs on its own Nginx instance

- **Status:** Accepted (product owner, 2026-10-26)
- **Context:** Plan §3.4 and §18 require that hosting operations never reload or break the panel. With one Nginx master, `nginx -t` and `reload` always cover every included file. A broken customer file would block panel changes, and every hosting reload would also reload the panel.

## Decision

The panel is served by a second Nginx master process, using the same distro `nginx` binary with its own configuration tree:

| Item | Panel instance | Customer hosting instance |
|---|---|---|
| systemd unit | `lpanel-nginx.service` (shipped by the LPanel package) | distro `nginx.service` (or `apache2.service`) |
| main config | `/etc/lpanel/nginx/nginx.conf` | `/etc/nginx/nginx.conf` |
| pid | `/run/lpanel-nginx.pid` | `/run/nginx.pid` |
| logs | `/var/log/lpanel/nginx/` | `/var/log/nginx/` |
| listener | `0.0.0.0:8443` by default (configurable IP/port) | `:80`, `:443` |
| PHP | `lpanel` PHP-FPM pool, own socket | per-account pools |
| validate | `nginx -t -c /etc/lpanel/nginx/nginx.conf` | `nginx -t` (default config) |

**Exact paths are proposals.** Verify them on Ubuntu 24.04 and Debian 12 in the LPA/LPanel packaging milestone. In particular:

- whether the distro `nginx.service` drop-ins or AppArmor profiles get in the way;
- whether `logrotate`'s nginx rule sends `USR1` to the right pid.

## Consequences

- LPA's hosting Nginx adapter only knows the hosting instance's paths and unit. It has no operation that names `lpanel-nginx`. Panel lifecycle is a separate, admin-only operation group (out of MVP).
- Before installing either instance, preflight checks for conflicting listeners on the chosen IP/port. Nothing is overwritten silently.
- The panel is on a non-standard port by default. ACME HTTP-01 for the panel hostname needs port 80, which the hosting backend owns. See the open question in [MVP_SCOPE.md](../MVP_SCOPE.md#open-questions).
- Two Nginx masters cost a few MB of RAM. Accepted.
