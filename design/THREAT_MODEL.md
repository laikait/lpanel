# Threat model

Scope: the MVP in [MVP_SCOPE.md](MVP_SCOPE.md), with the architecture in
[ARCHITECTURE.md](ARCHITECTURE.md). Revisit this at every milestone that adds a
mutating operation. Plan §14 says not to proceed past a failed security test.

## 1. Assets

| # | Asset | Why it matters |
|---|---|---|
| A1 | Panel operation-signing key | Whoever holds it can make every agent run any allowlisted operation |
| A2 | Agent keys (signing + TLS) | Impersonate a server: forge results, read the operations sent to it |
| A3 | Admin accounts, sessions, API tokens | Full control through the panel |
| A4 | Customer files, databases, mail, secrets | The thing customers pay to have protected |
| A5 | Generated hosting config + last-known-good backups | Wrong config takes every site down, or exposes another account |
| A6 | Panel DB (users, audit, enrollment hashes, resource state) | Integrity of every decision |
| A7 | `APP_KEY` (LPHP `Signer`/`Encrypter`) | Forged signed URLs and CSRF tokens; decrypts encrypted columns |
| A8 | Audit trail | Accountability; must survive a compromised customer and a careless admin |

## 2. Actors

| Actor | Starting position |
|---|---|
| T1 Internet attacker | can reach `:8443` and customer sites |
| T2 **Hosting customer** | panel login with customer role, **plus code execution as their own uid on a managed server** (PHP, cron, SFTP) |
| T3 Compromised customer website | same as T2, but for an attacker the customer doesn't know about |
| T4 Network attacker | on-path between agent and panel |
| T5 Compromised `lpa-agent` process | the `lpa` user on one server |
| T6 Compromised panel web process | the `lpanel` user on the panel host |
| T7 Malicious or careless administrator | an admin role in the panel |
| T8 Supply chain | PHP / Go dependencies, distro packages, the LPA release channel |

## 3. Threats and mitigations

Status: **D** = designed (in these docs), **T** = needs a named test before the milestone closes, **O** = open.

### Authentication and sessions

| Threat | Mitigation | Status |
|---|---|---|
| T1 credential stuffing / brute force | LPHP login throttling (`auth.throttle.*`); TOTP required for every admin role | D, T |
| T1 CSRF on cookie API | LPHP CSRF on by default; `/api/v1` keeps it; only bearer-only `/api/token/v1` and `/agent/v1` opt out | D, T |
| Session fixation | LPHP regenerates the session on login and rotates CSRF | D (LPHP) |
| Token theft from the DB | only SHA-256 fingerprints stored; tokens shown once; revocable; scoped to a role | D, T |
| T1 user enumeration | LPHP: every login failure gives the same answer and costs the same time | D (LPHP) |

### Authorization and isolation (release-blocking, plan §8)

| Threat | Mitigation | Status |
|---|---|---|
| T2 IDOR: change an id to reach another customer's resource | every account id goes through `AccountAccess`: not yours is answered exactly like "does not exist" (404) and audited (`account.access`); acting needs `accounts.manage`, refused with 403 and audited; lists are scoped to the owner | D, **T** (built for accounts, M6a, and websites, M6b, through `WebsiteAccess`; a test per resource type as each arrives) |
| T2 mass assignment (`"role":"admin"`) | `Schema::deserialize()` drops unknown fields; privileged fields never in customer schemas | D, T |
| T2 reading another account's files on the server | distinct uid per account (20000–59999); homes `0750 root:<acct>` with `web/` `0750`, `tmp/` `0700`, `logs/` `0750` owned by the account; PHP-FPM pool per account running as that uid, `open_basedir` = its home, sessions in its `tmp/`; `www-data` reads through group membership only ([ADR-0013](adr/0013-hosting-accounts-on-a-server.md)) | D, **T** (built, M6a) |
| T2 reading LPA keys | `/etc/lpa` is `0700 lpa`; `exec.sock` is `0660 root:lpa`, and customers aren't in group `lpa` | D, T (built, M6a) |
| T2 talking to `lpa-exec` | `SO_PEERCRED` must equal the `lpa` uid; anything else gets no answer and is logged | D, T (built, M6a) |
| T2 process snooping | `hidepid=invisible` on `/proc`. Until then, A sees that B's PHP workers exist (their names); the kernel already keeps their memory, environment and open files from A | **O** — deferred to M13 (product owner, 2026-10-28) |
| T2 config injection through managed web settings | structured settings only; allowlisted keys and value grammars; LPA renders templates; values are escaped for Nginx syntax; `include`, `load_module`, `http`/`events` directives and absolute paths are refused. Built (M6b, [ADR-0014](adr/0014-websites-through-the-hosting-nginx.md)): every value has a grammar that can't end a string, directive or block, checked by the panel and again by `lpa-exec`; no `$` (no variables); a customer changes PHP, index files, redirects and headers of its own websites only | D, **T** (built, M6b) |
| T2 answering certificate checks for LPanel (a file at `/.well-known/acme-challenge/` in the document root, or a redirect there), to get a certificate issued to someone else's key | the checks are answered from root's `/var/lib/lpa-acme/<website id>/` through `location ^~`, which beats the account's regex locations; the redirect grammar refuses the path; tokens are written only while an order is open ([ADR-0015](adr/0015-website-certificates-with-acme-http-01.md)) | D, **T** (built, M7: the package tests plant a file there and get 404) |
| T2 reading a website's private key | made by `lpa-exec` and kept in `/etc/nginx/lpanel/tls/` (`0700` dir, `0600` file, root's), read only by the Nginx master; never in the panel, the database, a result or a log | D, T (built, M7) |
| T2 symlink tricks in the document root (e.g. pointing at `/etc/lpa`) | Nginx `disable_symlinks if_not_owner`; LPA resolves paths with `openat`-style containment before any privileged file operation; website logs are root's, in `/var/log/nginx`, never in the account's `logs/` (the Nginx master opens them as root) | D, T (built, M6b) |
| T2 database reach | one DB user per database, granted only on it; a deny list for the `lpanel` schema and accounts | D, T |

