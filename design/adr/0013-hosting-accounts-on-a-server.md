# ADR-0013: Hosting accounts on a managed server, and the `lpa-exec` socket

- **Status:** Accepted (product owner, 2026-10-28: admins only manage accounts; delete removes files at once after typed confirmation; `hidepid` deferred to M13)
- **Context:** M6a is the first time LPA changes a server. [ADR-0009](0009-lpa-privilege-split.md) put privileged work in `lpa-exec`, behind a Unix socket, but left the socket protocol and the shape of an account open. Both decide what a compromised customer, a compromised `lpa-agent` and a careless administrator can do.

## Decision

### An account on the server

| Thing | Value | Why |
|---|---|---|
| Linux user and group | `lp` + 8 characters of `[a-z2-7]`, chosen by the panel; its own group of the same name | a name that can't collide with a person's or a package's account, and that a regex can check |
| uid / gid | 20000–59999, picked by `useradd -K UID_MIN=… -K UID_MAX=…` | never mixed with people (1000+) or system users |
| shell, password | `/usr/sbin/nologin`, locked | SFTP comes in M10 |
| GECOS | `LPanel account <account uid>` | readable by an administrator with `getent` |
| home | `/home/<user>`, **`0750 root:<user>`** | root-owned, so the account can't rename what's in it, and it's ready for an SFTP chroot (Q3, M10); others can't even list it |
| `web/` | `0750 <user>:<user>` | websites (M6b) |
| `tmp/` | `0700 <user>:<user>` | PHP's temp files, uploads and sessions; only the account itself reads them |
| `logs/` | `0750 <user>:<user>` | the pool's PHP error log |
| web server | `www-data` joins the account's group | Nginx (and later Apache) reads static files through group membership only; PHP runs as the account, never as `www-data` |
| PHP-FPM pool | `/etc/php/<v>/fpm/pool.d/lpanel-<user>.conf`, running as the account; socket `/run/php/lpanel-<user>.sock`, `0660 <user>:www-data`; `open_basedir` = the home; temp, upload and session paths inside `tmp/` | another account can't connect to it or read its sessions |
| PHP version | the distro default: exactly one `php-fpm` installed, or the operation refuses (ADR-0004) | multi-version PHP is out of MVP |
| record | `/var/lib/lpa-exec/accounts/<user>.json` (`0600 root`) with the panel's account id | **the server-side proof that LPanel made this user.** Suspend and delete refuse a user with no record, or one whose record names another account |
| limits | stored in the panel only | disk quotas need quota-enabled filesystems; enforcing them is a later milestone |

**Suspend:** the account expires (`usermod --expiredate 1`, so PAM refuses logins and cron), its pool is switched off (`.conf` → `.conf.suspended`) and reloaded away, and its remaining processes are killed. Files are kept. **Unsuspend** reverses each step and writes the pool afresh. **Delete** checks the record, does everything suspend does, then `userdel` (which also removes the group) and removes the home without following symlinks. There are no backups until M11, so the panel asks the administrator to type the user name first.

Every operation **converges**: run twice, it ends in the same state and gives the same result. A failure part-way leaves a state that retrying finishes, or that delete removes. That is the compensation (AGENT_PROTOCOL.md §6.2): no step is undone on failure, because undoing is just as able to fail.

### The `lpa-exec` socket

- `lpa-exec.socket` listens on `/run/lpa/exec.sock`, `0660 root:lpa`. systemd starts `lpa-exec.service` (root, hardened, `RestrictAddressFamilies=AF_UNIX`; since M6b also IP to `localhost` only, for `nginx -t` and the website probe, [ADR-0014](0014-websites-through-the-hosting-nginx.md)) on the first connection.
- One request per connection, at most 256 KiB, read within 10 s. **The request is the panel's signed operation, exactly as the agent received it:**

  ```json
  {"v": 1, "operation": {"operation": "<base64url bytes>", "kid": "…", "signature": "…"}}
  ```

- `lpa-exec` **trusts nothing from the agent.** It checks, itself:
  1. the peer, with `SO_PEERCRED`: it must be the `lpa` uid;
  2. the signature, against the panel key in `/etc/lpa/enrollment.json`;
  3. a strict decode; `agent_id`; `not_after`; the operation in its own compiled-in registry; the target and the payload.

  So a compromised `lpa-agent` can't make up an operation. It can only pass on ones the panel signed for this server, until their `not_after`.
- **Each idempotency key runs at most once.** `lpa-exec` remembers each succeeded key's result for 7 days (`/var/lib/lpa-exec/done/`), and answers a repeat with that result without acting. A replayed `account.create` can't undo a later delete.
- It runs one operation at a time, to the end, even if the agent hangs up; the agent then reports what happened, not a cancel. The answer:

  ```json
  {"status": "succeeded", "result": { }}
  {"status": "failed", "error_code": "precondition_failed", "message": "…"}
  ```

### Operations (v1, account scope, mutating, idempotent)

| op | payload | result |
|---|---|---|
| `account.create` | `{system_user}` | `{system_user, uid, gid, home, php_version, pool_socket, state: "active"}` |
| `account.suspend` | `{system_user}` | `{system_user, state: "suspended"}` |
| `account.unsuspend` | `{system_user}` | `{system_user, state: "active"}` |
| `account.delete` | `{system_user}` | `{system_user, state: "deleted"}` |

The target names the server **and** the account (`target.account_id`, the panel's ULID). New error code: **`config_rejected`**, for when the service's own check (`php-fpm -t`) refuses the generated file. The file is then put back as it was, and nothing is reloaded.

## Consequences

- `ProtectHome` is off for `lpa-exec`, and `/etc` is writable for it, because `useradd` writes there. The unit trims capabilities to what `useradd`, `chown`, `kill` and removing a home need.
- `hidepid` stays off until M13: account A can see that B's PHP workers exist (their names), but not their memory, environment or files.
- Customers can't create accounts. Only `accounts.manage` (root and admins) can.
