# LPanel features

LPanel is a control panel for hosting companies that sell shared hosting. It is
installed on each server and manages that one machine, the way WHM/cPanel does.

This file lists every feature the product will have and when it ships. The
order of work is in [ROADMAP.md](ROADMAP.md).

## How to read the tables

**Release**

| Tag | Meaning |
|---|---|
| **v1** | Needed for the first release we can sell. |
| **v1.x** | Fast follow after v1: migration, more OSes, the most-requested extras. |
| **v2** | Later. It is designed for, but not scheduled. |

**Who** (which tier sees the feature)

| Letter | Tier | Equivalent |
|---|---|---|
| **A** | Root admin | WHM root |
| **R** | Reseller | WHM reseller |
| **U** | End user, the account owner | cPanel user |
| **S** | Sub-user, when the owner grants it | cPanel "user manager" |

A reseller can only do what their ACL allows. A sub-user can only do what the
account owner granted. The root admin can do everything.

## 1. Users, tiers and access

| Feature | Release | Who | Notes |
|---|---|---|---|
| Root login with the server's real root password (PAM via `lpanel-authd`) | v1 | A | Like WHM. Nothing stores the password. |
| Panel admin users with roles (no system root) | v1 | A | For staff who must not hold the root password. |
| Two-factor auth (TOTP + recovery codes) for every tier | v1 | A R U S | Admin can make it mandatory for each tier. |
| Login protection: rate limit, lockout, login history | v1 | A R U S | Feeds the brute-force blocker (section 10). |
| Reseller accounts | v1 | A | Admin creates resellers and sets quotas and ACLs. |
| Reseller ACLs (create/suspend/terminate accounts, edit DNS, use packages, shell access…) | v1 | A | ACLs are named lists of permissions, so they can be reused. |
| Reseller overselling | v1 | A | Per reseller, separately for disk, bandwidth and account count. |
| Sub-users with scoped permissions (files, email, DBs, DNS, SSL, backups…) | v1 | U | Each sub-user has their own login, password and 2FA. |
| "Log in as" an account (admin → reseller → user) | v1 | A R | Audited, and a banner shows on every page while it lasts. |
| API tokens with scopes, expiry and IP allow-list | v1 | A R U | See section 14. |
| Session list and "sign out everywhere" | v1 | A R U S | |
| Audit log of every change (who, what, from where), kept 1 year | v1 | A R U | Users see their own entries. Admin sees all. |

## 2. Accounts and packages

| Feature | Release | Who | Notes |
|---|---|---|---|
| Create account (system user, home, main domain, PHP pool, DNS zone, mail domain) | v1 | A R | One step. If any step fails, the whole create rolls back. |
| Suspend / unsuspend (with reason, and an optional suspend page) | v1 | A R | Stops web, mail, FTP, SSH, cron and logins. |
| Terminate (typed confirmation; optional backup first) | v1 | A R | |
| Modify account (package, owner reseller, main domain, IP, shell) | v1 | A R | |
| Change account username | v1.x | A | Hard to do: renames the system user, DB prefixes and paths. |
| Packages/plans: disk, bandwidth, inodes, addon/parked/subdomains, mailboxes, mail lists, DBs, FTP users, cron jobs, max hourly emails, CPU/RAM/IO/processes | v1 | A R | CPU/RAM/IO/process limits are enforced from v1.x (section 11). |
| Feature lists (switch whole cPanel features on or off per package) | v1 | A R | E.g. no SSH, no Git, no Node.js. |
| Disk quotas enforced by the kernel (ext4/XFS user quotas) | v1 | A | Usage refreshed live. Warning email at 80/90/100%. |
| Bandwidth metering per account from web, mail and FTP logs, with an over-limit action | v1 | A R U | The action is "warn" or "suspend", set per package. |
| Dedicated IPs and IP pool (IPv4 + IPv6) | v1 | A | Shared IP by default. Dedicated IPs assigned per account. |
| List and search accounts, with bulk actions (suspend, change package, …) | v1 | A R | |
| Account welcome email (template per reseller) | v1 | A R | |
| Account list CSV export | v1 | A R | |

## 3. Websites and domains

