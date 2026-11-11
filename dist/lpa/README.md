# lpa: the LPanel agent

`lpa` is the only part of LPanel that runs as root. The panel runs as the
unprivileged `lpanel` user. It asks the agent to change the system through a
local Unix socket, and only through a fixed list of named operations. The agent
has no shell and no way to run "any command". The design is in
[ARCHITECTURE.md](../../design/ARCHITECTURE.md) and
[ADR-0003](../../design/adr/0003-agent-over-unix-socket.md).

## How a request is checked

Each connection carries one JSON line each way. A request goes through these
checks in order:

| Check | Refused with |
|---|---|
| The caller's uid (SO_PEERCRED) is the panel's | no answer at all |
| A version-1 request with a valid id, op and nonce, and `args` a JSON object | `bad_request` |
| Ed25519 signature by the panel's key over `SignedBytes` | `bad_signature` |
| Timestamp within 60 s of the agent's clock | `stale` |
| Nonce not seen before | `replay` |
| The operation exists | `unknown_op` |
| Fewer than `max_running` operations running | `busy` |
| The operation accepts its arguments (unknown fields refused) | `bad_args` |

The signature covers this text, with the fields joined by newlines:

```
lpa-request-v1
<id>
<op>
<ts>
<nonce>
<hex sha256 of args exactly as sent>
```

Because the agent hashes the bytes it received, the panel needs no JSON
canonicalisation. `internal/protocol/testdata/vectors.json` holds golden
vectors. The panel's PHP tests sign them and must get identical signatures.

Operations that change the system are claimed in the journal first. The
journal is `/var/lib/lpa/journal`, one file per request id. A retry with the
same id returns the first outcome and does not run again. Reusing an id for
different work is refused (`id_reused`). An entry still marked running at
start-up becomes `interrupted`. Read-only operations skip the journal.

Operations marked async answer `accepted` at once. The panel then asks
`op.status {"id": …}` for the outcome.

## Layout

| Path | What |
|---|---|
| `cmd/lpa` | the command: `serve`, `facts`, `ops`, `version` |
| `internal/protocol` | wire format, signing, golden vectors |
| `internal/server` | socket, peer check, replay cache, dispatch, journal use |
| `internal/journal` | exactly-once records |
| `internal/ops` | the operation registry and the operations |
| `internal/sysinfo` | `/proc` and `/etc` readers, tested against `testdata/root` |
| `internal/peer` | SO_PEERCRED |
| `internal/config` | `/etc/lpa/lpa.json` |
| `internal/fsutil` | atomic writes |
| `internal/accounts` | hosting accounts: users, homes, suspension; only users it made itself |
| `internal/quota` | kernel user quotas through `quotactl(2)` (ext4 and XFS) |
| `internal/web` | accounts' sites: an Apache file and PHP-FPM pools per account, through the staged pipeline below |
| `internal/sysexec` | runs `useradd`, `usermod`, `userdel`, `pkill`, `restorecon`, `httpd`, `systemctl` and Remi's `php-fpm` by absolute path, no shell, fixed environment |
| `packaging/` | the systemd unit and the default configuration |

## Operations

| Operation | Kind | Does |
|---|---|---|
| `system.ping` | read-only | answers with the agent version and time |
| `system.facts` | read-only | hostname, OS, kernel, CPUs, load, memory, uptime, disks |
| `account.create` | change | `{username, shell, quota}`: user and group, 0711 home with `public_html`, quota. Undone if any step fails |
| `account.modify` | change | `{username, shell?, quota?}` |
| `account.suspend` | change | `{username}`: expires the account (PAM then refuses every login) and ends its processes |
| `account.unsuspend` | change | `{username}`: removes the expiry |
| `account.terminate` | change, background | `{username}`: ends processes, removes the user, group, home (never following links) and mail spool. A name with no user is already gone |
| `account.info` | read-only | `{usernames?}`: LPanel accounts with their disk use and limits |
| `web.apply` | change | `{username, suspended, limited, sites: [{name, aliases, docroot, php, canonical, redirects, error_pages, protected, hotlink}], pool: {max_children}, php_settings: {"83": {directive: value}}}`: the account's whole web state. Applying the same state again changes nothing |
| `web.remove` | change | `{username}`: its Apache file and pools. Also run by `account.terminate` before the user goes |
| `web.info` | read-only | the web server, whether it runs, and each PHP version installed with its pools |
| `web.traffic` | read-only | bytes sent and received per account and day, read from the access logs (running totals; see below) |
| `php.info` | read-only | every version of the catalogue: installed, its extensions, pools, the panel's own |
| `php.install` | change, background | `{version}`: the version with the usual extensions, from Remi |
| `php.remove` | change, background | `{version}`: refused while any pool uses it (`in_use`) and for the panel's own (`panel_php`) |
| `php.extensions` | change, background | `{version, install, remove}`: extension names from the catalogue; the panel's own stay in its version |
| `op.status` | built in | the journalled outcome of an earlier request |

