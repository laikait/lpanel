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
| `cmd/lpa` | the command: `serve`, `facts`, `ops`, `version` (and `files-worker`, which only the agent starts) |
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
| `internal/files` | the file manager: a worker as the account's user, inside its home (`os.Root`), and the staging folder |
| `internal/ftp` | FTP logins on Pure-FTPd (PureDB), its configuration, its certificate and its sessions |
| `internal/sshkeys` | accounts' SSH keys, in files sshd reads instead of `~/.ssh` |
| `internal/backup` | backups with restic: destinations (with their secrets and repository passwords), runs, retention, snapshots |
| `cmd/lpanel-jail` | the login shell of accounts with SSH or SFTP: runs everything inside bubblewrap (a separate binary; not part of lpa) |
| `internal/sysexec` | runs `useradd`, `usermod`, `userdel`, `pkill`, `restorecon`, `httpd`, `systemctl` and Remi's `php-fpm` by absolute path, no shell, fixed environment |
| `packaging/` | the systemd unit and the default configuration |

## Operations

| Operation | Kind | Does |
|---|---|---|
| `system.ping` | read-only | answers with the agent version and time |
| `system.facts` | read-only | hostname, OS, kernel, CPUs, load, memory, uptime, disks |
| `account.create` | change | `{username, shell, quota}`: user and group (and `lpanel-users`, when the installer has made it), 0711 home with `public_html`, quota. `shell` is `none`, `jail`, `sftp` or `bash`. Undone if any step fails |
| `account.modify` | change | `{username, shell?, quota?}`; an account made before `lpanel-users` existed joins it |
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
| `dns.apply` | change | `{username, zone, soa, records}`: a zone's whole state (`username` empty for a zone of the server's own; see below) |
| `dns.remove` | change | `{username, zone}`: delete a zone of that account (also run by `account.terminate` for every zone of it) |
| `dns.info` | read-only | PowerDNS's version and state |
| `dns.lookup` | read-only | `{hosts, zones}`: what the world's DNS says: each host's addresses and whether one is this server's (glue), each zone's nameservers at its registry |
| `mail.apply` | change | `{username, suspended, domains, limit, spam}`: an account's whole mail state (see below); answers each domain's DKIM public key |
| `mail.purge` | change | `{username, domain, local}`: delete a mailbox's mail (or, with `local` empty, a domain's) once it is no longer set up |
| `mail.settings` | change | `{smarthost, user, password, keep_password}`: the relay host for outgoing mail (`host:port`, empty for none) and its login (PLAIN over TLS only) |
| `mail.filtering` | change | `{spam, virus, reject}`: Rspamd and ClamAV on or off (virus needs spam), the score in tenths at which mail from outside is refused |
| `mail.queue_act` | change | `{action, ids}`: deliver (in the background), remove, freeze or thaw queued messages; `flush` tries them all |
| `mail.usage` | read-only | `{username}`: the bytes each mailbox holds |
| `mail.info` | read-only | Exim's, Dovecot's, Rspamd's and ClamAV's state and versions, filtering, the queue, the number of mail domains |
| `mail.queue` | read-only | `{search, limit}`: the messages in Exim's queue, oldest first |
| `mail.queue_view` | read-only | `{id}`: one queued message (up to 256 KB) and Exim's log of it |
| `mail.track` | read-only | `{username, search, status, days, limit}`: what became of each message, from Exim's log; with a username, only mail from or to its domains |
| `mail.sso` | change | `{username, address, hours}`: a webmail sign-on password for one of the account's mailboxes (Dovecot accepts it beside the mailbox's own); the same one while it has an hour left |
| `mail.sso_revoke` | change | `{username, address}`: forget a mailbox's sign-on password, or (no address) every one of the account's |
| `mail.sso_expire` | change | `{}`: forget every sign-on password past its time |
| `mail.check` | read-only | `{domains, ip, hostname, lists}`: each domain's SPF (and its result for the address), DKIM, DMARC and MX as the world's DNS has them; the address's reverse name, whether it leads back, and the blocklists |
| `files.list` | read-only | `{username, path}`: a folder's files and folders (10,000 at most), links with where they lead |
| `files.read` | read-only | `{username, path}`: a file's text and SHA-256, for the editor (4 MB at most; `binary` when it is not text) |
| `files.usage` | read-only | `{username, path}`: the disk space each thing in a folder takes, largest first |
| `files.download` | read-only | `{username, path, into}`: copy a file (2 GB at most) into a staged file the panel made |
| `files.write` | change | `{username, path, staged, expect, create}`: replace a file with staged content; refused (`files_changed`) when its SHA-256 is no longer `expect` |
| `files.upload` | change | `{username, dir, name, staged, overwrite}`: put a staged upload into a folder |
| `files.mkdir`, `files.create` | change | `{username, path}`: a folder, or an empty file |
| `files.rename` | change | `{username, from, to}`: rename or move one path |
| `files.move`, `files.copy` | change | `{username, paths, to}`: into a folder; refused whole when a name is taken there |
| `files.delete` | change | `{username, paths}`: folders with everything in them; a link, not what it leads to |
| `files.chmod` | change | `{username, paths, mode}`: the nine permission bits, not recursively |
| `files.compress` | change | `{username, paths, to}`: paths in one folder into a new `.zip`, `.tar.gz` or `.tar` |
| `files.extract` | change | `{username, path, to}`: an archive into a folder; what is there already, links, and names that would climb out are left out |
| `ftp.apply` | change | `{username, users, suspended}`: an account's whole list of FTP logins (`login` name@domain, bcrypt `hash`, `folder` from the home); sessions of logins that went or changed end |
| `ftp.settings` | change | `{tls, passive_from, passive_to, passive_ip, max_clients, max_per_ip}`: `pure-ftpd.conf`, whole; Pure-FTPd restarts, and the old file comes back if it will not start |
| `ftp.info` | read-only | Pure-FTPd's state and version, the logins, the settings applied and every session with its account |
| `ftp.kill` | change | `{pid, username?}`: end one session listed by `pure-ftpwho` (with `username`, only one of that account's) |
| `firewall.info` | read-only | firewalld's state, the zones, the open ports (and which service opens each), the allow and deny lists, the blocked countries and what is cached |
| `firewall.port` | change | `{action: open\|close, port, proto, sources?, protect?}`: open a port, limit it to some addresses, or close it |
| `firewall.lists` | change | `{allow, deny, protect?}`: the allow list and the deny list, whole |
| `isolation.info` | read-only | whether /proc hides other users' processes (and the unit that keeps it), the kernel's four link protections and their values |
| `isolation.apply` | change | `{hidepid, links}`: both settings, whole |
| `waf.info` | read-only | whether Apache has ModSecurity loaded, the Core Rule Set's version, the paranoia level |
| `waf.settings` | change | `{paranoia}`: how strict the Core Rule Set is, 1 to 4 (global; written, tested with `httpd -t`, reloaded) |
| `waf.events` | read-only | `{cursors}`: what the rules said about requests, one event per request, from each account's error log since its cursor |
| `authlog.scan` | read-only | `{cursors}`: failed sign-ins found in the SSH, FTP, mail and SMTP logs since each source's cursor, counted by address |
| `firewall.countries` | change | `{codes, refresh?, protect?}`: the countries blocked, whole; their lists are fetched when missing |
| `backup.destination` | change | `{id, type, path \| host, port, user \| endpoint, scheme, region, bucket, prefix, access_key?, secret_key?}`: checks a destination, keeps it with its secrets (empty secrets keep the ones there) and makes its repository with a new random password; a destination that does not answer is not kept |
| `backup.destination_remove` | change | `{id}`: forgets a destination and its password; what was backed up stays there |
| `backup.run` | change, background | `{destination, accounts: [{username, meta}], keep: {daily, weekly, monthly}, prune}`: one snapshot per account (home without `tmp/`, a dump of each of its databases, `meta` as `lpanel.json`), then that account's retention; one run at a time |
| `backup.status` | read-only | the run going on now: account, done, total |
| `backup.snapshots` | read-only | `{destination, username?}`: snapshots, newest first, with their account |
| `backup.files` | read-only | `{destination, snapshot, username, path}`: one folder of the account's snapshot (and, at the top, the databases it holds) |
| `backup.meta` | read-only | `{destination, snapshot}`: the snapshot's `lpanel.json`, to make its account again |
| `backup.restore` | change, background | `{destination, snapshot, username, paths, databases, folder}`: puts paths in the home (or into `restored-<time>/`) and databases back from the account's own snapshot |
| `backup.download` | change, background | `{destination, snapshot, username, path \| database}`: a `.tar.gz` (or `.sql.gz`) in the home's `lpanel-backups/` |
| `ssh.apply` | change | `{username, keys: [{type, key, comment}]}`: an account's whole list of SSH public keys, written to `/etc/ssh/lpanel/keys/<account>`; an empty list removes the file |
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

### DNS (`dns.*`)

`lpa.json`'s `dns` names PowerDNS's API (`http://127.0.0.1:8081`, only on
this machine) and the file holding its key (`/etc/lpa/pdns.key`, root's
alone). Without `api` the DNS operations answer `dns_off`, and terminating
an account still works. `resolver` (host:port) sends the agent's own
lookups to another server than the system's.

`dns.apply` checks every record itself before anything reaches PowerDNS:

- **names:** the zone or a name under it (`*` only first, `_` allowed);
- **types:** A, AAAA, CNAME, MX, TXT, SRV, CAA, NS, PTR; never SOA (the
  agent writes it from `soa`), nor anything PowerDNS would run (LUA);
- **contents:** addresses, host names, priorities, ports and CAA tags. TXT
  text is quoted by the agent, with `"` and `\` escaped and every byte
  outside printable ASCII as `\DDD`, in strings of 255 bytes;
- **the zone's rules:** a CNAME stands alone and never at the top, the zone
  names its own nameservers, TTLs are 60 seconds to a week.

It then changes only the record sets that differ, so the same state again
changes nothing. When something changes, the SOA serial becomes today's
date and a count (`2026111501`). Record types the agent does not manage are
left alone.

Each zone carries its account in PowerDNS (`account`). A zone is changed or
removed only for that account; a zone of the server's own has none.

Wildcard certificates (`*.example.com`) are checked by DNS-01. Before
ordering, the agent puts a random record in the account's zone and looks it
up the way anyone would: if that does not come back, the domain's
nameservers at its registry are not this server's, and the wildcard is left
out with that reason. During the order it adds the authority's TXT value
beside the owner's own records at `_acme-challenge.<name>` (a `dns.apply`
meanwhile keeps it), and takes it away afterwards.

### Mail (`mail.*`)

With `lpa.json` `"mail": "on"` (the installer sets it), the agent writes the
data Exim and Dovecot read; their configurations are the installer's. Each
domain of the state has:

- `name`, `local` (false: its MX is elsewhere; mail to it is sent there,
  while its mailboxes still log in and send), `catchall` (empty or
  `:fail:` refuses, `:blackhole:` discards, or an address);
- `mailboxes`: `local`, `hash` (bcrypt only), `quota_mb` (0: none of its
  own), and an optional `autoresponder` (`subject`, `body`, `from_name`,
  `days` 1–30, `start` and `end` as YYYY-MM-DD);
- `forwarders`: `local` and `to` (1–50 addresses).

Local parts are lowercase letters, digits and `. _ -` (never `+`, which
separates a tag). Forwarding addresses come from a narrow alphabet that
can never read as a file, a pipe or a second address. A domain written for
another account is refused (`mail_not_owner`). Domains the account no longer
has lose their files; their mail stays in the home until `mail.purge`.
Autoresponders are compiled with `sievec`. A suspended account's mailboxes
get `nologin`, and Exim refuses their mail from the owner file. The
hostname certificate is copied to `/etc/pki/lpanel-mail` at start and
after each change, reloading Exim and Dovecot.

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

M6b adds to each account's state a sending limit (`limit`, recipients an
hour; written to `/etc/exim/lpanel/accounts/<account>`, which Exim's
ratelimit reads) and its spam settings (`spam`: `junk` and `delete` scores
in tenths, `allow` and `block` senders), and to each mailbox its `filters`
(`field` from/to/subject, `match` contains/is/begins/ends, `value`, `action`
folder/forward/discard, `target`, `stop`). A mailbox's Sieve script holds,
in order: blocked senders (discarded), spam filing by `X-LPanel-Spam-Score`
(unless the sender is allowed), its filters, its autoresponder. No script is
written for a mailbox with nothing to do.

M6c adds to each mail domain the certificate of its site (`cert`, its id in
the certificate store): when it covers `mail.<domain>` and is valid, the
agent copies it to `/etc/pki/lpanel-mail/sni/mail.<domain>/` (Exim finds it
by SNI) and lists it in Dovecot's `/etc/dovecot/lpanel/sni.conf` (then
reloads Dovecot). Webmail's sign-on passwords live in
`/etc/dovecot/lpanel/sso.d/<domain>` (salted SHA-256, Dovecot's second
passdb) and `sso.keep/<domain>` (root's alone); every domain has the first,
empty when nobody signed on. `web.apply` takes `mail_setup` and `webmail`
for each site and `webmail_url`: the site passes mail clients' setup
requests to the panel (`127.0.0.1:7449/mail-setup/`) and sends its webmail
names to webmail, before HTTPS, a suspension and the owner's own rules.

### Files (`files.*`)

M7a's file operations never touch an account's files as root. The agent
starts its own binary as the account's user (`lpa files-worker`: that uid,
its group only, a fixed environment, umask 022, killed with the agent),
sends it the request on standard input and reads one reply. The worker opens
the home as an `os.Root`, so every path resolves beneath it: a link that
leads out (or is absolute) is refused with `files_outside`, wherever it is
in the path, however it was swapped in. The account's own permissions and
disk quota then apply to everything the worker does.

Contents never travel in a request. The panel puts uploads and saved files
in its staging folder (`lpa.json` `files_staging`, default
`/var/lib/lpanel/files`, 0700 lpanel) under 64 hex digits, and makes an empty
one for a download. The agent opens that name without following links and
takes only a plain file with one link that root does not own, then hands the
worker the open descriptor (3 to read, 4 to write); the worker never sees the
folder.

Saving writes a temporary file beside the target and renames it over, so a
file is either the old one or the whole new one (through a link, or with
more than one hard link, it is written in place). `public_html`, `mail`,
`tmp` and `logs` at the top of the home are the server's: their contents
are the owner's, but they cannot be deleted, renamed, moved or chmod'ed.
Archives unpack at most 8 GB and 200,000 entries; links, devices and names
with `..` are left out, and no entry keeps setuid bits. With SELinux on,
what is moved into one of those folders is relabelled. The error codes are
`files_missing`, `files_exists`, `files_denied`, `files_quota`,
`files_outside`, `files_type`, `files_too_big`, `files_binary`,
`files_changed`, `files_protected`, `files_archive`, `files_staged` and
`files_into`.

### FTP (`ftp.*`)

M7b's FTP logins are Pure-FTPd virtual users (`lpa.json` `"ftp": "on"`,
which the installer writes). Each works as its account's Unix user, shut in
a folder of the home (`/./` in Pure-FTPd's file), so the account's
permissions and disk quota hold as for its PHP. The agent keeps one file
per account in `/etc/pure-ftpd/lpanel/users.d/<account>` (0600 root: the
logins, their bcrypt hashes and folders), joins them into
`/etc/pure-ftpd/lpanel/passwd` and has `pure-pw mkdb` index it into
`pureftpd.pdb`, which Pure-FTPd reads at each login: nothing reloads, and
sessions of other logins go on. A login named in another account's file is
refused (`ftp_taken`); a folder is made when missing, as the account's,
through an `os.Root` at the home (a link out is refused). A suspended
account's file is empty. The sessions of logins that went, or whose password
or folder changed, end at once; `RemoveFor` (the last of the terminate
chain) removes the file and ends the account's sessions.

`pure-ftpd.conf` is the agent's (`ftp.settings`): everyone shut in their
folder, PureDB's logins alone (no anonymous, no PAM, no system users), uid
1000 and up, files 644 and folders 755, the passive ports (and the address
given out behind NAT), the client limits, and TLS: `optional` (1),
`required` (2: logins only over TLS) or `strict` (3). The hostname
certificate is copied, key first, into `/etc/pki/pure-ftpd/pure-ftpd.pem`
(0600) whenever it changes (`certs.Manager.AfterHost`), and Pure-FTPd
restarts, since it reads it at start. Sessions come from `pure-ftpwho -s`;
`ftp.kill` signals only a process in that list. The error codes are
`ftp_off`, `ftp_taken` and `ftp_no_session`.

### Backups (`backup.*`)

M8's backups are restic repositories, one per destination: a local folder
(not in `/home`, `/etc` or another system folder), an SFTP server, an
S3-compatible store (`s3:https://endpoint/bucket/prefix`) or Backblaze B2.
Everything that opens a destination is the agent's, in `/etc/lpa/backup`
(0700 root): `destinations/<id>.json` with the credentials,
`destinations/<id>.pass` with the repository's password (64 hex characters,
made once when the destination is first added and never changed), and
`id_ed25519` for SFTP (one key for every SFTP destination; the answer gives
its public half to authorize). The panel sends secrets once and never gets
them back. Root keeps a copy of a password for disaster recovery with
`lpa backup-password <id>`; no operation returns it.

