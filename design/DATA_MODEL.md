# Data model

The logical model from plan §5, mapped to LPHP modules and to MariaDB
([ADR-0008](adr/0008-panel-database-mariadb.md)). Each table lives in
`modules/<Owner>/Database/Migrations/` and is built with LPHP's schema builder
(`$tables->create(...)`, `docs/reference/database.md:404-603`).

The column types are a proposal. Confirm each one against the schema builder's
methods when the migration is written.

## Conventions

- `id` is a `bigint` auto-increment primary key, used internally. Resources that leave the panel (in URLs, the API, or to agents) also get `uid` CHAR(26) ULID, unique. **The API never exposes the numeric `id`**, so ids can't be counted to enumerate records.
- Timestamps are UTC `DATETIME(6)` (`created_at`, `updated_at`), and are shown in the user's timezone.
- Every hosting resource has `account_id` **and** `server_id`, both foreign keys, both not null.
- Secrets are never stored in plain text. They are either hashed (tokens, enrollment), encrypted with LPHP `Encrypter` and a per-purpose context (`'db_password'`), or never stored at all (shown once).
- **No table is named `jobs`.** That is LPHP's queue table ([LPHP_DISCOVERY.md §8](LPHP_DISCOVERY.md#8-background-work)).

## Identity (module Identity)

| Table | Key columns |
|---|---|
| `users` | **built (M2a):** `uid` (ULID), `login` (unique, lower case), `name`, `email`, `kind` (`system` = root, checked by lpanel-authd and never by a hash; `panel`), `password_hash` (null for root), `status`, `totp_secret` (Encrypter, context `identity.totp`), `totp_last_step`, `recovery_codes` (JSON of hashes), `last_login_at`, `session_epoch` (M2b: a session holds the number it started with; bumping it ends every other session), timestamps |
| `user_roles` | **built (M2a):** `user_id`, `role` (name of a code-declared role: `admin`, `customer`) — unique(user, role). `root` is never stored; the provider grants it to the system root row alone. Account ownership (M6a) is a column on the account, not a role: a customer owns its accounts (`accounts.own`), administrators see and manage all (`accounts.*`) |
| `roles`, `role_capabilities` | for custom roles; not built until someone needs one. Built-in roles live in `modules/Identity/module.php` |
| `api_tokens` | **built (M2b):** `uid`, `user_id`, `name`, `prefix` (first 12 characters, for telling tokens apart), `fingerprint` (SHA-256, unique; the token itself is never stored), `expires_at` (30/90/180/365 days, no "never"), `last_used_at`, `last_used_ip`, `revoked_at`, timestamps. A token acts with its owner's current roles; narrower token scopes are not built yet. Root has none (ADR-0011) |
| `password_resets` | **not needed (M2b):** LPHP's `PasswordReset` links are stateless, signed with APP_KEY and bound to the current password hash, so setting a password spends the link. Root never gets one |

Sessions: the LPHP `database` session store (`framework:…create_sessions` migration), set with `session.store = database`.

## Audit (module Audit)

| Table | Key columns |
|---|---|
| `audit_events` | `occurred_at`, `actor_type` (user/token/agent/system), `actor_id`, `action` (e.g. `website.create.requested`), `target_type`, `target_uid`, `server_id`, `account_id`, `request_id`, `ip`, `outcome` (allowed/denied/succeeded/failed), `metadata` (JSON, redacted); index (account_id, occurred_at), (target_type, target_uid) |

## Infrastructure (module Servers)

| Table | Key columns |
|---|---|
| `servers` | **built (M3):** `uid`, `name`, `state` (pending / active / revoked; *online* or *offline* is worked out from `last_seen_at` against `Servers.offline_after`, 180 s), `agent_id` (UUIDv4, unique, null until paired), `hostname`, `os_id`, `os_version`, `kernel`, `machine_id_sha256` (the raw machine id never leaves the server), `agent_version`, `protocols`, `capabilities`, `health` (JSON, each validated strictly on the way in), `rotate_requested`, `enrolled_at`, `last_seen_at`, `last_seen_ip`, `created_by`, timestamps. Later: `desired_backend`, `detected_backend` (M5) |
| `agent_credentials` | **built (M3):** `server_id`, `kid` (unique), `public_key` (Ed25519, base64url), `tls_cert_sha256`, `state` (active / rotating / revoked), `retire_at` (end of a rotation overlap), `revoked_at`, timestamps. Who revoked is in the audit log |
| `agent_enrollments` | **built (M3):** `server_id`, `token_hash` (SHA-256, unique; the token is shown once), `expires_at` (15 min), `created_by`, `consumed_at`, `consumed_from_ip`, `revoked_at` (a newer token for the same server revokes the older one) |
| `agent_seen_requests` | **built (M3):** `agent_id`, `request_id`, `expires_at` (unix seconds, `expires_at` of the request + 60 s); unique (agent_id, request_id), which decides a race between two copies. `servers:sweep` empties it every minute |