| Feature | Release | Who | Notes |
|---|---|---|---|
| Apache 2.4 + PHP-FPM (event MPM) | v1 | A | The first web server built. Has `.htaccess` support. |
| Nginx + PHP-FPM | v1 | A | Admin picks one web stack per server. |
| Nginx in front (static files, TLS, HTTP/2/3) + Apache behind it (`.htaccess`) | v1 | A | The hybrid mode Plesk and cPanel offer. |
| OpenLiteSpeed / LiteSpeed Enterprise | v2 | A | |
| Main domain, addon domains, parked/alias domains, subdomains, wildcard subdomains | v1 | A R U S | Each one is bounded by the package limits. |
| Choose each domain's document root | v1 | U S | It must stay inside the account's home folder. |
| Redirects (301/302, with or without www, wildcard) | v1 | U S | |
| Force HTTPS for each domain | v1 | U S | On by default once a certificate exists. |
| Custom error pages (400/401/403/404/500/503) | v1 | U S | |
| Directory privacy (HTTP basic auth) | v1 | U S | |
| Hotlink protection | v1 | U S | |
| IP blocker for each account | v1 | U S | |
| MIME types and handlers | v1.x | U | |
| Safe `.htaccess` subset when Nginx is in front | v1 | A | Admin can configure which directives are allowed. |
| Hand-edited vhost detection: report, don't overwrite, admin "restore" | v1 | A | Protects edits made by staff at the shell. |
| Staged config pipeline: render → syntax test → swap → reload → probe → roll back | v1 | A | Every web config change goes through it. |
| Website preview URL before DNS points to the server | v1.x | U | |

## 4. PHP and application runtimes

| Feature | Release | Who | Notes |
|---|---|---|---|
| Multi-PHP: several versions side by side (Remi on EL, Sury on Debian/Ubuntu) | v1 | A | Admin installs and removes versions from the panel. |
| PHP version for each domain | v1 | U S | Limited to the versions the admin enabled. |
| One PHP-FPM pool per account, running as the account user | v1 | A | Pool limits come from the package. |
| php.ini editor (basic form and advanced raw edit), per domain | v1 | U | Allowed directives are whitelisted. |
| PHP extension manager per version | v1 | A | |
| `open_basedir` / `disable_functions` defaults per package | v1 | A | |
| Node.js app manager (version, start command, env, process manager, reverse proxy) | v2 | U | |
| Python app manager (WSGI/ASGI, virtualenv, reverse proxy) | v2 | U | |
| Static site deploy | v2 | U | |
| Docker containers per account | v2 | A U | Behind a feature flag in the package. |

## 5. SSL/TLS

| Feature | Release | Who | Notes |
|---|---|---|---|
| AutoSSL with Let's Encrypt (HTTP-01), auto-renew, backoff on failure | v1 | A U | On for every domain by default. |
| ZeroSSL as an alternative/fallback CA | v1 | A | |
| Wildcard certificates via DNS-01 through the local PowerDNS | v1 | U | |
| Upload or paste a commercial certificate; generate a key + CSR | v1 | U S | |
| Certificate list with expiry and issuer; "issue now" / "remove" | v1 | A R U S | |
| Hostname certificate for the panel itself | v1 | A | |
| Service certificates for Exim, Dovecot and Pure-FTPd, with SNI per mail domain | v1 | A | So `mail.customer.com` gets a valid certificate. |
| Expiry alerts | v1 | A U | Sent through section 13. |

## 6. Databases

| Feature | Release | Who | Notes |
|---|---|---|---|
| MariaDB (or MySQL) databases and users, named with the account-user prefix | v1 | U S | |
| Grant privileges per user per database | v1 | U S | |
| Change DB user password; rename DB | v1 | U S | |
| phpMyAdmin with single sign-on from the panel | v1 | U S | No second password. |
| Remote access allow-list (hosts that may connect remotely) | v1 | U | Opens the firewall only for those hosts. |
| DB size counted in the disk quota | v1 | A | |
| Admin: MariaDB service config, root password reset, process list | v1 | A | |
| PostgreSQL databases and users, Adminer SSO | v2 | U | |
| Redis / Memcached instance per account | v2 | U | |

## 7. DNS

| Feature | Release | Who | Notes |
|---|---|---|---|
| PowerDNS (database backend) as the local authoritative DNS server | v1 | A | |
| Zone created automatically for every domain | v1 | A R U | |
| Zone editor: A, AAAA, CNAME, MX, TXT, SRV, CAA, NS, PTR (admin) | v1 | U S | Syntax checks before saving. |
| Zone templates (default records for new zones) | v1 | A R | |
| Reset a zone to its template | v1 | U | |
| Admin's and resellers' own nameservers (`ns1.reseller.com`), with glue instructions | v1 | A R | |
| DNSSEC sign/unsign per zone | v1.x | U | |
| Cloudflare DNS API (push zones/records instead of hosting locally) | v1.x | A U | |
| Route53, DigitalOcean, Hetzner DNS APIs | v2 | A U | |
| DNS cluster (sync zones to separate nameserver machines) | v2 | A | |

