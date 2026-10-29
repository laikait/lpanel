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
- New `design/` set: README, ARCHITECTURE, THREAT_MODEL, and ADRs 0001–0009.
- Agent: `lpa` runs as root and listens on a Unix socket. It checks the
  caller's identity (SO_PEERCRED) and the signature of every request. It runs
  each operation exactly once per idempotency key and keeps a journal.
- `lpanel-authd` checks the root password with PAM.
- Panel: identity, sessions, 2FA, roles and the ACL engine with tiers A/R/U/S,
  "log in as", and the audit log.
- UI shell with the three skins (WHM, reseller, cPanel), i18n plumbing with
  RTL, and dark mode.
- Installer skeleton: on a fresh AlmaLinux 9 box, one command installs the
  panel's PHP-FPM, MariaDB and nginx for the panel itself (port 2087/2083
  style), plus the agent and authd.

**Exit:** on a fresh AlmaLinux 9 VM the installer runs, root logs in with the
real password, sets up 2FA, sees the dashboard, and the dashboard shows
live data read from the agent.

### M1: Accounts, packages, resellers
- Packages and feature lists.
- Accounts: create, modify, suspend, unsuspend, terminate. Each action creates
  or changes the system user, home folder, filesystem quota and shell.
- Resellers with ACLs, quotas and overselling. Sub-users.
- Bandwidth metering (log pipeline).

**Exit:** a reseller creates an account from a package. The disk quota is
enforced by the kernel. Suspend and terminate work and appear in the audit
log.

### M2: Web
- Apache + PHP-FPM with one pool per account, and multi-PHP from Remi.
- All domain types, redirects, error pages, directory privacy, hotlink
  protection.
- The staged config pipeline with rollback.
- Then Nginx-only and the Nginx-proxy + Apache hybrid.
- php.ini editor and extension manager.

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
1. cPanel `cpmove` import, then Plesk, DirectAdmin and CyberPanel.
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
