# Default settings

Every environment variable the framework reads, and every configuration key with
the value it has when nothing sets it. How settings are loaded is explained in
[Configuration](configuration.md); this page is the list.

## Who wins

The framework's defaults read the environment variables. A file in `config/`
comes after them, and **replaces a key it names**:

```
.env              APP_DEBUG=true
config/app.php    return ['debug' => false];
result            app.debug is false: the file named the key, so APP_DEBUG is not used for it
```

To keep a key under the environment's control, read it in the file:

```php
// config/app.php
return ['debug' => Env::bool('APP_DEBUG', false)];
```

Keys the file does not name keep their defaults, environment variables included.
A real environment variable beats the same name in `.env`.

## Environment variables

| Variable | Config key | Default | What it does |
|---|---|---|---|
| `APP_ENV` | `app.env` | production | Free-form name of the environment; yours to branch on. |
| `APP_DEBUG` | `app.debug` | false | Stack traces and messages in responses, and the Whoops page. Never true in production. |
| `APP_EDITOR` | `app.editor` | unset | Editor the debug page links file paths to: phpstorm, vscode, sublime, … |
| `APP_TIMEZONE` | `app.timezone` | UTC | Timezone for every date. An unknown name stops the boot. |
| `MEMORY_LIMIT` | `app.memory_limit` | unset (php.ini) | PHP memory_limit: 256M, 1G, -1. Workers stop at 80% of it. |
| `MAX_EXECUTION_TIME` | `app.max_execution_time` | unset (php.ini) | Seconds a web request may run; 0 = no limit. Not the console. |
| `APP_KEY` | `security.key` | unset | The one secret: signs CSRF tokens, and is what Encrypter derives its key from. php laika security:key. |
| `APP_PREVIOUS_KEYS` | `security.previous_keys` | unset | Retired keys, comma separated: decrypt only, for rotating APP_KEY. |
| `CSRF_ENABLED` | `security.csrf.enabled` | true | CSRF checks on unsafe methods. |
| `MAX_REQUEST_BYTES` | `security.max_request_bytes` | 8388608 (8 MiB) | Larger request bodies get 413. |
| `security.webhooks` | `[]` | — |
| `LOCALIZATION_COUNTRY_HEADER` | `localization.country_header` | unset | CDN country header, e.g. CF-IPCountry; trusted proxies only. |
| `LOCALIZATION_MAXMIND_DATABASE` | `localization.maxmind_database` | unset | Local GeoLite2/GeoIP2 Country or City .mmdb file. |
| `DB_DSN` | `database.connections` | unset | One database with no config file, e.g. mysql:host=…;dbname=…. |
| `DB_USERNAME` | `database.connections` | unset | User for DB_DSN. |
| `DB_PASSWORD` | `database.connections` | unset | Password for DB_DSN. |
| `DB_CONNECTION` | `database.default` | default | Name of the default connection. |
| `CACHE_STORE` | `cache.store` | array | array, file, database or null. |
| `CACHE_CONNECTION` | `cache.connection` | empty (default connection) | Connection for the database cache store. |
| `CACHE_TTL` | `cache.ttl` | 3600 | Seconds an entry lives when the caller does not say. |
| `QUEUE_STORE` | `queue.store` | sync | sync (no worker), file, database or memory. |
| `QUEUE_CONNECTION` | `queue.connection` | empty (default connection) | Connection for the database queue store. |
| `QUEUE_NAME` | `queue.queue` | default | Queue a job goes on when it does not say. |
| `QUEUE_TRIES` | `queue.tries` | 3 | Attempts before a job is recorded as failed. |
| `QUEUE_TIMEOUT` | `queue.timeout` | 60 | Seconds a job may run / hold its reservation. |
| `QUEUE_MAX_MEMORY` | `queue.max_memory` | unset (80% of MEMORY_LIMIT) | A worker stops between jobs at this size: 128M. |
| `APP_URL` | `app.url` | unset | The public address; links in emails are built from it. |
| `STORAGE_DISK` | `storage.default` | local | The disk `$storage->disk()` means without a name. |
| `S3_BUCKET` | `storage.disks.s3.bucket` | unset | Bucket of the `s3` disk. |
| `S3_REGION` | `storage.disks.s3.region` | us-east-1 | |
| `S3_KEY` | `storage.disks.s3.key` | unset | Access key id. |
| `S3_SECRET` | `storage.disks.s3.secret` | unset | Secret access key. |
| `S3_ENDPOINT` | `storage.disks.s3.endpoint` | unset (AWS) | R2, Spaces, B2, MinIO: the service's endpoint. |
| `S3_PATH_STYLE` | `storage.disks.s3.path_style` | false | Bucket in the path rather than the host name. |
| `S3_URL` | `storage.disks.s3.url` | unset | Public base URL (a CDN) for `url()`. |
| `MAIL_TRANSPORT` | `mail.transport` | log | log (send nothing), smtp, sendmail or array. |
| `MAIL_FROM_ADDRESS` | `mail.from.address` | unset | The From of a message that sets none. |
| `MAIL_FROM_NAME` | `mail.from.name` | empty | |
| `MAIL_HOST` | `mail.smtp.host` | 127.0.0.1 | |
| `MAIL_PORT` | `mail.smtp.port` | 587 | |
| `MAIL_ENCRYPTION` | `mail.smtp.encryption` | tls | tls (STARTTLS), ssl or none. |
| `MAIL_USERNAME` | `mail.smtp.username` | unset | |
| `MAIL_PASSWORD` | `mail.smtp.password` | unset | |
| `SESSION_STORE` | `session.store` | file | file, database or memory (tests). |
| `SESSION_CONNECTION` | `session.connection` | empty (default connection) | Connection for the database session store. |
| `SESSION_IDLE` | `session.idle` | 7200 | Seconds of inactivity before a session ends. |
| `SESSION_ABSOLUTE` | `session.absolute` | 0 (none) | Hard maximum session length, in seconds. |
| `SESSION_GRACE` | `session.grace` | 30 | Seconds an old session id keeps working after regeneration; 0 for none. A login leaves none either way. |
| `SESSION_COOKIE` | `session.cookie.name` | session | Session cookie name. |
| `SESSION_COOKIE_LIFETIME` | `session.cookie.lifetime` | 0 (browser session) | Cookie lifetime in seconds. |
| `SESSION_COOKIE_SECURE` | `session.cookie.secure` | unset (follow the request) | Require HTTPS for the session cookie. |
| `SCHEDULER_TIMEZONE` | `scheduler.timezone` | unset (APP_TIMEZONE) | Clock scheduled tasks are read against. |
| `SCHEDULER_LOCK_TTL` | `scheduler.lock_ttl` | 3600 | Seconds a schedule lock survives a crashed process. |
| `LOG_LEVEL` | `logging.level` | unset (debug if APP_DEBUG, else info) | Lowest level written. |
| `LOG_CONNECTION` | `logging.database.connection` | empty (default connection) | Connection for the database log writer. |
| `APP_PROFILE` | `observability.profile` | false | Record where each request's time went. Do not leave on. |
| `SLOW_QUERY_MS` | `observability.slow_query_ms` | 0 (off) | Log statements slower than this many milliseconds. |