restic runs from `/usr/bin/restic` with an environment the agent builds
(the repository, the password file, S3 or B2 keys, a cache in
`/var/cache/lpa/restic`), never through a shell; secrets are in the
environment, not the arguments. `backup.destination` asks `restic cat config`
first: exit 10 (no repository) makes one, 12 (wrong password) is
`backup_wrong_password` and the destination is not kept.

`backup.run` runs in the background (the panel follows it with
`op.status`) and backs each account up in turn: `mariadb-dump
--single-transaction` of each database with the account's prefix (as the
agent lists them, whatever the panel says) and the panel's description into
`/var/lib/lpa/backup/<account>/` (0700, removed afterwards), then
`restic backup --host lpanel --tag lpanel --tag account:<name>` of the home
(without `tmp/`) and that folder, then `restic forget` of that account's
snapshots beyond the retention. restic's exit 3 (some files unreadable) is a
warning; anything else fails that account and the next one goes on. `prune`
frees space at the end. A second run, or a destination change during a run,
is `backup_busy`. The error codes are `backup_off` (no restic),
`backup_no_destination`, `backup_unreachable`, `backup_wrong_password` and
`backup_busy`.

`backup.destination` may bring `password`: the repository password another
server made (its `lpa backup-password`), for a destination added now. It
must open the repository there (`backup_wrong_password` otherwise), and a
destination that does not work leaves no password behind.