## Operations (module Operations)

| Table | Key columns |
|---|---|
| `operations` | **built (M4):** `uid` (UUIDv4, the wire `operation_id`), `server_id`, `op`, `op_version`, `target_type`, `target_uid`, `payload` (JSON, **no secrets**; secrets go through the sealed envelope), `idempotency_key` (mutations only; unique per server), `mutating` and `idempotent` (copied from the definition when queued, so retries follow what was promised), `status`, `attempt` (deliveries so far), `max_attempts`, `cancel_requested`, `lease_seconds`, `timeout_seconds`, `lease_until`, `not_after`, `requested_by` (user id; null for the console), `requested_by_name`, `error_code`, `message`, `result` (JSON, only when it passed the result schema), `result_attempt`, `agent_version`, `started_at` / `finished_at` (as the agent reports), `queued_at`, `claimed_at`, `completed_at`; index (server_id, status, queued_at), index (status, lease_until) |
| `operation_events` | **built (M4):** `operation_id`, `occurred_at`, `from_status`, `to_status` (equal for "cancel requested"), `attempt`, `actor` (user / agent / sweeper / system), `detail` (JSON, sanitized: an error code) |

## Hosting (modules Hosting, Websites; later ones in their own modules)

| Table | Owner | Key columns |
|---|---|---|
| `hosting_accounts` | Hosting | **built (M6a):** `uid`, `server_id`, `owner_uid` (users.uid of the customer), `label`, `system_user` (unique per server, `lp` + 8 of `[a-z2-7]`), `system_uid`, `system_gid`, `php_version` (from the agent's result), `status` (provisioning / active / suspending / suspended / resuming / deleting / deleted / failed), `previous_status` (where a failed action goes back to), `last_action`, `operation_uid` (the operation under way; only it may settle the account), `last_error_code`, `last_error`, `limits` (JSON, stored, not yet enforced), `created_by`, `suspended_at`, `deleted_at`, timestamps. Settled in the operation's own transaction through the `operations.finished` hook |
| `websites` | Websites | **built (M6b, ADR-0014):** `uid`, `server_id`, `account_id`, `owner_uid` (the account's customer, copied for scoping), `domain` (primary), `settings` (JSON: domains, document_root, php, index, redirects, headers — what the server serves), `pending_settings` (an update under way; they replace `settings` only once served), `status` (provisioning / active / updating / suspending / suspended / resuming / deleting / deleted / failed), `previous_status`, `last_action`, `operation_uid`, `last_error_code`, `last_error`, `revision` (what the website's probe answers), `config_state` (ok / edited / missing), `config_checked_at`, `created_by`, `suspended_at`, `deleted_at`, timestamps |
| `website_domains` | Websites | **built (M6b):** `server_id`, `website_id`, `domain`; unique (`server_id`, `domain`): a domain belongs to one website per server, reserved while an update that adds it is under way, released when it is no longer served |
| `domains` (verification) | Domains (later) | DNS verification of who may claim a name; in M6b only administrators choose domains |
| website config history | Websites | each change is an operation (payload = the settings asked for, result = the revision served) and audit events (`website.*`, `website.drift`, `website.restore`); a separate versions table can come with the history screen |
| `php_pools` | Hosting | one per account (ADR-0013), on the server; PHP settings per website are a later milestone |
| `databases`, `database_users` | Databases (later) | `account_id`, `server_id`, `name` (prefixed with the account's system user), grants; password never stored after it is shown once |
| `certificates` | Certificates (later) | `domain set`, `issuer`, `not_after`, `renewal_state`, `last_error_code` |
| `cron_jobs`, `backup_jobs`, `backup_artifacts`, `dns_zones`, `dns_records`, `resource_usage_samples` | later modules | as plan §5 |

## Integrity rules

- Foreign keys use `ON DELETE RESTRICT`. Deleting a resource is a state change (`deleting` → operation → `deleted`), never a cascade.
- Desired state and operations are written in one transaction ([ARCHITECTURE.md §2](ARCHITECTURE.md#2-request-and-operation-flow)). The OS side is a saga: a DB rollback doesn't undo an OS change, so the result handler records partial failure as `needs_attention`.