Every one of these is also in [`.env.example`](../../.env.example), and an
architecture test fails if the framework reads one that is not listed there.

## Configuration keys

Grouped by file: `app.*` is `config/app.php`, `queue.*` is `config/queue.php`,
and so on. A module's own keys are under its name — `Billing.*` in
`config/Billing.php` — and are listed by the module, not here. The values are
the defaults with no environment variable set.


### `app`

| Key | Default | Environment variable |
|---|---|---|
| `app.env` | `'production'` | `APP_ENV` |
| `app.debug` | `false` | `APP_DEBUG` |
| `app.timezone` | `'UTC'` | `APP_TIMEZONE` |
| `app.url` | `null` | `APP_URL` |
| `app.memory_limit` | `null` | `MEMORY_LIMIT` |
| `app.max_execution_time` | `null` | `MAX_EXECUTION_TIME` |
| `app.editor` | `null` | `APP_EDITOR` |
| `app.handle_errors` | `true` | — |

### `http`

| Key | Default | Environment variable |
|---|---|---|
| `http.base_path` | `null` | — |
| `http.trusted_proxies` | `[]` | — |
| `http.headers` | `[]` (no X-Engine; PHP's X-Powered-By is removed) | — |
| `http.client.timeout` | `30` | — |
| `http.client.user_agent` | `null` (LPHP/&lt;version&gt;) | — |

### `database`

| Key | Default | Environment variable |
|---|---|---|
| `database.default` | `'default'` | `DB_CONNECTION` |
| `database.connections` | `[]` | `DB_DSN`, `DB_USERNAME`, `DB_PASSWORD` |

### `assets`

| Key | Default | Environment variable |
|---|---|---|
| `assets.url` | `null` | — |
| `assets.versioning` | `'content'` | — |
| `assets.manifests` | `true` | — |
| `assets.max_age` | `31536000` | — |

### `storage`

| Key | Default | Environment variable |
|---|---|---|
| `storage.default` | `'local'` | `STORAGE_DISK` |
| `storage.disks.local.driver` | `'local'` | — |
| `storage.disks.local.root` | `'system/Storage'` | — |
| `storage.disks.local.url` | `null` | — |
| `storage.disks.s3.driver` | `'s3'` | — |
| `storage.disks.s3.bucket` | `unset` | `S3_BUCKET` |
| `storage.disks.s3.region` | `'us-east-1'` | `S3_REGION` |
| `storage.disks.s3.key` | `unset` | `S3_KEY` |
| `storage.disks.s3.secret` | `unset` | `S3_SECRET` |
| `storage.disks.s3.endpoint` | `unset` | `S3_ENDPOINT` |
| `storage.disks.s3.path_style` | `false` | `S3_PATH_STYLE` |
| `storage.disks.s3.url` | `unset` | `S3_URL` |
| `storage.disks.s3.root` | `''` | — |

See [Storage](storage.md).

### `cache`

| Key | Default | Environment variable |
|---|---|---|
| `cache.store` | `'array'` | `CACHE_STORE` |
| `cache.connection` | `''` | `CACHE_CONNECTION` |
| `cache.table` | `'cache'` | — |
| `cache.ttl` | `3600` | `CACHE_TTL` |
| `cache.namespace` | `''` | — |

### `mail`

| Key | Default | Environment variable |
|---|---|---|
| `mail.transport` | `'log'` | `MAIL_TRANSPORT` |
| `mail.from.address` | `unset` | `MAIL_FROM_ADDRESS` |
| `mail.from.name` | `''` | `MAIL_FROM_NAME` |
| `mail.smtp.host` | `'127.0.0.1'` | `MAIL_HOST` |
| `mail.smtp.port` | `587` | `MAIL_PORT` |
| `mail.smtp.encryption` | `'tls'` | `MAIL_ENCRYPTION` |
| `mail.smtp.username` | `unset` | `MAIL_USERNAME` |
| `mail.smtp.password` | `unset` | `MAIL_PASSWORD` |
| `mail.smtp.timeout` | `30` | — |
| `mail.sendmail.path` | `'/usr/sbin/sendmail'` | — |

### `queue`

| Key | Default | Environment variable |
|---|---|---|
| `queue.store` | `'sync'` | `QUEUE_STORE` |
| `queue.connection` | `''` | `QUEUE_CONNECTION` |
| `queue.table` | `'jobs'` | — |
| `queue.queue` | `'default'` | `QUEUE_NAME` |
| `queue.tries` | `3` | `QUEUE_TRIES` |
| `queue.timeout` | `60` | `QUEUE_TIMEOUT` |
| `queue.max_memory` | `null` | `QUEUE_MAX_MEMORY` |
| `queue.sleep` | `1` | — |
| `queue.backoff.base` | `5` | — |
| `queue.backoff.multiplier` | `2` | — |
| `queue.backoff.cap` | `600` | — |
| `queue.backoff.jitter` | `0.0` | — |

### `security`

| Key | Default | Environment variable |
|---|---|---|
| `security.key` | `unset` | `APP_KEY` |
| `security.previous_keys` | `unset` | `APP_PREVIOUS_KEYS` |
| `security.csrf.enabled` | `true` | `CSRF_ENABLED` |
| `security.csrf.check_origin` | `true` | — |
| `security.csrf.lifetime` | `7200` | — |
| `security.csrf.host_prefix` | `true` | — |
| `security.counters` | `'file'` | — |
| `security.max_request_bytes` | `8388608` | `MAX_REQUEST_BYTES` |
| `security.headers.csp` | `null` (the default policy on HTML pages while debug is off; `''` for none) | — |
| `security.headers.hsts_days` | `0` | — |
| `security.headers.hsts_subdomains` | `false` | — |
| `security.headers.extra` | `[]` | — |

### `auth`

| Key | Default | Environment variable |
|---|---|---|
| `auth.password.options` | `[]` | — |
| `auth.throttle.enabled` | `true` | — |
| `auth.throttle.per_login_ip` | `5/15m` | — |
| `auth.throttle.per_ip` | `30/15m` | — |
| `auth.throttle.per_login` | `100/1h` | — |
| `auth.guest_roles` | `[]` | — |
| `auth.passwords.ttl` | `3600` | — |
| `auth.passwords.route` | `'password.reset'` | — |
| `auth.verification.ttl` | `86400` | — |
| `auth.verification.route` | `'email.verify'` | — |
| `auth.social.register` | `false` | — |
| `auth.social.providers` | `[]` | — |
| `auth.two_factor.issuer` | `'LPHP'` | — |
| `auth.two_factor.route` | `'two_factor.challenge'` | — |

### `session`

| Key | Default | Environment variable |
|---|---|---|
| `session.store` | `'file'` | `SESSION_STORE` |
| `session.connection` | `''` | `SESSION_CONNECTION` |
| `session.table` | `'sessions'` | — |
| `session.idle` | `7200` | `SESSION_IDLE` |
| `session.absolute` | `0` | `SESSION_ABSOLUTE` |
| `session.grace` | `30` | `SESSION_GRACE` |
| `session.cookie.name` | `'session'` | `SESSION_COOKIE` |
| `session.cookie.lifetime` | `0` | `SESSION_COOKIE_LIFETIME` |
| `session.cookie.path` | `'/'` | — |
| `session.cookie.domain` | `''` | — |
| `session.cookie.same_site` | `'Lax'` | — |
| `session.cookie.secure` | `null` | `SESSION_COOKIE_SECURE` |

### `scheduler`

| Key | Default | Environment variable |
|---|---|---|
| `scheduler.lock` | `'file'` | — |
| `scheduler.lock_ttl` | `3600` | `SCHEDULER_LOCK_TTL` |
| `scheduler.timezone` | `null` | `SCHEDULER_TIMEZONE` |

### `system`

| Key | Default | Environment variable |
|---|---|---|
| `system.enabled` | `true` | — |
| `system.execution.default_timeout` | `60.0` | — |
| `system.execution.max_output` | `1048576` | — |
| `system.execution.max_concurrent` | `16` | — |
| `system.execution.http_timeout` | `10.0` | — |
| `system.shell.enabled` | `false` | — |
| `system.shell.binary` | `'bash'` | — |
| `system.commands.allowed` | `null` | — |
| `system.commands.scripts` | `null` | — |
| `system.services` | `[]` | — |
| `system.filesystem.read` | `[]` | — |
| `system.filesystem.write` | `[]` | — |
| `system.permissions.owners` | `[]` | — |
| `system.permissions.groups` | `[]` | — |
| `system.cron.enabled` | `true` | — |
| `system.cron.owner` | `null` | — |
| `system.audit.enabled` | `true` | — |

### `mcp`

| Key | Default | Environment variable |
|---|---|---|
| `mcp.enabled` | `true` | — |
| `mcp.server.name` | `'lphp'` | — |
| `mcp.server.version` | `null` | — |
| `mcp.transports.stdio` | `true` | — |
| `mcp.transports.http` | `false` | — |
| `mcp.http.path` | `'/mcp'` | — |
| `mcp.allow_guests` | `false` | — |
| `mcp.log.enabled` | `true` | — |

### `openapi`

| Key | Default | Environment variable |
|---|---|---|
| `openapi.title` | `null` (`app.name`, else `API`) | — |
| `openapi.version` | `'1.0.0'` | — |
| `openapi.description` | `''` | — |
| `openapi.route` | `null` (not served) | — |
| `openapi.docs` | `null` (no page) | — |
| `openapi.runtime` | `true` (read what `openapi:docs` and `openapi:auth` switched) | — |
| `openapi.meta` | `[]` | — |

### `logging`

| Key | Default | Environment variable |
|---|---|---|
| `logging.writers` | `[]` | — |
| `logging.level` | `null` | `LOG_LEVEL` |
| `logging.redact` | `[]` | — |
| `logging.file.prefix` | `'app'` | — |
| `logging.file.retention_days` | `0` | — |
| `logging.syslog.identity` | `'app'` | — |
| `logging.database.connection` | `''` | `LOG_CONNECTION` |
| `logging.database.table` | `'logs'` | — |
| `logging.database.retention_days` | `0` | — |

### `observability`

| Key | Default | Environment variable |
|---|---|---|
| `observability.profile` | `false` | `APP_PROFILE` |
| `observability.slow_query_ms` | `0` | `SLOW_QUERY_MS` |
| `observability.trust_incoming_ids` | `false` | — |

### `localization`

| Key | Default | Environment variable |
|---|---|---|
| `localization.country_header` | `null` | `LOCALIZATION_COUNTRY_HEADER` |
| `localization.maxmind_database` | `null` | `LOCALIZATION_MAXMIND_DATABASE` |

### `templates`

| Key | Default | Environment variable |
|---|---|---|
| `templates.cache` | `false` | — |
| `templates.themes` | `[]` | — |
| `templates.theme_provider` | `null` | — |
| `templates.error_themes` | `[]` | — |

### `modules`

| Key | Default | Environment variable |
|---|---|---|
| `modules.paths` | `['modules']` | — |
| `modules.disabled` | `[]` | — |

### `features`

| Key | Default | Environment variable |
|---|---|---|
| `features` | `[]` | — |

See [Feature flags](features.md).
