# LPanel architecture

LPanel is installed on each hosting server and manages only that server. It is
made of two programs and a small helper:

| Part | Language | Runs as | Job |
|---|---|---|---|
| **Panel** (this repo) | PHP 8.3 on LPHP | `lpanel` (unprivileged) | Web UI, REST API, CLI, its own database, decisions and permissions |
| **Agent** (`lpa`, in `dist/lpa/`) | Go | root | The only thing that changes the system: users, configs, services, files |
| **authd** (`lpanel-authd`) | Go | root, socket-activated | Checks the root password through PAM; nothing else |

```
 Browser / API client / billing system
        │ HTTPS :7443 (Admin panel)  :7444 (User panel, /webmail/, /phpmyadmin/)
        ▼
 ┌──────────────────────────────┐
 │ panel nginx (own instance)   │   separate from the customers' web server
 │ panel PHP-FPM (user lpanel)  │
 │   LPHP modules               │──── MariaDB "lpanel" schema (panel state)
 └──────────┬───────────────────┘
            │ Unix socket /run/lpa/lpa.sock
            │ (SO_PEERCRED = lpanel uid, every request signed)
            ▼
 ┌──────────────────────────────┐        ┌──────────────────────────────┐
 │ lpa (root)                   │        │ lpanel-authd (root)           │
 │ operation registry + journal │        │ PAM "lpanel" service          │
 └──────────┬───────────────────┘        └──────────────────────────────┘
            ▼
 Apache / Nginx · PHP-FPM pools · MariaDB · PowerDNS · Exim · Dovecot · Rspamd
 ClamAV · Pure-FTPd · restic · firewalld · ModSecurity · quotas · systemd
```

## Rules

1. **The panel decides, the agent does.** The panel checks who is asking and
   whether they're allowed. It then sends the agent a named operation with
   typed arguments, such as `account.create` or `domain.add`. The agent checks
   the arguments again, because it does not trust the panel to have done so.
2. **The agent has no shell API.** It only runs operations from a fixed
   registry. Each operation declares its arguments, timeout and whether it is
   safe to retry.
3. **Exactly once.** Every operation carries an idempotency key. The agent
   journals it, so a retry after a crash or timeout does not repeat the work.
4. **Configs are generated, staged and tested.** Every config goes through the
   same steps: render → write to a staging path → syntax-test the candidate →
   swap → reload → probe → roll back if the probe fails. Edits made by hand are
   detected and reported, never silently overwritten.
5. **Panel state lives in the panel's database. System state lives on the
   system.** A scheduled reconcile job compares the two and reports drift.
6. **Customer isolation by default.** Each account is a Linux user with its
   own PHP-FPM pool. Home folders are 0711, `/proc` is mounted with `hidepid`,
   each account gets a private `/tmp`, and kernel quotas apply.

## Panel (this repo)

The panel is built on LPHP 3.2.0 modules, with one module per feature area
under `modules/`. The framework in `engine/` is not edited.

| Module | Covers |
|---|---|
| `Identity` | users, tiers and their roles, sessions and session epochs, 2FA, API tokens, "log in as" |
| `Audit` | audit log |
| `Agent` | socket client, request signing |
| `Packages` | packages: limits and feature lists (the `feature.*` capabilities) |
| `Accounts` | accounts and their agent operations (the `Provisioner`), quotas, bandwidth |
| `Resellers` | resellers: allowance, overselling, ACL; later nameservers and branding |
| `Subusers` | sub-users and their grants; the gate on `feature.*` |
| `Web` | domains (main, addon, sub, alias), each site's folder and PHP version, keeping Apache and PHP-FPM in step (`WebSync`); later redirects and error pages |
| `Ssl` | AutoSSL, certificates |
| `Databases` | MariaDB DBs/users, phpMyAdmin SSO |
| `Dns` | PowerDNS zones and templates |
| `Mail` | mail domains (every domain of an account), mailboxes, forwarders, catch-alls, autoresponders, filters, spam settings, DKIM; the server's filtering, relay host, queue and delivery tracking |
| `Files` | file manager, FTP, SSH keys |
| `Backups` | schedules, destinations, restore |
| `Security` | firewall, brute force, ModSecurity |
| `Monitor` | dashboard, services, stats, alerts |
| `System` | updates, settings, license |

