# LPanel design

LPanel is a web hosting control panel for hosting companies. It is installed
on each server and has four tiers: root admin, resellers, end users and
sub-users. Admins and resellers work in the **Admin panel**. Account owners and
sub-users work in the **User panel**.

| Document | What it covers |
|---|---|
| [FEATURES.md](FEATURES.md) | Every feature, who gets it, and in which release (v1 / v1.x / v2) |
| [ROADMAP.md](ROADMAP.md) | Milestones M0–M11 for v1, with exit criteria |
| [DEVELOPMENT.md](DEVELOPMENT.md) | Running it locally, development logins, module rules, checks, gotchas |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Panel, agent and authd; rules; server layout |
| [THREAT_MODEL.md](THREAT_MODEL.md) | What is attacked, by whom, the defence and the test for each, known gaps |
| [adr/](adr/) | Architecture decision records |

## Decisions

| ADR | Decision |
|---|---|
| [0001](adr/0001-single-server-install.md) | One install manages one server |
| [0002](adr/0002-php-panel-go-agent.md) | Panel in PHP (LPHP), agent in Go |
| [0003](adr/0003-agent-over-unix-socket.md) | Panel ↔ agent over a local Unix socket, signed requests |
| [0004](adr/0004-root-login-via-pam.md) | Root logs in with the real root password (PAM) |
| [0005](adr/0005-almalinux-apache-first.md) | AlmaLinux 9 + Apache first; OS matrix |
| [0006](adr/0006-exim-dovecot.md) | Exim + Dovecot, Rspamd + ClamAV |
| [0007](adr/0007-powerdns.md) | PowerDNS with database backend |
| [0008](adr/0008-filesystem-quotas.md) | Kernel filesystem quotas |
| [0009](adr/0009-commercial-license.md) | Commercial per-server license |
| [0010](adr/0010-panel-own-web-stack.md) | The panel runs its own nginx and PHP-FPM |