## 8. Email

| Feature | Release | Who | Notes |
|---|---|---|---|
| Exim (MTA) + Dovecot (IMAP/POP3) | v1 | A | Same MTA as cPanel, which makes cpmove import easier. |
| Mailboxes with quota; change password | v1 | U S | |
| Forwarders (address → address/pipe/discard) and domain forwarders | v1 | U S | |
| Autoresponders with schedule | v1 | U S | |
| Default address / catch-all | v1 | U S | |
| Mailing lists | v2 | U | |
| Spam filtering with Rspamd, scores and actions per user | v1 | A U S | |
| Virus scanning with ClamAV | v1 | A | |
| Email filters (Sieve) per mailbox and per account | v1 | U S | |
| DKIM keys, SPF and DMARC records created automatically | v1 | A U | Written into the local zone. Shown as "fix this" when DNS is elsewhere. |
| Email deliverability check page (SPF/DKIM/DMARC/PTR/blacklists) | v1 | A U | |
| Outbound limit per account (emails per hour) | v1 | A | |
| Mail queue manager (search, delete, retry, view) | v1 | A | |
| Email track delivery (log search) | v1 | A U | |
| Roundcube webmail with SSO | v1 | U S | |
| SnappyMail as an alternative webmail | v1.x | A | |
| Autodiscover (Outlook) and autoconfig (Thunderbird) | v1 | A | |
| Mail client setup page with ready-made settings | v1 | U S | |

## 9. Files and access

| Feature | Release | Who | Notes |
|---|---|---|---|
| Web file manager: browse, upload (chunked, drag-and-drop), download, rename, move, copy, delete | v1 | U S | |
| Code editor with syntax highlighting | v1 | U S | |
| Compress/extract zip, tar.gz | v1 | U S | |
| Permissions editor (chmod) | v1 | U S | Ownership is always fixed to the account user. |
| Trash with restore | v1.x | U S | |
| FTP/FTPS with Pure-FTPd; FTP users limited to a folder | v1 | U S | |
| SFTP and jailed SSH (chroot, no other users visible) | v1 | A U | Shell access is a package feature. |
| SSH key manager (add, generate, authorize) | v1 | U | |
| Web terminal | v2 | U | |
| Git repositories: clone, pull, deploy on push, webhooks | v1.x | U S | |
| Disk usage explorer (which folders are large) | v1 | U S | |

## 10. Security

| Feature | Release | Who | Notes |
|---|---|---|---|
| Firewall manager (firewalld on EL, nftables on Debian/Ubuntu): ports, services, rules | v1 | A | |
| IP allow/block lists, including country blocking | v1 | A | |
| Brute-force blocking for panel, SSH, FTP, mail (Fail2ban-style, built in) | v1 | A | Admin sees blocked IPs and can unblock them. |
| ModSecurity v3 + OWASP Core Rule Set, on/off per domain, rule exclusions | v1 | A U | |
| Malware scanner (ClamAV + signature rules) with quarantine and reports | v1.x | A U | |
| Account isolation: per-user PHP-FPM, `hidepid=2`, private `/tmp`, home folders 0711 | v1 | A | |
| CageFS-like filesystem jail without CloudLinux | v2 | A | |
| CloudLinux LVE/CageFS integration | v2 | A | |
| Symlink protection (`SymLinksIfOwnerMatch`, protected hardlinks) | v1 | A | |
| Security advisor page (checks the server and suggests fixes) | v1.x | A | |

## 11. Resource limits

| Feature | Release | Who | Notes |
|---|---|---|---|
| CPU, RAM, IO and process limits per account through cgroups v2 (systemd slices) | v1.x | A | Values come from the package. |
| Resource usage graphs per account | v1.x | A R U | |
| Number of PHP processes per account (FPM `pm.max_children`) | v1 | A | |
| Inode limit | v1 | A | Through filesystem quotas. |

## 12. Backups and migration

