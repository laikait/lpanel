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
| `internal/certs` | certificates: the store, checks of key and chain, the ACME client (`golang.org/x/crypto/acme`) and the hostname's certificate |
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
| `ssl.issue` | change, background | `{username, names, authorities, zerossl_eab?}`: a certificate for the names that point here, from the first authority that issues it; stored under the account (see below) |
| `ssl.install` | change | `{username, key, chain}`: a certificate the owner brings; the key may be empty when it answers an `ssl.csr` |
| `ssl.csr` | change | `{username, names, key_type, country, state, locality, organization}`: a key kept on the server and a certificate request |
| `ssl.host_issue` | change, background | `{names, authorities, zerossl_eab?}`: the hostname's certificate, installed for the panel's ports |
| `ssl.host_install` | change | `{key, chain}`: a hostname certificate the administrator brings |
| `ssl.host_info` | read-only | the hostname certificate installed now |
| `db.apply` | change | `{username, databases, users: [{name, hash, grants: {db: [privileges] or ["ALL"]}}], hosts, suspended}`: the account's whole database state (see below) |
| `db.drop` | change | `{username, name}`: drop one database and everything in it |
| `db.rename` | change | `{username, from, to}`: move its tables to a new name |
| `db.sizes` | read-only | `{usernames?}`: each account database's size in bytes |
| `db.sso` | change | `{username, databases}`: a fresh phpMyAdmin login, `lpsso-<username>` |
| `db.sso_revoke` | change | `{username}`: remove that login |
| `db.remove` | change | `{username}`: drop every database and user of the account (also run by `account.terminate`) |
| `db.info`, `db.processes` | read-only | MariaDB's version and state; its connections |
| `db.kill` | change | `{id}`: end one of an account's connections |
| `op.status` | built in | the journalled outcome of an earlier request |

The account operations refuse (`not_ours`) any user without the comment
`lpanel account`, a uid of at least 1000 and a home of exactly
`homes_base/<name>`. Their own error codes are `exists`, `no_such_account`,
`not_ours` and `quota`.

`lpa.json` adds `homes_base` (default `/home`) and `quota`: `"on"`, or `"off"`
on a machine without quotas, which the installer writes only with
`--allow-no-quota`. `web` is the stack for customers' sites, chosen with the
installer's `--web`: `"apache"` (the default), `"nginx"`, `"hybrid"` (nginx in
front of Apache) or `"off"`.
`panel_php` (default `"83"`) is the Remi version the panel itself runs on.

### Sites (`web.apply`)

Each account has one file per server of the stack, with a site per domain:
`/etc/httpd/lpanel/sites/<user>.conf` (a VirtualHost each) for Apache and
`/etc/nginx/lpanel/sites/<user>.conf` (a server block each) for nginx. It
also has one PHP-FPM pool per PHP version its sites use,
`/etc/opt/remi/php<XY>/php-fpm.d/lpanel-<user>.conf`. A pool runs as the
account's own user, and only the web server's group may use its socket
(`apache`, or `nginx` when nginx is alone). A suspended account's sites
answer 503 and it runs no PHP at all.

| Stack | On :80 | PHP | Features | Logins | Bandwidth from |
|---|---|---|---|---|---|
| `apache` | Apache | Apache → pool | in the VirtualHost; `.htaccess` works | `/etc/httpd/lpanel/htpasswd` | Apache's logs |
| `nginx` | nginx | nginx → pool | in the server block; no `.htaccess` | `/etc/nginx/lpanel/htpasswd` | nginx's logs |
| `hybrid` | nginx: static files itself, the rest to Apache on 127.0.0.1:8080 | Apache → pool | both; `.htaccess` works | Apache's (protected folders go to Apache whole) | nginx's logs |

With nginx alone, an address with no file and no extension (`/shop/cart`)
goes to the site's `index.php`, as an app's rewrite rules would send it;
a missing file with an extension stays a 404. Hidden files are refused
(`.well-known` stays reachable), and links are followed only to files of
the link's owner (`disable_symlinks if_not_owner`). nginx has no escape for
`$`, so one in a redirect address becomes `${lpanel_dollar}`, a variable
holding it.

In front of Apache, nginx sets `X-Forwarded-For` to the visitor's address
(never appending what the visitor sent) and Apache takes it from there
(`mod_remoteip`, trusting 127.0.0.1 only). Each probe asks Apache directly
on 8080 for `X-LPanel-Revision` and nginx on :80 for `X-LPanel-Front`.