Restores (M8b) take an account and one of its snapshots; the snapshot's
`account:` tag must be that account (`backup_not_in_snapshot` otherwise), so
an account is only ever given its own data. restic restores what was asked
(`--include` of the paths in the snapshot's home, which may be another
server's) into `/var/lib/lpa/restore/<account>-*/` (0700 root, removed
afterwards), never into a home. The files are then copied into the home
through an `os.Root` opened on it: a link out of the home is never followed,
a link met where a folder goes is replaced by the folder, and each file is
written under a temporary name and renamed over the old one (a hard link the
owner made is replaced, not written through), as the owner's, with its mode
(no set-id bits) and time. Files made since stay. With SELinux, `restorecon`
labels what was put back. A database comes back by loading its dump from the
snapshot (`restic dump`) into `mariadb` as root; only the account's own
(by prefix). One restore or download at a time (`backup_busy`).
`backup.download` writes into `lpanel-backups/` (0700, the owner's) the same
way.

### Firewall (`firewall.*`)

M9a manages firewalld (lpa.json `"firewall": "on"`, set by the installer when
firewalld runs). What the panel keeps in `/var/lib/lpa/firewall/state.json`
(the lists and countries) the agent turns into six firewalld IP sets
(`/etc/firewalld/ipsets/lpanel-{allow,deny,cc}[6].xml`, one per family) and
six rich rules in the default zone and every active zone, which point at them
with priorities: the allow list accepts (-300), the deny list drops (-200),
the blocked countries drop (-100); all run before the zone's port rules. The
files are rewritten whole, firewalld is reloaded only when one changed, so
the same call twice does nothing.

Ports are changed in the default zone (permanent, then `--reload`). Services
the installer opened (`ssh`, `http`...) are read for their ports; changing
one of a service's ports takes the service out and opens its other ports
one by one, so nothing else is lost. A limited port is a rich rule per
address (IPv4 and IPv6) instead of the plain port.

Guards: ports 22, 7443 and 7444 (`Keep`) can be limited to addresses but
never closed. Every list entry is checked (a network no wider than /8 for
IPv4 and /16 for IPv6; never the loopback, never `0.0.0.0/0`). `protect`
names addresses that must stay reachable (the administrator's own, from the
panel): a deny list or a blocked country that holds one (unless the allow
list names it), or a limit on a kept port that leaves it out, is refused
with `firewall_lockout`. Country lists come from ipdeny.com (HTTPS only, that
host only, at most 4 MB, every line a network of the right family, or the
list is not kept); a country that cannot be fetched changes nothing. Error
codes: `firewall_off`, `firewall_kept`, `firewall_lockout`, `firewall_fetch`,
`firewall_toomany`.

### Account isolation (`isolation.*`)

Accounts are already apart by user and group, each with a home that can be
passed through but not listed, a site folder private to the account and the web
server (0750), a PHP pool of their own with sessions, uploads and temporary
files in the account's folder (and `TMP`, `TMPDIR`, `TEMP` for programs a
script starts), links followed only to files the same owner made
(`SymLinksIfOwnerMatch`, nginx `disable_symlinks if_not_owner`) and, for SSH, a
jail with its own processes and `/tmp`. M9d adds the two things the accounts
still share, the system's `/proc` and the kernel's rules for links:

- **hidepid**: `/proc` is remounted `hidepid=2,gid=0`: a process of an account
  sees only its own, not other command lines (which may hold passwords); root
  sees all. `lpanel-hidepid.service` (a oneshot with `ExecStop` back to 0)
  makes it survive a reboot; `mount` is the only tool added for it. Turning it
  off removes the unit and remounts `hidepid=0`.
- **links**: `/etc/sysctl.d/90-lpanel-isolation.conf` sets
  `fs.protected_symlinks=1`, `protected_hardlinks=1`, `protected_regular=2`,
  `protected_fifos=2` and is loaded with `sysctl -p`. Turning it off removes the
  file and sets the distribution's values (1, 1, 2, 1) again at once.

`isolation.info` reports the state the kernel has now (`hidepid` and the mode
the mount shows, `links_ok` when each value is at least as strict as wanted)
and whether LPanel's files are there. Error codes: `isolation_proc` (the mount
failed), `isolation_sysctl`.

### Web application firewall (`waf.*`, a site's `waf` in `web.apply`)

M9c puts ModSecurity with the OWASP Core Rule Set in front of each site. It
lives in Apache (the `mod_security` and `mod_security_crs` packages, installed
by the installer), so it covers the apache stack and the hybrid stack's dynamic
requests; nginx alone has none, and says so (`waf.info` `available: false`).