There are two interfaces over one set of routes and one API:

| Interface | Port | Who |
|---|---|---|
| **Admin panel** | :7443 | Root admins and panel admins see everything. Resellers see what their ACL allows, with their own branding. |
| **User panel** | :7444 | Account owners see their account. Sub-users see what the owner granted. |

Webmail (Roundcube) is served at `/webmail/` on the User panel's port, as
phpMyAdmin is at `/phpmyadmin/`: one port fewer to open and to give a
certificate. Each runs in a PHP-FPM pool of its own, as its own user.
Customers' sites send `webmail.<domain>` there, and pass mail clients'
setup requests (autoconfig, autodiscover) to the panel's nginx on
127.0.0.1:7449, which serves only `/mail-setup/`.

## Agent (`lpa`)

The agent's source is in this repo at [`dist/lpa/`](../dist/lpa/README.md), as
its own Go module whose one dependency is the Go project's own
`golang.org/x/crypto` (for its ACME client, `x/crypto/acme`). It builds to a single
static binary, run by systemd as `lpa serve`, listening on `/run/lpa/lpa.sock`.
Its README covers the wire format, the checks in order, the journal and the
operation list.

Each subsystem is a package under `internal/` (so far `sysinfo`, `accounts`,
`quota`, `web`, `certs`, `mysql` and `dns`; later `mail`, `ftp`, `backup`,
`firewall`), registered as operations in `internal/ops`. Each reads the system
through paths it is given, so tests run against fixture files without root.
Operations that need the system's own tools (`useradd`, `usermod`, `userdel`,
`pkill`) run them through `internal/sysexec`: a fixed list of absolute paths,
arguments as a list, never a shell, and a fixed minimal environment.

Where a subsystem's state is small, the panel sends all of it at once
instead of step by step. `web.apply` takes an account's whole web state, so
applying it twice is harmless and a lost change is fixed by sending the
current state again. `Web\WebSync` raises a revision for each change and
records the state with its operation id before sending it, and `web:settle`
resends what the agent has not answered.

The panel never calls an operation that changes the system and forgets it.
`Accounts\Provisioner` writes the operation, its arguments and a new id to the
account's row first, then sends it. An answer it never received is asked for
again by `accounts:settle` (every minute) with the same id, and the agent's
journal returns the first outcome. The same pattern is used for every later
subsystem.

The panel's side is the `Agent` module. It provides `AgentClient`, the signing
key and `agent:*` commands. `tests/Unit/AgentProtocolTest.php` signs the Go
golden vectors, and `tests/e2e/agent.sh` runs both halves together in
AlmaLinux 9.

Databases (M4) follow them too. The agent drives MariaDB with its own
client, as root over the local socket (unix_socket authentication, so there
is no root password to keep). SQL goes on standard input and is built only
from checked values: names with the account's prefix in a fixed alphabet,
password hashes (the panel never sends a password), privileges from a fixed
list, and hosts in a narrow alphabet. `db.apply` takes an account's whole
state, like `web.apply`, but never drops a database: only an explicit
`db.drop` does. phpMyAdmin runs in a PHP-FPM pool of its own as
`lpanel-pma`, and is signed in to with a one-time ticket the panel writes
for a login the agent makes (`db.sso`).

DNS (M5) follows them too. PowerDNS keeps zones in its own `pdns`
database, and the agent writes them through its HTTP API, which listens on
127.0.0.1 with a key only root holds. `dns.apply` takes a zone's whole
state, checks every record itself, and changes only what differs. The
panel keeps the records the owner sees; a zone's own NS records and its SOA
come from the nameservers of whoever the zone belongs to (the account's
reseller, or the server), so changing nameservers changes every zone at
once. For a wildcard certificate, the agent answers the DNS-01 check in the
account's own zone.

Mail (M6) follows them too, with one difference: Exim and Dovecot run from
configurations the installer writes once, and the agent writes only their
data, one file a domain, which both read on every lookup. So no mail change
reloads anything. `mail.apply` takes an account's whole mail state:

- `/etc/exim/lpanel/{owners,local,mailboxes,forwards,dkim}/<domain>`:
  which account a domain belongs to (and whether it is suspended), whether
  its mail is delivered here, its mailboxes, its forwarders and catch-all,
  and its DKIM private key (made by the agent when missing);
- `/etc/dovecot/lpanel/passwd.d/<domain>`: each mailbox's bcrypt hash, the
  account's uid and gid, its home and quota;
- `/etc/dovecot/lpanel/sieve/<domain>/<local>.sieve`: what the mailbox does
  on arrival (the account's blocked senders, its spam filing, the mailbox's
  filters, its autoresponder), compiled by the agent, readable by the
  account's group only;
- `/etc/exim/lpanel/accounts/<account>`: the account's sending limit, which
  Exim's ratelimit applies to its mailboxes and its sites together.

Server-wide, `mail.filtering` turns Rspamd and ClamAV on or off (the
`filter` flag file tells Exim to ask Rspamd; the agent writes Rspamd's
`actions.conf` and `antivirus.conf`), and `mail.settings` writes the relay
host and its login. The queue (`mail.queue`, `mail.queue_view`,
`mail.queue_act`) and delivery tracking (`mail.track`, which reads Exim's
log and gives an account only its own domains' mail) are read through the
agent too; the panel never reads Exim's spool or logs itself.

Webmail's single sign-on (`mail.sso`) gives a random password for a
mailbox, good for a few hours and the same while it is good; Dovecot
accepts it beside the mailbox's own (a second passdb,
`/etc/dovecot/lpanel/sso.d/<domain>`, salted SHA-256; the passwords
themselves in `sso.keep/`, root's alone). Exim checks logins through
Dovecot, so webmail sends as the mailbox too. The panel hands the password
to Roundcube in a one-time ticket, as it does for phpMyAdmin; `mail.apply`
drops it when the mailbox goes or the account is suspended, and
`mail.sso_expire` when its time is up.

Each mail domain's site answers to `mail.`, `autoconfig.`, `autodiscover.`
and `webmail.<domain>` (the Mail module adds them through the `web.state`
filter, and their records to the zone), so AutoSSL puts them in the site's
certificate. When that certificate covers `mail.<domain>`, `mail.apply`
names it and the agent copies it to `/etc/pki/lpanel-mail/sni/` for Exim
(which finds it by SNI with dsearch on each connection) and writes
Dovecot's `local_name` blocks (`sni.conf`, then a reload). The Web module's
`web.applied` hook brings a renewed certificate to mail. `mail.check` asks
the world's DNS for the deliverability page: each domain's SPF (evaluated
for the server's address), DKIM, DMARC and MX, and the address's reverse
name and blocklists.

The file manager (M7a) is the one place the agent works on customers' own
files, and it does not do that as root. Each `files.*` operation starts the
agent's own binary as the account's user (`lpa files-worker`: its uid and
group only, a fixed environment), which opens the home as an `os.Root`, so
every path resolves beneath it and a link out of it is refused. The kernel
applies the account's permissions and quota, exactly as to its own PHP or
SSH. File contents never go in a request (they are capped at 64 KB, signed
and journalled): the panel stages uploads and saved files in
`/var/lib/lpanel/files` under random names, the agent opens one by name
(no links, one hard link, not root's) and passes the worker the open
descriptor. Downloads come back the same way into a file the panel made.

FTP (M7b) is Pure-FTPd with virtual users only: each login (`name@domain`)
works as its account's Unix user, shut in a folder of the account's home, so
the same permissions and quota hold as for its PHP and file manager. The
panel sends an account's whole list of logins (`ftp.apply`, bcrypt hashes
only); the agent keeps a file per account, joins them and has `pure-pw`
index them, and Pure-FTPd reads that index at each login, so nothing is
reloaded. `pure-ftpd.conf` is written whole by the agent from the Admin
panel's settings (TLS for logins by default, passive ports, client limits),
and Pure-FTPd serves the hostname certificate, copied whenever it changes.

Exim finds a domain's files with `dsearch`, which answers only with a name
that is in the folder, so a name from a message never becomes part of a
path. Exim hands local mail to Dovecot over LMTP and checks logins through
Dovecot's auth socket. Mail is kept in the account's home
(`~/mail/<domain>/<local>/Maildir`), written as the account's user, so it
counts against the account's disk space. `mail.apply` never deletes mail:
`mail.purge` does, once the mailbox is no longer set up. The DKIM public key
comes back in the answer; the panel publishes it, with a DMARC policy, in
the account's zone through the DNS module's "provided records", which the
owner may still edit or remove.

Certificates (M3) follow the same lines. The agent makes every key itself
and keeps it. `ssl.issue`:

1. checks which names point at this server;
2. asks the certificate authorities named in lpa.json (never by the panel),
   answering their HTTP-01 checks from `/var/www/lpanel-acme`, which every
   site serves before its own rules;
3. checks the chain against the key;
4. stores it under the account, named by its id.

Nothing served changes until `web.apply` names that id. Then the site gets
HTTPS: a `:443` VirtualHost with Apache alone, or `listen 443 ssl` in nginx's
server block, alone or in front. The change is tested like any other, and
probed over HTTPS as well.

In the panel:

- `Web\AutoSsl` records each order before sending it (`ssl_orders`);
- `ssl:autossl` (every five minutes) settles those orders and orders for
  whatever is due;
- `Web\SslSites` holds each site's certificate id and the owner's choices,
  which `WebSync` adds to the state it sends.

The hostname's certificate replaces the panel's own (`/etc/lpanel/tls/`). It
is tested with the panel's nginx and probed on 7443, and the old one goes
back if anything fails.

Code from the earlier attempt was reviewed and reused where it fitted:

- `sysinfo`, `fsutil` and the SO_PEERCRED check (M0b);
- authd (M0c, from this repo's git history at `ee0adbd`);
- the staged config pipeline and renderers (rewritten in `internal/web`, M2);
- the ACME client (rewritten in `internal/certs`, M3).

## The panel's own PHP

On EL9 the panel runs on Remi's `php83`, installed under `/opt/remi/php83`,
beside whatever PHP versions customers' sites use. AppStream's PHP has no
sodium extension on EL9, and the panel signs agent requests with it. Keeping
the panel's PHP separate also means a customer-facing PHP upgrade never
touches the panel.

## SELinux

LPanel runs with SELinux enforcing. Its policy module is in
[`dist/selinux/`](../dist/selinux/lpanel.te), and the installer loads it
whenever SELinux is on.

| Process | Domain |
|---|---|
| the panel's nginx and PHP-FPM | `httpd_t` (their binaries are `httpd_exec_t`) |
| customers' Apache and PHP-FPM pools | `httpd_t` too |
| `lpa` | `lpanel_agent_t`, unconfined |
| `lpanel-authd` | `lpanel_authd_t`, unconfined |
| the scheduler and `lpanel` commands (php83 CLI) | `unconfined_service_t` / the caller's |

`httpd_t` may connect to `lpanel_agent_t` and `lpanel_authd_t` only, through
`lpanel_sock_t` sockets. That is the reason the two root programs have
domains of their own: the panel can reach them without being allowed to
reach every unconfined service. The module also labels the panel's paths
(see the table below).

`dist/selinux/setup.sh` holds every setting, and the installer and the build
check both run it:
- the module;
- the two panel ports;
- accounts' `public_html`, `tmp` and `logs` as `httpd_user_rw_content_t`,
  because sites write uploads and caches and PHP keeps sessions there;
- the booleans sites need: `httpd_enable_homedirs`,
  `httpd_can_network_connect`, `_connect_db` and `httpd_can_sendmail`.

The agent runs `restorecon` on each new home and on each folder it makes for
a site. Between accounts, isolation comes from each pool running as its own
user, not from SELinux.

## Layout on an AlmaLinux 9 server

| Path | What |
|---|---|
| `/usr/local/lpanel/` | panel code, root-owned and read-only to the panel |
| `/usr/local/lpanel/.env` | panel settings and secrets (APP_KEY, database password), 0640 root:lpanel |
| `/usr/local/lpanel/system/` | the only part of the code tree the panel writes (cache, logs, sessions) |
| `/etc/lpanel/agent.key` | the panel's signing key for agent requests, 0600 lpanel |
| `/etc/lpanel/tls/` | the hostname's certificate and key, used for the panel's ports and, with Apache alone, the default HTTPS site (key 0600 root; self-signed until AutoSSL gets one) |
| `/etc/pki/lpanel/sites/<user>/<id>.crt`, `.key` | each site's certificate (chain, leaf first) and key, named by the certificate's id, 0700/0600 root (`cert_t`); `csr-<id>.key` keeps a request's key until its certificate comes |
| `/var/www/lpanel-acme/` | HTTP-01 answers while a certificate is being ordered, served by every site at `/.well-known/acme-challenge/` (root's, 0755) |
| `/var/lib/lpa/acme/account-<hash>.json` | the server's account key with each certificate authority (0600 root) |
| `/etc/lpanel/php-fpm.conf` | the panel's own PHP-FPM master (`lpanel-php-fpm.service`): the `panel` pool (as `lpanel`) and the `pma` pool (as `lpanel-pma`) |
| `/usr/local/lpanel-pma/phpmyadmin/` | phpMyAdmin, root-owned and read-only; `config.inc.php` 0640 root:lpanel-pma; `lpanel-signon.php` takes the panel's tickets |
| `/var/lib/lpanel-pma/tickets/` | one-time sign-on tickets: written by the panel, read and deleted by phpMyAdmin (2770 lpanel:lpanel-pma) |
| `/var/lib/lpanel-pma/{sessions,tmp,log}/` | phpMyAdmin's sessions, temporary files and PHP error log (0700 lpanel-pma) |
| `/etc/pdns/pdns.conf` | PowerDNS: the MySQL backend (database `pdns`), port 53 on every address, the API on 127.0.0.1:8081 (0640 root:pdns) |
| `/etc/lpa/pdns.key` | PowerDNS's API key, the agent's alone (0600 root) |
| `/etc/exim/exim.conf`, `/etc/dovecot/dovecot.conf` | the mail servers' configurations, written by the installer whole |
| `/etc/exim/lpanel/` | accounts' mail data for Exim (lpa; 0750 root:exim): owners, local, mailboxes, forwards, dkim, accounts, smarthost, smarthost_auth (the relay login as Exim sends it), filter; smarthost_login (0600 root: the plain login, to keep a password); srs_secret (the installer) |
| `/etc/rspamd/local.d/` | Rspamd's settings: workers, no signing (the installer); actions.conf and antivirus.conf (lpa) |
| `/etc/dovecot/lpanel/` | mailbox logins (`passwd.d`, 0750 root:dovecot), webmail sign-on passwords (`sso.d`, hashed; `sso.keep`, 0700 root), autoresponders (`sieve/<domain>`, root:account group) and each mail domain's certificate (`sni.conf`) |
| `/etc/pki/lpanel-mail/` | the hostname certificate's copy for Exim and Dovecot (0640 root:exim), refreshed by lpa when it changes; `sni/mail.<domain>/` each mail domain's own |
| `/usr/local/lpanel-webmail/roundcube/` | Roundcube, root-owned and read-only; `config/config.inc.php` 0640 root:lpanel-webmail; the `lpanel_sso` plugin takes the panel's tickets |
| `/var/lib/lpanel-webmail/` | Roundcube's sessions, temporary files and logs (0700 lpanel-webmail); `tickets/` one-time sign-on tickets (2770 lpanel:lpanel-webmail). Its database is `lpanel_webmail` |
| `/var/lib/mysql/<user>_<name>/` | each account database's files, owned by MariaDB; their size comes off the home's quota |
| `/etc/lpanel/nginx/` | the panel's own nginx (`lpanel-nginx.service`, ports 7443 and 7444, and 127.0.0.1:7449 for mail clients' setup) |
| `/etc/lpanel/authd.json`, `/etc/pam.d/lpanel` | root sign-in: who may be checked, and the PAM stack used |
| `/var/lib/lpanel/`, `/var/log/lpanel/` | panel data and logs |
| `/etc/pure-ftpd/pure-ftpd.conf` | Pure-FTPd's configuration, written whole by lpa (`ftp.settings`) |
| `/etc/pure-ftpd/lpanel/` | FTP logins (lpa; 0700 root): `users.d/<account>` (login, bcrypt hash, uid, folder), the joined `passwd`, its index `pureftpd.pdb`, `settings.json` |
| `/etc/pki/pure-ftpd/pure-ftpd.pem` | the hostname's key and certificate for FTPS (0600 root), refreshed by lpa when it changes |
| `/var/lib/lpanel/files/` | the file manager's staging folder (0700 lpanel): uploads (also PHP's upload folder), saved files and downloads on their way to and from the agent, under 64 hex digits; `files:sweep` removes what nobody collected |
| `/usr/local/lpa/bin/lpa`, `/usr/local/lpa/bin/lpanel-authd` | the two root programs (`lpanel_agent_exec_t`, `lpanel_authd_exec_t`) |
| `/etc/lpa/lpa.json`, `/etc/lpa/panel.pub` | agent config, and the panel's public key |
| `/var/lib/lpa/journal/` | agent journal |
| `/run/lpa/lpa.sock`, `/run/lpanel-authd/authd.sock` | the two sockets, 0660 root:lpanel |
| `/home/<user>/` | account homes (`public_html`, `mail`, `logs`, `tmp`, `.lpanel`) |
| `/etc/httpd/conf.d/lpanel.conf` | the agent's one addition to Apache: the log format, a 404 default site on 80 and on 443 (with the hostname's certificate), `Listen 443` when Apache is alone, and the include of the sites folder (through the `LPANEL_SITES` variable, so candidates can be tested whole). The installer empties mod_ssl's own `ssl.conf` |
| `/etc/httpd/lpanel/sites/<user>.conf` | each account's sites, a VirtualHost per site with its redirects, error pages, hotlink rules and protected folders (labelled `httpd_config_t`) |
| `/etc/httpd/lpanel/htpasswd/<user>-<hash>.htpasswd` | the logins of a protected folder, bcrypt hashes only (0640 root:apache) |
| `/etc/opt/remi/php<XY>/php-fpm.d/lpanel-<user>.conf` | each account's pool for that PHP version, running as the account, with the owner's php.ini settings for that version; Remi's sample `www.conf` is emptied |
| `/var/opt/remi/php<XY>/run/php-fpm/lpanel-<user>.sock` | the pool's socket, usable only by the web server's group |
| `/var/log/httpd/lpanel/<user>.access.log`, `.error.log` | each account's Apache logs, with bytes in and out for bandwidth |
| `/etc/nginx/nginx.conf` | with nginx serving sites (`--web=nginx` or `hybrid`): the installer's own, which includes `conf.d/` only (the package's is kept as `nginx.conf.lpanel-orig`) |
| `/etc/nginx/conf.d/lpanel.conf`, `/etc/nginx/lpanel/sites/<user>.conf` | the same for nginx: log format, 404 default site on 80 (on 443 it refuses the TLS handshake for names nobody serves), and a server block per site, also on 443 with its certificate |
| `/etc/nginx/lpanel/htpasswd/` | protected folders' logins when nginx alone checks them (0640 root:nginx) |
| `/var/log/nginx/lpanel/<user>.access.log`, `.error.log` | each account's nginx logs; bandwidth is counted from these when nginx is in front |
| `/etc/logrotate.d/lpanel` | rotates the accounts' Apache and nginx logs daily and the panel's weekly |
| `/home/<user>/public_html/` | every site's folder is this or one below it (0750 with the web server's group, `httpd_user_rw_content_t`) |
| `/home/<user>/tmp/`, `/home/<user>/logs/` | the account's PHP sessions and uploads, and its PHP error log |
| `/home/<user>/mail/<domain>/<local>/` | a mailbox's home and Maildir, written by Dovecot as the account's user (`mail_home_rw_t`) |

Services: `lpa`, `lpanel-authd.socket` (socket-activated), `lpanel-php-fpm`,
`lpanel-nginx`, `lpanel-scheduler.timer` (every minute), `mariadb`, `pdns`, `exim` and `dovecot`. For
customers' sites: `httpd`, and `php<XY>-php-fpm` for each PHP version in use.
The agent enables each of those when an account first uses it, and stops a
version left with no pools. The stock `nginx` service stays off; the panel
has its own. The installer is
`dist/installer/install.sh`, run from a bundle made by
`dist/installer/bundle.sh`.

## Decisions

All decisions are recorded in [adr/](adr/).