A switch of stack (the installer again, with another `--web`) re-sends
every account; the agent writes the new stack's files and removes the old
one's, without touching the server the stack no longer runs.

A change goes through these steps:

1. **Test** every candidate before anything live changes. Each new pool is
   checked with `php-fpm -t`. Apache is checked with `httpd -t` over a copy of the
   sites folder holding the candidate, chosen through
   `-C "Define LPANEL_SITES <copy>"`; `/etc/httpd/conf.d/lpanel.conf` includes
   whatever that variable names. nginx is checked with `nginx -t` over its own
   `nginx.conf` with `conf.d` replaced by a copy whose LPanel include names a
   copy of the sites folder, so a broken file anywhere in nginx's
   configuration stops the change too.
2. **Swap** the files in, keeping the old ones in memory.
3. **Reload** each PHP version involved, then Apache, then nginx. A version
   left with no pools is stopped, because PHP-FPM will not run without one.
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

### Certificates (`ssl.*`)

`lpa.json`'s `acme` names the two authorities' directories (`letsencrypt`,
`zerossl`, by default the real ones) and optionally `roots`, a PEM file of
extra roots to trust (a test authority such as Pebble). An operation only
names an authority; it never gives a URL.

`ssl.issue`:

1. **Checks each name:** it must resolve, and the site must answer a token of
   the agent's own at `http://<name>/.well-known/acme-challenge/`. A name that
   cannot be reached still counts when it resolves to an address of this
   server. A name that fails is left out, and the answer says why (`excluded`).
   The site's own name must pass, or nothing is ordered.
2. **Orders:** it makes a P-256 key and asks each authority in turn. A failed
   domain check is not retried at the other authority (it would fail there
   too). The account key for each directory is kept in `/var/lib/lpa/acme/`.
3. **Checks the chain:** it must be for the key, in order, and valid now.
4. **Stores it:** in `/etc/pki/lpanel/sites/<user>/<id>.crt` and `.key`,
   where `<id>` is the first 16 hex digits of the leaf's SHA-256.

A refusal is still a result (`issued: false`, with `error` and `attempts`),
so the panel can show the reason.

A site serves the certificate once `web.apply` names it:
`"tls": {"cert": "<id>", "force": true}`. The agent refuses (`cert_missing`)
an id that is not in the account's folder. On success it removes the
account's certificates no site names, once they are a day old. Keys of
requests (`csr-<id>.key`) are kept for 90 days. `web.remove` removes the
folder.

Every site answers `/.well-known/acme-challenge/<token>` from
`/var/www/lpanel-acme` before anything else: before a suspension, a
redirect, the www. choice or the move to HTTPS. So do the default sites, for
the hostname.

`ssl.host_issue` and `ssl.host_install` replace `/etc/lpanel/tls/panel.crt`
and `.key`. They test the panel's nginx with `nginx -t`, reload
`lpanel-nginx` (and `httpd` when Apache serves HTTPS itself), and check that
port 7443 presents the new serial. If anything fails, the old files go back
(`host_tls`).

### Databases (`db.*`)

The agent runs MariaDB's own client (`/usr/bin/mariadb --no-defaults`) as
root over `/var/lib/mysql/mysql.sock`, with SQL on standard input. Every
value is checked before it is used:

- **names:** the account's prefix (`bob_`), then `[a-z0-9_]`; at most 64
  characters for a database and 32 for a user;
- **passwords:** only as MariaDB's `mysql_native_password` hash (`*` and 40
  hex digits);
- **privileges:** from a fixed list (`ALL` is all of them, never GRANT
  OPTION or anything server-wide);
- **hosts:** `[A-Za-z0-9.:%_/-]`, and never localhost.

`db.apply`:

- creates missing databases (utf8mb4) and users, for `localhost` and each
  listed host;
- sets every hash, revokes everything and grants what the state says (with
  `_` escaped in the database name), and drops users of the account that are
  no longer listed;
- **never drops a database:** prefixed databases the state leaves out are
  reported as `stray`;
- when suspended, locks every user (`ACCOUNT LOCK`) and ends its
  connections.

`db.rename` refuses a database with views, routines, triggers or events
(`db_rename_objects`); otherwise it creates the new database with the same
character set, moves every table in one `RENAME TABLE` and drops the old
one.

`db.sso` makes or renews `lpsso-<username>`@localhost with a random password
(only its hash goes into SQL) and every privilege on the listed databases,
and returns the password.

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
