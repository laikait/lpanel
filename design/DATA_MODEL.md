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
| `users` | **built (M2a):** `uid` (ULID), `login` (unique, lower case), `name`, `email`, `kind` (`system` = root, checked by lpanel-authd and never by a hash; `panel`), `password_hash` (null for root), `status`, `totp_secret` (Encrypter, context `identity.totp`), `totp_last_step`, `recovery_codes` (JSON of hashes), `last_login_at`, timestamps |
| `user_roles` | **built (M2a):** `user_id`, `role` (name of a code-declared role: `admin`, `customer`) — unique(user, role). `root` is never stored; the provider grants it to the system root row alone. Account-scoped roles arrive with hosting accounts (M6) |
| `roles`, `role_capabilities` | for custom roles; not built until someone needs one. Built-in roles live in `modules/Identity/module.php` |
| `api_tokens` | `user_id`, `name`, `fingerprint` (SHA-256, unique), `role_id` (a token may hold fewer rights than its user), `last_used_at`, `expires_at`, `revoked_at` |
| `password_resets` | as LPHP's `PasswordReset` needs — confirm in the Identity milestone |

Sessions: the LPHP `database` session store (`framework:…create_sessions` migration), set with `session.store = database`.

## Audit (module Audit)

| Table | Key columns |
|---|---|
| `audit_events` | `occurred_at`, `actor_type` (user/token/agent/system), `actor_id`, `action` (e.g. `website.create.requested`), `target_type`, `target_uid`, `server_id`, `account_id`, `request_id`, `ip`, `outcome` (allowed/denied/succeeded/failed), `metadata` (JSON, redacted); index (account_id, occurred_at), (target_type, target_uid) |

## Infrastructure (module Servers)

| Table | Key columns |
|---|---|
| `servers` | `uid`, `name`, `state` (pending/online/offline/maintenance/revoked), `os_id`, `os_version`, `agent_version`, `protocols` (JSON), `capabilities` (JSON), `last_seen_at`, `desired_backend` (nginx/apache), `detected_backend`, `facts` (JSON) |
| `agent_credentials` | `server_id`, `agent_uuid` (unique), `kid`, `public_key` (32 bytes), `tls_fingerprint`, `state` (active/rotating/revoked), `issued_at`, `revoked_at`, `revoked_by` |
| `agent_enrollments` | `server_id`, `token_hash` (unique), `expires_at`, `created_by`, `consumed_at`, `revoked_at`, `consumed_from_ip` |
| `agent_seen_requests` | `agent_uuid`, `request_id`, `expires_at` — primary key (agent_uuid, request_id). Swept every minute. (Alternative: the LPHP cache, if a shared store is configured) |

## Operations (module Operations)

| Table | Key columns |
|---|---|
| `operations` | `uid`, `server_id`, `op`, `op_version`, `target_type`, `target_uid`, `payload` (JSON, **no secrets**; secrets go through the sealed envelope), `idempotency_key` (unique per server), `status`, `attempt`, `max_attempts`, `lease_until`, `not_after`, `timeout_seconds`, `requested_by` (user/token id), `request_id`, `error_code`, `message`, `result` (JSON), `queued_at`, `claimed_at`, `finished_at` ; index (server_id, status, queued_at) |
| `operation_events` | `operation_id`, `occurred_at`, `from_status`, `to_status`, `attempt`, `detail` (JSON, sanitized) |

## Hosting (modules Hosting, Websites; later ones in their own modules)

| Table | Owner | Key columns |
|---|---|---|
| `hosting_accounts` | Hosting | `uid`, `owner_user_id`, `server_id`, `system_user` (unique per server, `lp` + 8 lowercase base32 chars), `system_uid`, `status` (provisioning/active/suspended/deleting/deleted/failed), `limits` (JSON: disk_mb, inodes, websites, databases) |
| `domains` | Websites | `account_id`, `server_id`, `name` (normalized, IDNA ASCII, unique per server), `type` (primary/alias/subdomain), `verification_state` |
| `websites` | Websites | `uid`, `account_id`, `server_id`, `primary_domain_id`, `document_root` (relative to the account home, validated), `php_version`, `state`, `desired_settings` (JSON, schema-validated), `applied_version` |
| `website_config_versions` | Websites | `website_id`, `version`, `settings` (JSON), `rendered_sha256`, `requested_by`, `validation_result`, `applied_at`, `rolled_back_at`, `operation_id` |
| `config_drift_events` | Websites | `server_id`, `website_id` (nullable), `path_label` (no raw paths for customers), `expected_sha256`, `found_sha256`, `detected_at`, `resolution` |
| `php_pools` | Websites | `website_id` or `account_id`, `php_version`, `settings` (JSON within policy) |
| `databases`, `database_users` | Databases (later) | `account_id`, `server_id`, `name` (prefixed with the account's system user), grants; password never stored after it is shown once |
| `certificates` | Certificates (later) | `domain set`, `issuer`, `not_after`, `renewal_state`, `last_error_code` |
| `cron_jobs`, `backup_jobs`, `backup_artifacts`, `dns_zones`, `dns_records`, `resource_usage_samples` | later modules | as plan §5 |

## Integrity rules

- Foreign keys use `ON DELETE RESTRICT`. Deleting a resource is a state change (`deleting` → operation → `deleted`), never a cascade.
- Desired state and operations are written in one transaction ([ARCHITECTURE.md §2](ARCHITECTURE.md#2-request-and-operation-flow)). The OS side is a saga: a DB rollback doesn't undo an OS change, so the result handler records partial failure as `needs_attention`.