| Feature | Release | Who | Notes |
|---|---|---|---|
| Scheduled backups: full + incremental (restic), retention (daily/weekly/monthly) | v1 | A | |
| Destinations: local disk, S3, Backblaze B2, Wasabi, SFTP | v1 | A | |
| Destinations: Google Drive, Dropbox | v1.x | A | |
| User-made backups (download a full account archive) | v1 | U | |
| Self-service restore: single file/folder, database, mailbox, full account | v1 | U S | |
| Admin restore of any account, including terminated ones | v1 | A | |
| Backups encrypted at rest | v1 | A | Restic repository key held by the agent. |
| Import a cPanel backup (`cpmove-*.tar.gz`): files, DBs, mail, DNS, SSL, cron | v1.x | A R | Highest-priority migration source. |
| Import from Plesk, DirectAdmin, CyberPanel | v1.x | A | |
| Server-to-server account transfer between two LPanel servers | v2 | A | |

## 13. Monitoring, stats and alerts

| Feature | Release | Who | Notes |
|---|---|---|---|
| Server dashboard: CPU, RAM, swap, disk, load, network, uptime | v1 | A | |
| Service status with start/stop/restart and automatic restart (watchdog) | v1 | A | |
| Per-account usage: disk, bandwidth, inodes, mailboxes, DBs | v1 | A R U | |
| Visitor statistics with GoAccess | v1 | U S | |
| AWStats | v1.x | U | |
| Raw access and error logs: view and download | v1 | U S | |
| Alerts: disk full, service down, high load, SSL expiry, quota reached, backup failed | v1 | A U | |
| Alert channels: email, Telegram, Slack, webhook | v1 | A U | |

## 14. Server administration (WHM side)

| Feature | Release | Who | Notes |
|---|---|---|---|
| Service manager: enable/disable services, edit main configs safely (test before applying) | v1 | A | |
| Basic setup wizard (hostname, IPs, nameservers, contact email, first SSL) | v1 | A | |
| OS package updates (view, apply, schedule) | v1 | A | |
| Panel self-update with release channels (stable/beta) and changelog | v1 | A | |
| PHP version installer | v1 | A | |
| Cron jobs per user (editor with presets) | v1 | U S | |
| Admin view of all cron jobs | v1 | A | |
| Background task viewer (queued/running/failed operations, retry) | v1 | A | |
| Hostname, timezone, NTP, swap settings | v1 | A | |
| Terminal-free emergency CLI (`lpanel` command on the server) | v1 | A | |

## 15. Apps and performance

| Feature | Release | Who | Notes |
|---|---|---|---|
| WordPress toolkit: install, list, clone, staging, push to live, updates, hardening, wp-cli | v2 | U | |
| App installer catalog (Joomla, Drupal, Laravel, Magento, Nextcloud…) | v2 | U | |
| Redis object cache for WordPress | v2 | U | |
| Nginx FastCGI cache, Varnish, LSCache | v2 | A U | |
| CDN integration: Cloudflare, BunnyCDN, with cache purge | v2 | U | |

## 16. Platform

| Feature | Release | Who | Notes |
|---|---|---|---|
| REST API covering every panel action (OpenAPI document) | v1 | A R U | The UI calls the same API. |
| `lpanel` CLI on the server (all admin actions, JSON output) | v1 | A | |
| Webhooks for events (account created, suspended, backup done…) | v1 | A R | Billing systems integrate through these and the API. |
| Three skins: WHM (admin), reseller, cPanel (user) | v1 | A R U S | |
| White-label: logo, colors, panel name, favicon, per reseller | v1 | A R | |
| Panel served on a custom domain per reseller | v1.x | R | |
| Multi-language with RTL support (English, Bangla, Arabic first) | v1 | A R U S | |
| Dark/light themes, fully responsive down to phone width | v1 | A R U S | |
| Commercial license: per-server key, online activation, grace period, license server | v1 | A | |
| Installer: one command on a fresh AlmaLinux 9 server | v1 | A | |
| Plugin system: modules that add UI pages, API endpoints and agent operations | v2 | A | |
| WHMCS / Blesta provisioning module | v2 | A | Not planned now. The API and webhooks cover integration. |
| Built-in billing | — | | Out of scope. |

## 17. Operating systems

| OS | Release |
|---|---|
| AlmaLinux 9 / Rocky Linux 9 | v1 (development and test target) |
| AlmaLinux / Rocky 8 and 10 | v1 |
| Ubuntu 22.04 / 24.04 / 26.04 LTS | v1.x |
| Debian 12 / 13 | v1.x |
| CloudLinux 8/9 (with LVE/CageFS integration) | v2 |