### Agent channel

| Threat | Mitigation | Status |
|---|---|---|
| T4 MITM / panel impersonation | SPKI pin from enrollment; TLS 1.2+ | D, T |
| T4 replay of a request | request id + expiry ≤ 120s + seen-id store | D, **T** |
| T4 / T6 forged operation | Ed25519 operation signature checked by the agent before parsing | D, **T** |
| Stolen enrollment token | single use, 15 min, stored hashed, rate-limited, audited; the agent pins the panel | D, T |
| T5 forged results for its own server | results validated against `result_schema`; resource is marked active only after a postcondition check; anomalies → `needs_attention` | D |
| T5 acting for another server | `agent_id` in both the signature and the mTLS fingerprint; operations are leased per agent | D, T |
| T5 making up privileged work | `lpa-exec` verifies the panel's signature itself and runs each idempotency key at most once ([ADR-0013](adr/0013-hosting-accounts-on-a-server.md)): a compromised agent can only pass on what the panel signed, until its `not_after`, and cannot replay it | D, T (built, M6a) |
| T5 claiming another account's result | a result is checked against the account's own `system_user`; one about another user settles nothing and is kept as `invalid_result` | D, T (built, M6a) |
| Compromised agent key | revoke in the UI; queued operations cancelled; 90-day rotation | D |

### Privilege and blast radius

| Threat | Mitigation | Status |
|---|---|---|
| T6 RCE in the panel → shell on the panel host | `system.enabled=false` (ADR-0005); `lpanel` has no sudo; only `system/` is writable | D, T |
| T6 RCE in the panel → all servers | the attacker gets allowlisted operations, not a shell. **But A1 is readable by `lpanel`**, so they can sign anything in the registry. Destructive operations (`*.delete`) need a recent re-auth at the API layer; the agent can't verify that | **O** — consider a separate signing service or hardware key after MVP |
| T5 parser bug → root | network code runs as `lpa`; root code is reachable only over the socket ([ADR-0009](adr/0009-lpa-privilege-split.md)); `lpa-exec` keeps 6 capabilities, no network, `NoNewPrivileges` | D (built, M6a) |
| T7 / T6 deleting a user that is not an account | `lpa-exec` acts only on `lp[a-z2-7]{8}` users in the account uid range, with its own record naming the same account id; anything else is `precondition_failed` | D, T (built, M6a) |
| T7 admin edits panel config through hosting operations | no hosting operation can name `lpanel-nginx` or `/etc/lpanel` (ADR-0003); the LPA deny list is compiled in. Website operations write only `/etc/nginx/lpanel/` and one `conf.d` include, and reload only `nginx.service`; the panel package test checks `/etc/lpanel` is byte-identical | D, T (built, M6b) |
| Generic command execution | none exists: no `exec`/`shell` operation; adapters use fixed binaries + argv; the protocol rejects unknown fields | D, **T** (a registry test lists every op) |
| Cron as an escalation route | cron operations install into the **customer's** crontab only, running as the customer uid. That is code execution at customer level, which is by design | D |

### Configuration safety