The account operations refuse (`not_ours`) any user without the comment
`lpanel account`, a uid of at least 1000 and a home of exactly
`homes_base/<name>`. Their own error codes are `exists`, `no_such_account`,
`not_ours` and `quota`.

`lpa.json` adds `homes_base` (default `/home`) and `quota`: `"on"`, or `"off"`
on a machine without quotas, which the installer writes only with
`--allow-no-quota`. `web` is `"apache"` (the default) or `"off"`.
`panel_php` (default `"83"`) is the Remi version the panel itself runs on.

### Sites (`web.apply`)

Each account has one Apache file, `/etc/httpd/lpanel/sites/<user>.conf`,
with a VirtualHost per site, and one PHP-FPM pool per PHP version its sites
use, `/etc/opt/remi/php<XY>/php-fpm.d/lpanel-<user>.conf`. A pool runs as the
account's own user, and only Apache's group may use its socket. A suspended
account's sites answer 503 and it runs no PHP at all.

A change goes through these steps:

1. **Test** every candidate before anything live changes. Each new pool is
   checked with `php-fpm -t`. Apache is checked with `httpd -t` over a copy of
   the sites folder holding the candidate, chosen through
   `-C "Define LPANEL_SITES <copy>"`; `/etc/httpd/conf.d/lpanel.conf` includes
   whatever that variable names.
2. **Swap** the files in, keeping the old ones in memory.
3. **Reload** each PHP version involved, then Apache. A version left with no
   pools is stopped, because PHP-FPM will not run without one.
4. **Probe.** Every site must answer on 127.0.0.1:80 with the new revision
   in its `X-LPanel-Revision` header, and every pool's socket must exist.
5. **Roll back** if the probe fails: the old files go back and the services
   are reloaded again.

Error codes: `config_rejected` (the test failed; nothing live changed),
`probe_failed` (the change was undone), `php_missing`, `docroot` (a link or
someone else's folder on the way) and `web_off`.

A site's features are written into its VirtualHost: redirects (mod_alias
`Redirect`, longest path first), the www. choice and hotlink protection
(mod_rewrite), `ErrorDocument`s, and protected folders. The protected
sections come after the site's own `<Directory>`, so its
`Require all granted` cannot undo them. Their logins go to
`/etc/httpd/lpanel/htpasswd/` (0640 root:apache, bcrypt hashes only); a
change of logins alone reloads nothing. php.ini settings go into the pool of
their version, as `php_value` or `php_flag`. `features.go` holds the rule
each value must match.

A `limited` account (its month's bandwidth used, on a package that blocks)
has its sites answer 503 and say so; its pools and settings stay.

### PHP versions (`php.*`)

The panel names a version and extensions from the agent's catalogue
(`php.go`): versions 7.4 to 8.5 and about twenty extensions. The agent makes
the package names itself, so nothing from the panel reaches `dnf` as a name
or an option. One `dnf` runs at a time; a removal also holds the sites lock,
so no pool can appear while a version goes. A new version's sample `www`
pool is emptied, as the installer does. `dnf_failed` carries dnf's last
lines (no network, a package Remi does not have for that version).

### Bandwidth (`web.traffic`)

The agent reads each `<user>.access.log` and `<user>.access.log.1` in
`/var/log/httpd/lpanel/` (the `lpanel` log format ends with `%O %I`) and
adds the bytes per account and day. It remembers how far it has read each
file by device and inode, in `/var/lib/lpa/traffic.json`, so a file that
logrotate renamed is finished, not read again; a line still being written
waits for the next call. The answer is the running total of each day (62
days are kept), so asking twice or losing an answer changes nothing.
`web.remove` deletes the account's logs and totals.

Document roots are `public_html` or folders under it. The agent makes them
by walking from the home with `O_NOFOLLOW` directory descriptors, so a link
planted by the customer is refused rather than followed. With SELinux on,
the folders it makes are relabelled.

## Build and test

You need Go 1.24 or newer.

```bash
go test ./...                 # works on Windows too: the socket tests use AF_UNIX
GOOS=linux go vet ./...
./build.sh                    # bin/lpa_linux_amd64, bin/lpa_linux_arm64
go test ./internal/protocol -update   # after changing the signing rule; then run the PHP tests
```

The end-to-end test, with the real agent and the real PHP client in an
AlmaLinux 9 container, is `tests/e2e/agent.sh` in the panel's repository.
