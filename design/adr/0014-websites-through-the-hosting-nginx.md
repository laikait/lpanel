# ADR-0014: Websites through the hosting Nginx: managed settings, checked apply, rollback and hand edits

- **Status:** Accepted (product owner, 2026-10-28: customers change their own websites' managed settings; a hand edit is checked with `nginx -t` and reloaded if it passes, never overwritten without an administrator; deleting a website keeps its files; suspending an account answers its websites with 503)
- **Context:** M6b serves websites from hosting accounts ([ADR-0013](0013-hosting-accounts-on-a-server.md)) through the server's own Nginx. Plan §3.5 requires managed configuration: structured settings, a candidate tested in the real Nginx context, atomic apply, rollback verified by a health check, drift reported. The panel's own Nginx is a separate instance ([ADR-0003](0003-separate-panel-nginx-instance.md)) that this must never touch.

## Decision

### Where websites live

| Thing | Value | Why |
|---|---|---|
| Nginx | the distribution's `nginx.service`, `/etc/nginx` | the hosting Nginx; the panel's is `lpanel-nginx.service` with `/etc/lpanel/nginx`, which no operation can name |
| hook | `/etc/nginx/conf.d/lpanel-websites.conf`: `include /etc/nginx/lpanel/sites/*.conf;` (and `server_names_hash_bucket_size 128;` unless the server sets it) | one file in the distribution's include directory; `nginx.conf` is never edited |
| website file | `/etc/nginx/lpanel/sites/<website id>.conf`, `0640 root:root`, in a `0750 root` directory | customers can't read or write it |
| files | `/home/<user>/web/<document root>`, owned by the account | made by `lpa-exec` walking with `openat(O_NOFOLLOW)`, so a link the account planted is refused, never followed; a new folder gets a placeholder page |
| logs | `/var/log/nginx/lpanel-<website id>.{access,error}.log` | root-owned and rotated by the distribution's logrotate. **Never in the account's `logs/`:** the Nginx master opens logs as root, so a customer's symlink there would make root append to any file |
| record | `/var/lib/lpa-exec/websites/<website id>.json` | the account it belongs to, its settings, and the SHA-256 of the file as LPanel wrote it |

Requires a `nginx.conf` with the line `include /etc/nginx/conf.d/*.conf;` exactly once, as Debian and Ubuntu ship it; anything else is `precondition_failed`.

### Managed settings v1

Domains (1–10, lower-case LDH names with a dot, the first one primary, one website per domain per server), the document root (1–4 folder names under `web/`), PHP on or off, index files, exact-path redirects (301/302/307/308, to a path or an `http(s)` address) and response headers (printable ASCII without `" $ \ { }`; Nginx-owned, hop-by-hop and `X-LPanel-*`/`X-Accel-*` names refused). Each value has a grammar in `lpa` (`internal/websites/settings.go`) that the panel mirrors for its forms; `lpa-exec` is the one that decides. Nothing is ever configuration text: the template quotes every value, and no value can contain what would end a string, a directive or a block.

Every rendered website also gets: `server_tokens off`; `disable_symlinks if_not_owner from=$document_root` (a link in the folder only reaches the account's own files); hidden files except `.well-known` denied; with PHP off, `.php` files denied, never handed out as source; with PHP on, `fastcgi_pass` to the account's own pool. Customers change PHP, index files, redirects and headers of their own websites (`websites.configure`); domains and the document root are an administrator's (`websites.manage`), because claiming a domain on a shared server affects others.

### Applying a change

1. **Stage and test.** The complete candidate tree (every live website file, with the change) is written to `/etc/nginx/lpanel/.stage/`, and `/etc/nginx/.lpanel-candidate.conf` is `nginx.conf` byte for byte except its `conf.d` line, which is spelled out file by file with LPanel's hook pointing at the stage. `nginx -t -c` that file reads the server's real modules, MIME types, snippets and other sites. If it fails, or warns of a `conflicting server name` for one of the change's domains, the answer is `config_rejected` and **no live file was touched and nothing was reloaded**. The stage is removed either way.
2. **Swap.** The record notes the file about to be written (`pending_hash`, so a crash in between is not mistaken for a hand edit); each file is written atomically; the previous contents are kept in memory.
3. **Reload and probe.** `systemctl reload-or-restart nginx.service`. Every website has `location = /.well-known/lpanel-probe` answering `204` with `X-LPanel-Config: <revision>` to `127.0.0.1` and `::1` only; the revision is a hash of the rest of the file. `lpa-exec` asks `127.0.0.1:80` for each changed website until it sees the new revision (10 s).
4. **Rollback.** If the reload fails or the probe never shows the new revision, the previous files go back, Nginx is reloaded again, and the probe must see the previous revision before the operation answers `rolled_back` ("the previous one is back and serving"). If even that cannot be confirmed, it answers `rolled_back` as **`needs_attention`**: lpa-exec now passes uncertain failures to the agent as such.

`lpa-exec` now needs `CAP_NET_BIND_SERVICE` and `AF_INET`/`AF_INET6`, because `nginx -t` binds its listeners to test them, and the probe needs `127.0.0.1`. Its unit allows IP traffic to `localhost` only (`IPAddressDeny=any`, `IPAddressAllow=localhost`); `systemd-analyze security` moves from 2.5 to 2.7 ("OK"). ([ADR-0015](0015-website-certificates-with-acme-http-01.md) later opens outbound HTTPS for the certificate authority.)

### Hand edits (drift)

The product owner's policy (2026-10-28): **check edits, and if `nginx -t` passes, reload.**

- `websites.check` (server-scoped, run hourly by the panel's scheduler and on demand) compares each website file's hash with the record. It reports `edited`, `missing` and files in the directory LPanel did not write. If it found any, it runs `nginx -t` on the live configuration and, only if that passes, reloads, so a working hand edit is served. It never rewrites a file.
- Any operation that would rewrite an edited website file (update, suspend, unsuspend, and an account suspension) answers `config_edited` and leaves it alone. The panel refuses the same up front, marks the website "edited outside LPanel", audits it (`website.drift`) and shows it to administrators.
- **Restore LPanel's version** is an administrator's `website.update` with `replace_edits: true`, audited as `website.restore`. Deleting a website removes its file whatever it holds; the administrator typed the domain.

### Operations (all privileged, mutating, idempotent; `lpa-exec` only)

| Operation | Scope | Payload |
|---|---|---|
| `website.create` | website | `account_id`, `system_user`, `domains`, `document_root`, `php`, `index`, `redirects`, `headers` |
| `website.update` | website | the same, and `replace_edits` |
| `website.suspend`, `website.unsuspend`, `website.delete` | website | `account_id`, `system_user` |
| `websites.check` | server | none |

A website's operation names its account in the payload; `lpa-exec` checks its own account record names the same account and that the website's record does too. Suspending a hosting account switches its websites to 503 as one checked change before its pool stops; deleting one with websites is refused (by the panel and by `lpa-exec`).

## Consequences

- Websites are plain HTTP until M7 adds certificates. The probe and the server blocks listen on port 80 (and `[::]:80` when the kernel has IPv6).
- A website file someone breaks by hand blocks every later change on that server: each candidate includes it, and `nginx -t` refuses. That is the correct answer (Nginx couldn't reload either), and the check reports it.
- Purging `lpa` leaves `/etc/nginx/lpanel` and the hook in place, serving: they are the customers' websites.
- Deleting a website keeps its files until the account is deleted. There is no per-website disk accounting yet.