| Threat | Mitigation | Status |
|---|---|---|
| A bad or missing certificate takes all sites down | each certificate is checked (for LPanel's key, every domain, valid now) before it is installed, under a new file name, through the same staged `nginx -t`, probe and rollback; a renewal that does not take keeps the old one serving and deletes the new key; `websites.check` reports a missing or changed certificate file | D, **T** (built, M7) |
| `lpa-exec` reaching more than it needs | outbound IP is needed for the certificate authority; the directory URL is `lpa-exec`'s command line, never a payload; link-local (cloud metadata) and multicast addresses are denied in its unit | D (built, M7) |
| Bad candidate takes all sites down | write the candidate to a staging tree → `nginx -t -c <staged main config>` that includes the real main config → atomic swap → reload → health probe → automatic rollback. Built (M6b): the staged main config is `nginx.conf` with only its `conf.d` line spelled out; the probe is each website's `/.well-known/lpanel-probe`, answering its revision to localhost; a rollback Nginx does not confirm is `needs_attention` | D, **T** (built, M6b) |
| Silent overwrite of manual edits | a hash of each generated file is recorded; drift is reported and never auto-fixed by default. Built (M6b): the hourly `websites.check` reports edits, reloads one that passes `nginx -t` (product owner's policy), never rewrites; every operation that would rewrite an edited file is refused (`config_edited`) until an administrator restores LPanel's version, audited | D, T (built, M6b) |
| Disk full in the middle of a write | write a temp file + `fsync` + `rename`; check free space before writing | D, T |

### Data, secrets and logs

| Threat | Mitigation | Status |
|---|---|---|
| Secrets in logs, results or errors | LPHP `Secret`; results contain no raw output; operations never carry DB passwords in plain text (encrypted to the agent's X25519 key, derived from its Ed25519 key — **design in the Databases milestone**) | D / O |
| Audit tampering by app roles | `audit_events` is append-only in code. Retention is deleted only by a scheduled task with a documented policy. Option: a MariaDB trigger that blocks UPDATE | D / O |
| Backups deleted by a compromised customer | stored outside every customer's uid; customers ask for restores through operations only | Later milestone |

### Supply chain

| Threat | Mitigation | Status |
|---|---|---|
| Malicious LPA update | signed release packages; no self-update from an unauthenticated URL; apt repository with a signing key pinned at install | D (Phase 1 packaging) |
| Vulnerable dependencies | `composer audit`, `govulncheck` in CI; dependency inventory | D |

## 4. Tests this model requires (release-blocking ones in bold)

1. **A customer changes every id in every customer API → 403/404, and an audit record for each attempt.** *Built for hosting accounts (M6a): `tests/Feature/HostingAccountsTest.php` tries another customer's account id and an unknown one on every page and API route (404, one `account.access` denial each), and the owner's own account on every action (403, audited). Built for websites (M6b): `tests/Feature/WebsitesTest.php` does the same on all 18 website routes, the certificate ones included (`website.access`), and checks an owner may change its own website's settings but not its domains or folder, nor suspend, delete or restore it (403, audited). Each new resource type adds its own.*
2. **Replayed, expired, wrong-signature, wrong-cert, wrong-agent and malformed agent requests are rejected with the right error code.** *Built (M3): `tests/Feature/AgentRequestTest.php`; the real agent through panel Nginx in `test/e2e/run.sh`.*
3. **An operation with a bad signature, an unknown op, unknown fields or an expired `not_after` is refused by LPA without acting.**
4. **A registry test fails if any operation takes a free-form command, executable path or environment.** *Built: the agent's `TestTheRegistryIsExactlyTheReadOnlyAllowlist` and `TestEveryOperationRefusesPayloadFields` (M1); the panel's `OperationCatalogueTest` (M4) checks its catalogue is exactly the agent's and that no payload takes a field.*
5. **As customer A's uid on a test server: can't read B's home, B's PHP session files, `/etc/lpa`, or connect to `exec.sock`.** *Built (M6a): the lpa repository's `test/packages/run.sh`, on all four OSes, with accounts made by the real `lpa-exec`. It also checks that A's PHP runs as A and reaches neither B's files nor `/etc/lpa`, that A can't connect to B's PHP-FPM pool, and that root gets no answer from `exec.sock`.*
6. **Invalid or prohibited web settings → no file changes, no reload; a failed reload → rollback verified by a health probe.** *Built (M6b): the lpa repository's `internal/websites` tests (hostile settings, a refused candidate, a name another site serves, a reload that does not take, a rollback that is not confirmed) and its `test/packages/run.sh` with the real Nginx on all four OSes: hostile settings refused with `/etc/nginx` byte-identical and the same Nginx workers (no reload); with `ExecReload=/bin/true` the change is rolled back, the files are as before and the probe sees the old revision. The panel refuses the same settings before anything is queued (`WebsitesTest`). Since M7 the same holds for certificates: a renewal whose reload does not take leaves the old certificate serving and no new key, and an authority's refusal or a certificate not for LPanel's key changes nothing.*
7. Enrollment token: second use, after expiry, and wrong token all give the same answer to the caller and different audit reasons. *Built (M3): `tests/Feature/AgentEnrollmentTest.php`.*
8. `config/system.php` has `enabled => false`; no module imports `App\Engine\System\*`.
9. No secret appears in the `audit` / app logs or operation results (grep the test fixtures for known sentinel secrets).

## 5. Open items for the product owner

- **O-1:** Should the operation-signing key move out of the web process's reach (a signing helper on a Unix socket, or a TPM/HSM) before the public beta?
- **O-2:** Is `hidepid` on `/proc` acceptable for the hosting servers? *Deferred to M13 (product owner, 2026-10-28).*
- **O-3:** What is the audit retention period?
