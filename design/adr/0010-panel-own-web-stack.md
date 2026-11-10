# ADR-0010: The panel runs its own nginx and PHP-FPM

- Status: Accepted (2026-11-10)

## Context
The panel must stay reachable when a customer's site configuration is broken,
when customers' PHP versions are upgraded or removed, and when the web server
for customers' sites (Apache, nginx, or both, ADR-0005) is restarted. The panel
also needs PHP's sodium extension to sign agent requests (ADR-0003). EL9's
AppStream PHP does not ship sodium.

## Decision
- **Its own PHP.** The panel runs on Remi's `php83`, installed under
  `/opt/remi/php83`. A dedicated master, `lpanel-php-fpm.service` with
  `/etc/lpanel/php-fpm.conf`, runs one pool as the `lpanel` user on
  `/run/lpanel/php-fpm.sock`. The package's own `php83-php-fpm` service stays
  off, so customers' pools never share a master with the panel.
- **Its own nginx.** `lpanel-nginx.service` runs nginx with
  `/etc/lpanel/nginx/nginx.conf` on 7443 (Admin panel) and 7444 (User panel).
  - Every request goes to the front controller, `public/index.php`, so nginx
    needs no access to the code.
  - Each port refuses the other interface's paths.
  - The package's own `nginx.service` stays off. M2 configures the web server
    customers' sites use separately.
- **Ownership.**
  - The code is root's and read-only to `lpanel`. Only `system/` is the
    panel's to write.
  - `.env` is 0640 root:lpanel.

## Consequences
- Restarting or breaking the customers' web server or PHP never affects the
  panel, and the reverse holds too.
- Two more units to supervise. The service watchdog (M10) treats them like any
  other.
- The certificate is self-signed until M3 can issue one for the panel's
  hostname.
- SELinux in enforcing mode: the installer sets port and file labels, but the
  policy for these custom units is not yet verified on a VM. See
  THREAT_MODEL.md, "Known gaps".