- **Per site** (`web.apply`, `sites[].waf = {mode, exclude}`): `off`,
  `detect` (the rules run and log, nothing is refused) or `block` (an anomaly
  score of 5 or more gets a 403), and up to 50 rule numbers the site does not
  want (`SecRuleRemoveById`). A site with no `waf` is `detect`, never the
  package's own default (blocking), so every mode in force is one somebody
  chose. In `detect` and `block` the request body limit is 512 MB with
  `ProcessPartial`, so a large upload is not refused for its size.
- **Global** (`waf.settings`): the paranoia level, written to
  `/etc/httpd/lpanel/waf.conf` as a `SecAction` that the main include reads
  before the rule set loads. Apache's `-t` must accept it, or the old file is
  put back (`waf_config`).
- **Events** (`waf.events`): the account's `*.error.log` carries one line per
  matching rule (`ModSecurity: Warning/Access denied … [id "941100"] [msg …]
  [unique_id …]`); lines with the same unique id are one request, the
  anomaly-score summary rule (949110) gives the score and whether it was
  refused. A log never read starts at its end; a replaced log is read from its
  top; at most 8 MB per log and 500 events per call.

### Sign-in logs (`authlog.scan`)

M9b's brute-force blocker needs to know who keeps failing. The agent only
reads and reports; it blocks nothing and keeps nothing. `authlog.scan` reads
four logs from the place the panel says it stopped (a cursor per source: a
position and a hash of the file's first line, so a rotated or replaced log is
read from its top) and answers the failures per address and service:

| Source | Log | Line |
|---|---|---|
| `ssh` | `/var/log/secure` | sshd `Failed password/publickey/keyboard-interactive for … from IP` |
| `ftp` | `/var/log/messages` | pure-ftpd `(user@IP) [WARNING] Authentication failed` |
| `mail` | `/var/log/maillog` | Dovecot `…-login: …auth failed, N attempts…rip=IP` (counts N) |
| `smtp` | `/var/log/exim/mainlog` | Exim `login authenticator failed for … [IP]` |

A source never read before starts at the end of its log, so history never
blocks anyone when the feature is turned on. A log that is not there is listed
under `missing`. At most 8 MB of one log is read per scan; a line still being
written is left for the next. Loopback and unspecified addresses are never
reported.

### SSH and SFTP (`ssh.apply`, `cmd/lpanel-jail`)

M7c's shell access is jailed. An account's login shell is one of
`/sbin/nologin` (`none`), `/usr/libexec/lpanel/jail-shell` (`jail`: a shell,
commands and SFTP) or `/usr/libexec/lpanel/jail-sftp` (`sftp`: SFTP only);
`bash` stays possible from the API but the panel never gives it out. Both
jail names are the same small static binary (`cmd/lpanel-jail`); the name it
was started under decides. sshd starts it as the account, for a terminal
(`-jail-shell`), a command (`-c …`) or SFTP (`-c /usr/libexec/openssh/sftp-server`,
which is why the installer makes sure SFTP is that program, not sshd's
`internal-sftp`). It replaces itself with bubblewrap, which builds a world
of the account's own: its home at its usual path (no other home), the
home's `tmp/` as `/tmp`, `/usr` and Remi's `/opt/remi` read-only, a short
list of `/etc` read-only, an `/etc/passwd` and `/etc/group` naming only
root, nobody and the account, its own PID namespace and `/proc`, IPC of its
own, MariaDB's socket, and a read-only root. `no_new_privs` keeps setuid
programs from working. The environment is rebuilt (no `LD_PRELOAD`,
`BASH_ENV` or agent socket comes in). If bubblewrap cannot start, the login
fails; it never falls back to an unjailed shell.

Keys (`ssh.apply`, `internal/sshkeys`): the panel sends an account's whole
list; each key is parsed (`golang.org/x/crypto/ssh`), DSA and RSA under
2048 bits are refused, a key may not carry options or a second line, and
each is written as `restrict,pty <type> <key> <comment>` to
`/etc/ssh/lpanel/keys/<account>` (root, the account's group, 0640: sshd
reads it as the account). The installer's `sshd_config.d/40-lpanel.conf`
matches the `lpanel-users` group, which every account is in, and reads keys
from there only (never `~/.ssh`), with passwords, keyboard-interactive,
`~/.ssh/rc` and every kind of forwarding off. Suspension needs nothing
here: an expired account cannot log in with any key. `RemoveFor` (in the
terminate chain) removes the file.
