# MVP scope and milestone order

Two targets:

- **MVP** — an internal milestone: a customer gets a working PHP website over HTTPS on an Nginx server that LPanel manages.
- **Public beta** — plan §15's acceptance list.

Each milestone ends with:

- `composer check` and the LPA tests;
- the threat-model tests that milestone names ([THREAT_MODEL.md §4](THREAT_MODEL.md#4-tests-this-model-requires-release-blocking-ones-in-bold));
- updated docs;
- a short completion report.

Work stops at a failed security or isolation test (plan §14).

## Fixed for the first release

| Item | Choice | Source |
|---|---|---|
| OS (panel and managed servers) | Ubuntu 24.04 + 26.04, Debian 12 + 13, amd64 | [ADR-0004](adr/0004-os-matrix.md) |
| Agent | Go; `lpa-agent` + `lpa-exec` | [ADR-0001](adr/0001-lpa-in-go.md), [ADR-0009](adr/0009-lpa-privilege-split.md) |
| Transport | agent pulls; mTLS + Ed25519 | [ADR-0002](adr/0002-agent-pull-transport.md) |
| Panel web server | separate `lpanel-nginx` instance: people on `:8443`, agents on `:8444`; own PHP-FPM master | [ADR-0003](adr/0003-separate-panel-nginx-instance.md), [ADR-0012](adr/0012-panel-package-php-fpm-and-agent-port.md) |
| Panel DB | distro MariaDB (10.11 or 11.8) | [ADR-0008](adr/0008-panel-database-mariadb.md) |
| Customer web backend | Nginx in MVP; Apache before beta | [ADR-0007](adr/0007-nginx-first-apache-second.md) |
| Customer PHP | the distro default only (8.3 / 8.2) | ADR-0004 |
| Customer DB | distro MariaDB (10.11 or 11.8) | plan §3.3 |
| Certificates | Let's Encrypt via ACME HTTP-01, served by the hosting backend | plan §3.3 |

## Milestones

| # | Milestone | Repo | Delivers | Exit criteria (beyond the standard ones) |
|---|---|---|---|---|
| M0 | **Design lock** (this folder) | lpanel | discovery, architecture, protocol, threat model, data model, ADRs | product owner accepts or edits ADR-0005…0009 and answers the open questions below |
| M1 | **LPA read-only prototype** | lpa | Go skeleton; config; protocol types + golden vectors; operation registry; the five read-only ops; local journal; systemd units; one `.deb` tested on all four OSes | tests reject bad, replayed and expired requests; no generic exec exists; install, upgrade, remove and restart pass in `ubuntu:24.04`, `ubuntu:26.04`, `debian:12`, `debian:13` |
| M2a | **LPanel foundation** (done 2026-10-27) | lpanel | Audit module; Identity: users, code-declared roles, root login via `lpanel-authd` + PAM (ADR-0010), panel-password login, optional TOTP, login/code/security pages, session and users API; Dashboard; vendored Bootstrap/jQuery layout; `config/system.php` disabled | CSRF, session-rotation, privilege-escalation and suspension tests; authd tested against real PAM; `composer check` green |
| M2b | **Identity, continued** (done 2026-10-27) | lpanel | password reset by email; API tokens (`TokenProvider`, bearer-only `/api/token/v1`, ADR-0011); session epochs (a new password or "sign out everywhere" ends other sessions); user-management, audit-log, tokens and security screens on a shared panel shell (`Shared\Ui`, sidebar filled by each module); MariaDB test run (10.11 + 11.8, `test/mariadb/run.sh`) | 60 tests on SQLite and on MariaDB 10.11/11.8; PHPStan level 8; coding standard; screens checked in light, dark and at 390 px |
| M3 | **Enrollment + inventory** (done 2026-10-27) | both | Servers module: `/agent/v1/enroll`, heartbeat, claim (empty until M4) and `keys/rotate`, every request checked in §3 order; key rotation (asked by the panel, or after 90 days; staged and crash-safe on the agent) and revocation; server list, pairing and detail screens, dashboard tally, `/api/v1/servers`; `servers:keygen`, `servers:add`, `servers:list`, `servers:rotate`, `servers:revoke`, `servers:sweep` | THREAT_MODEL tests 2 and 7 as PHPUnit tests; PHP and Go checked against the same golden vectors (incl. rotation proof); `test/e2e/run.sh`: real `lpa` against panel Nginx (mTLS) + PHP-FPM in Docker, 16 checks; Go `-race`, root and non-root |
| M4 | **Operations** (done 2026-10-27) | both | Operations module: catalogue mirroring the agent registry, `operations` + `operation_events`, `/agent/v1/operations/claim`, `/{id}/lease`, `/{id}/result`; `operations:sweep` (lease lapse → retry / failed / needs_attention, expiry); cancel (queued at once, running via the lease answer); revocation cancels queued work; screens (list, detail with history, live resources and services on the server page, dashboard tally), `/api/v1/operations`; `operations:run`, `operations:list`, `operations:show`; agent: `cancelled` code, lease timing scaled in tests, catalogue golden file | lease expiry, duplicate delivery, offline agent and protocol mismatch as PHPUnit tests; PHP catalogue checked against the agent's (definitions and real results); `test/e2e/run.sh` 27 checks incl. every operation on the real agent; Go `-race`, root and non-root |
| M5 | **Panel packaging** (done 2026-10-27) | lpanel | the `lpanel` `.deb` (ADR-0012): `lpanel-setup` (users, `/opt/lpanel`, certificates, secrets, MariaDB database and accounts, operation key, migrate, caches), `lpanel-nginx` (8443 people, 8444 agents), `lpanel-php-fpm` (own master), `lpanel-scheduler.timer`, `lpanel-authd`, logrotate; the `lpanel` console wrapper | `test/packages/run.sh`: install, use, upgrade, remove, reinstall, purge on all four OSes; root signs in through PAM; a real agent pairs over 8444 and runs an operation, before and after the upgrade; a broken or stopped hosting Nginx/PHP-FPM leaves the panel serving; nothing under `/etc/nginx`, `/etc/php`, `/etc/mysql` changes |
| M6a | **Hosting accounts** (done 2026-10-28) | both | `lpa-exec` (ADR-0009, ADR-0013): root, socket-activated, `SO_PEERCRED`, verifies the panel's signature itself, each idempotency key once; `account.create/suspend/unsuspend/delete` (uid 20000–59999, root-owned home, per-account PHP-FPM pool, lpa-exec's ownership record); Hosting module: `hosting_accounts`, settled by `operations.finished`, screens (list, create, detail with history), customer view, `/api/v1/accounts`, `accounts:*` commands | THREAT_MODEL tests 1 and 5; lpa `test/packages/run.sh` 78 checks × 4 OSes; panel `test/packages/run.sh` makes, suspends and deletes a real account through panel → agent → lpa-exec, panel config byte-identical |
| M6b | **Nginx websites** (done 2026-10-28) | both | `lpa` `internal/websites` ([ADR-0014](adr/0014-websites-through-the-hosting-nginx.md)): render from checked settings, stage and `nginx -t` in the server's real context, atomic swap, reload, probe, verified rollback; hand edits reported, reloaded if `nginx -t` passes, never overwritten without an administrator; `website.create/update/suspend/unsuspend/delete`, `websites.check`; account suspension answers 503. Websites module: screens with the settings editor (customers change their own redirects, headers, index files, PHP), `/api/v1/websites`, `websites:*`, hourly check | THREAT_MODEL tests 1 (websites) and 6; lpa `test/packages/run.sh` 118 checks × 4 OSes with the real Nginx; panel `test/packages/run.sh` serves a real website through panel → agent → lpa-exec, panel config byte-identical |
| M7 | **TLS for websites** (done 2026-10-28) | both | [ADR-0015](adr/0015-website-certificates-with-acme-http-01.md): `lpa-exec` makes the key, orders over ACME HTTP-01 (`golang.org/x/crypto/acme`), answers the checks from a root-owned directory through the hosting Nginx, and serves the certificate through ADR-0014's checked change; `website.certificate.issue/remove`; HTTP→HTTPS redirect a website setting, on by default. Panel: `website_certificates`, automatic first certificates and renewals (`certificates:renew` every five minutes, 1 h / 6 h / daily backoff, a first one given up after a week), owners secure their own websites, HTTPS card and list column, `websites:certificate` | issued by **Pebble** (Let's Encrypt's test server) in both package tests on all four OSes; staging needs a public name, so it is a manual check (ADR-0015) |
| — | **= MVP** | | | plan §11 E2E scenarios 1–8 |
| M8 | Apache adapter | lpa | same contract suite as Nginx | contract suite green; backend migration designed |
| M9 | Databases | both | MariaDB DB/user lifecycle; sealed secrets | THREAT_MODEL test 9; grants verified |
| M10 | Files + SFTP + cron | both | scoped file manager; chrooted SFTP; customer crontab | symlink and traversal tests |
| M11 | Backups (local) + restore | both | files + DB dumps; retention; verified restore into an isolated target | restore test (plan §15) |
| M12 | Monitoring + service actions | both | metrics history; alerts; allowlisted restarts with confirmation | |
| M13 | Hardening + release | both | full threat-model pass; compatibility matrix; docs | plan §15 checklist |
| — | **= Public beta** | | | |

## Out of scope for MVP and beta

These are deferred unless the scope is changed:

- **Mail.** It needs its own design: stack, abuse handling and deliverability (plan §6, Phase 6).
- **DNS hosting.** See the open question below.
- **OpenLiteSpeed.**
- **Multiple PHP versions per server.**
- **Off-server backups.**
- **Firewall management.**
- **Unattended upgrades.**
- **Plan §16:** billing, resellers, containers, Windows and plugins.

## Open questions

These are for the product owner. An answer is needed by the milestone shown.

| # | Question | Needed by | Recommendation |
|---|---|---|---|
| Q1 | Panel TLS on `:8443`. ACME HTTP-01 needs `:80`, which the hosting backend owns. Options: (a) the admin supplies a cert; (b) ACME DNS-01; (c) LPA adds a managed `/.well-known/acme-challenge/` rule for the panel hostname to the hosting backend. | M5 | **Decided (ADR-0012):** (a) for MVP. Agents use their own listener (`:8444`) and key, so the browser certificate can be replaced freely; (b) later |
| Q2 | DNS: which backend, if any, before beta? PowerDNS (API, SQL backend) or BIND (zone files)? Or "external DNS only, we show the records to create"? | M9 | external-only for beta; PowerDNS after |
| Q3 | Customer SFTP model: OpenSSH `ChrootDirectory` per account (needs a root-owned chroot path) or `internal-sftp` with a home chroot? | M10 | `internal-sftp` + `ChrootDirectory /home/%u` with root-owned home and a writable `site/` dir |
| Q4 | Signing key protection ([THREAT_MODEL O-1](THREAT_MODEL.md#5-open-items-for-the-product-owner)) | M13 | a local signing helper after MVP |
| Q5 | `hidepid` on `/proc` (THREAT_MODEL O-2) | M6 | **Decided:** later, in M13 hardening (2026-10-28) |
| Q6 | Audit retention (THREAT_MODEL O-3) | M2 | **Decided:** 1 year (`Audit.retention_days`) |
| Q7 | Licence and repository visibility for `lpa` and `lpanel` | M1 | — |
| Q8 | Ask LPHP for `STABILITY.md` + `framework.json` for 3.1.3 ([LPHP_DISCOVERY §1](LPHP_DISCOVERY.md#1-which-lphp-this-is)) | M2 | — |
