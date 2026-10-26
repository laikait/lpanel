# LPanel design

Phase 0 documents for LPanel (this repository) and LPA (the agent, in its own
repository). Read them in this order:

1. [LPHP_DISCOVERY.md](LPHP_DISCOVERY.md) — what the framework really provides, with evidence.
2. [MVP_SCOPE.md](MVP_SCOPE.md) — what is built, in which order, and the open questions.
3. [ARCHITECTURE.md](ARCHITECTURE.md) — components, flows, modules, boundaries.
4. [AGENT_PROTOCOL.md](AGENT_PROTOCOL.md) — the LPA ↔ LPanel wire format.
5. [THREAT_MODEL.md](THREAT_MODEL.md) — assets, attackers, mitigations, required tests.
6. [DATA_MODEL.md](DATA_MODEL.md) — tables per module.
7. [DEVELOPMENT.md](DEVELOPMENT.md) — running and testing the panel locally.

## Decisions

| ADR | Decision | Status |
|---|---|---|
| [0001](adr/0001-lpa-in-go.md) | LPA in Go | Accepted |
| [0002](adr/0002-agent-pull-transport.md) | Agent pulls; mTLS + Ed25519 | Accepted |
| [0003](adr/0003-separate-panel-nginx-instance.md) | Separate panel Nginx instance | Accepted |
| [0004](adr/0004-os-matrix.md) | Ubuntu 24.04/26.04 + Debian 12/13 | Accepted |
| [0005](adr/0005-lpanel-disables-engine-system.md) | LPanel switches off `engine/System` | Accepted |
| [0006](adr/0006-operations-separate-from-queue.md) | Operations separate from the LPHP queue | Accepted |
| [0007](adr/0007-nginx-first-apache-second.md) | Nginx adapter first, Apache next | Accepted |
| [0008](adr/0008-panel-database-mariadb.md) | Panel DB is MariaDB | Accepted |
| [0009](adr/0009-lpa-privilege-split.md) | `lpa-agent` / `lpa-exec` split | Accepted |
| [0010](adr/0010-root-login-via-authd.md) | Root logs in with the system password via `lpanel-authd` | Accepted |

`design/` is in no LPHP manifest, so `framework:update` never touches it. It is
outside `public/`, so the web server never serves it.
