# LPanel + LPA architecture

The system as built for the MVP ([MVP_SCOPE.md](MVP_SCOPE.md)). Every choice
here is either a verified LPHP fact ([LPHP_DISCOVERY.md](LPHP_DISCOVERY.md)) or
an ADR in [adr/](adr/).

## 1. Components

```
 Browser / API client
        │ HTTPS :8443 (session cookie + CSRF, or bearer token)
        ▼
 ┌──────────────── panel host ────────────────────────────────┐
 │ lpanel-nginx.service  (own config tree, ADR-0003)           │
 │   └─ PHP-FPM pool "lpanel" (user lpanel) ── LPHP app        │
 │         modules/: Shared, Identity, Audit, Servers,         │
 │                   Operations, Dashboard, Hosting, …         │
 │   lpanel-worker@default.service  (php laika queue:work)     │
 │   lpanel-scheduler.timer         (php laika schedule:run)   │
 │   MariaDB "lpanel" (ADR-0008)                               │
 │   /etc/lpanel/keys/operations.key (Ed25519, not in DB)      │
 └─────────────────────────────────────────────────────────────┘
        ▲  HTTPS :8443 /agent/v1/* — mTLS + signed envelopes,
        │  agent-initiated only (ADR-0002, AGENT_PROTOCOL.md)
 ┌──────┴────────── managed server (each) ────────────────────┐
 │ lpa-agent.service  (user lpa; network, verify, journal)     │
 │      │ /run/lpa/exec.sock (SO_PEERCRED = lpa)               │
 │ lpa-exec.service   (root, hardened; registry ops only)      │
 │      ├─ hosting adapter: Nginx (ADR-0007) │ Apache later    │
 │      ├─ PHP-FPM pools per account                           │
 │      └─ MariaDB (customer databases)                        │
 └─────────────────────────────────────────────────────────────┘
```

The panel host normally also runs LPA, enrolled like any other server, so the
panel can see its own health. That copy of LPA treats `lpanel-nginx` as
read-only (status only).

## 2. Request and operation flow

1. A user calls an LPanel API route. LPHP checks CSRF or the token, `auth`, `can`, the rate limit and the schema.
2. The module's service checks ownership with the `authorization.decision` filter, which can refuse but never grant. It also checks quota and the server's capability document.
3. In **one DB transaction**, the module:
   - writes the desired-state change (e.g. `websites.state = provisioning`);
   - inserts an `operations` row (`queued`), with its signed envelope prepared at claim time;
   - inserts `audit_events`.
4. The API answers `202 Accepted` with the operation's URL. The UI polls `GET /api/v1/operations/{id}`.
5. The server's agent polls `/agent/v1/operations/claim`. The panel leases the next eligible operation (guarded `UPDATE`) and returns it signed with the operation key.
6. `lpa-agent` verifies the signature and the payload schema, and checks its journal (§7 of the protocol). For a privileged operation it forwards to `lpa-exec`, which re-validates and runs the adapter. While the work runs, the agent extends the lease.
7. The agent posts the result. The panel validates it against `result_schema`, stores it, updates the resource state, and writes `operation_events` and an audit event — in one transaction.
8. A per-minute scheduled task sweeps lapsed leases ([ADR-0006](adr/0006-operations-separate-from-queue.md)). It also marks agents with no heartbeat for 3 × the poll interval as `offline`; new operations for an offline server are refused at creation.
9. Reconciliation (later milestone) is just more operations: `*.drift_check` operations report differences. They correct nothing unless a policy for that operation explicitly enables it.

## 3. LPanel modules (LPHP conventions)

Each module is `modules/<Name>/` with a `module.php`, plus the subfolders it needs:

- `Http/` — route handlers
- `Jobs/` — LPHP queue jobs
- `Commands/` — console commands
- `Database/Migrations/`
- `Templates/` (`@<Name>/…`)
- `assets/` (`/assets/module/<Name>/…`)

Dependencies are declared with `->requires()`. Proposed names (plan §4 Phase 2):

| Module | Owns | Requires |
|---|---|---|
| Shared | `DataSource`, `/health`; shared UI layout partials | — |
| Identity | users, roles, role assignments, API tokens, TOTP; `UserProvider`, `TokenProvider`, `TwoFactorAccounts`; login / logout / reset / 2FA screens | — |
| Audit | `audit_events`; one `AuditLog` service other modules call | — |
| Servers | servers, enrollments, agent credentials, `/agent/v1/*`, the Ed25519 verifier and signer | Identity, Audit |
| Operations | `operations`, `operation_events`, the operation catalogue, the lease sweeper, the `/api/v1/operations` API | Servers, Audit |
| Dashboard | `/` and the admin and customer home screens | Identity, Servers, Operations |
| Hosting | hosting accounts, limits, customer ↔ account ownership | Identity, Servers, Operations |
| Websites | domains, websites, managed web settings, PHP pools, config history | Hosting |
| Databases, Certificates, Files, Cron, Backups, Monitoring, DNS, Mail | later milestones | Hosting (and others as needed) |

Rules:

- No circular `requires()`. LPHP refuses those at boot.
- A module reads another module's data only through that module's service classes, never its tables.
- No module imports `App\Engine\System\*` ([ADR-0005](adr/0005-lpanel-disables-engine-system.md)). Operations code never imports `App\Engine\Queue\*` ([ADR-0006](adr/0006-operations-separate-from-queue.md)).

## 4. API surface

| Prefix | Who | Auth | CSRF |
|---|---|---|---|
| `/` (HTML pages) | browsers | session | on (default) |
| `/api/v1/*` | the panel's own JS | session cookie | **on**, via `X-CSRF-TOKEN` |
| `/api/token/v1/*` | automation clients | bearer token (`TokenProvider`) only | `'csrf' => false`; on such a route the session cookie authenticates nobody |
| `/agent/v1/*` | LPA only | mTLS + Ed25519 envelope | `'csrf' => false` |
| `/health` | load balancer | none | n/a |

Automation gets its own prefix because LPHP checks CSRF on every unsafe request
unless the route opts out, and a bearer token doesn't exempt it
(`engine/Security/Guard.php:136-147`). Both API prefixes call the same handler
classes; only the group meta differs.

Error bodies use LPHP's single error shape. Lists use `ApiResponse::collection`, with cursor pagination wherever a table can grow without limit (operations, audit).

## 5. UI

- Twig templates. Bootstrap 5 and jQuery are **vendored** into `public/assets/vendor/`, not loaded from a CDN, because of the default CSP.
- All data loads and changes go through `/api/v1`. Untrusted values are inserted with `.text()` / `textContent`, never `.html()`.
- Two navigation trees: one for administrators, one for customers. Shared layout lives in `templates/layout.twig`, overridden for LPanel.
- Every operation shows `queued` / `running` / `succeeded` / `failed` / `needs_attention`, with the stored `message`. Buttons are disabled while a mutation is pending.

## 6. Panel deployment (summary)

- `lpanel` system user. The application lives in `/opt/lpanel/` (owned by root, readable by `lpanel`).
- Writable by `lpanel`: `system/` and nothing else.
- Panel Nginx serves `/opt/lpanel/public` only. Its server block starts from `php laika nginx:make --listen 8443 --root /opt/lpanel`, then is extended with TLS, client-cert and HSTS settings.
- `.env` holds `APP_KEY` and `DB_*` (`0640 root:lpanel`). The operation-signing key is a separate file (`AGENT_PROTOCOL.md §1`).
- Install and upgrade order: stop workers → `php laika down` → copy release → `composer install --no-dev` → `php laika migrate` (as `lpanel_migrate`) → `php laika cache:warm` → `php laika up` → start workers.

## 7. Where the boundaries are

| Boundary | Enforced by |
|---|---|
| Customer ↔ customer (panel) | `authorization.decision` ownership filter; tests that change ids |
| Customer ↔ customer (server) | distinct Linux uid per account, PHP-FPM pool per account, `0750` homes, open_basedir as defense in depth only |
| Panel process ↔ OS | `system.enabled = false`; `lpanel` user has no sudo |
| Panel ↔ agent | mTLS + per-request signatures + signed operations + agent-side schema validation |
| Agent network side ↔ root | `lpa-agent` / `lpa-exec` split with `SO_PEERCRED` ([ADR-0009](adr/0009-lpa-privilege-split.md)) |
| Panel Nginx ↔ hosting web server | separate instance, config tree, unit and listener ([ADR-0003](adr/0003-separate-panel-nginx-instance.md)) |
