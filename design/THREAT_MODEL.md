# LPanel threat model

This page covers what LPanel protects, from whom, how, and which test proves
each protection. Update it in the same change as any feature that adds a new
way in.

## What is worth attacking

| Asset | Why |
|---|---|
| Root on the server | Whoever controls `lpa` or `lpanel-authd` controls the machine |
| The root password | Checked by authd. The panel never stores or logs it |
| `/etc/lpanel/agent.key` | Signs agent requests: whoever holds it can ask root for any listed operation |
| `.env` (APP_KEY, database password) | APP_KEY decrypts two-factor secrets and signs CSRF tokens; the database holds every login |
| Panel sessions | A stolen admin session acts as that admin |
| Customers' data | Sites, mail, databases (from M1 on) |

## Trust boundaries

```
 Internet ──HTTPS──▶ lpanel-nginx (nginx user) ──FastCGI──▶ PHP-FPM (lpanel user)
                                                                │ signed requests
                         lpanel-authd (root) ◀──Unix socket─────┤
                         lpa (root)          ◀──Unix socket─────┘
 Local users (customers' shell accounts, from M7) ──✗──▶ both sockets
```

The `lpanel` user is the boundary that matters. Everything it can do is
limited by these:

- The agent accepts only a fixed list of operations, each checking its own
  arguments.
- authd answers only "is this root's password?" and limits how often it can
  be asked.
- Neither socket answers any other uid.

## Threats and what stops them

| # | Threat | Defence | Proved by |
|---|---|---|---|
| T1 | Guessing logins on the web | Framework throttle per login, address and both. Root: the panel's own 5/15 min per address plus authd's lockout and global limit. Every failure audited | `SignInTest::test_root_guessing_is_throttled…`, authd `TestStrictLockoutPerAddress`, `TestGlobalLimitStopsALyingPanel` |
| T2 | Learning which logins exist | One answer for every failure, including a right password on the wrong interface. A hash is computed even for unknown names | `SignInTest::test_each_login_uses_only_its_own_sign_in_page` |
| T3 | A stolen password alone | Optional TOTP for every tier. Codes work once. Recovery codes stored only as hashes | `SignInTest::test_two_step_sign_in_from_set_up_to_recovery_code` |
| T4 | One tier using another's interface | Route guard on every `/admin` or `/panel` route plus an authorization filter, by login kind. nginx refuses the other interface's paths per port | `SignInTest::test_logins_cannot_open_the_other_interface`, e2e install "7443 does not serve the User panel" |
| T5 | CSRF, session fixation | CSRF on every form. Session id regenerated at sign-in. Logout invalidates the session | `SignInTest::test_a_form_without_its_csrf_token_is_refused` |
| T6 | Open redirect after sign-in | `next` must be a path inside the same interface | `SignInTest::test_the_page_asked_for…but_never_another_site` |
| T7 | Downloading source or secrets | nginx passes everything to `index.php`. The code is outside any served root | e2e install "/.env cannot be downloaded" and five more |
| T8 | A local user (or another service) asking the agent or authd | Both sockets are 0660 root:lpanel. Both check SO_PEERCRED and stay silent to any other uid | e2e agent "a member of the lpanel group gets no answer", e2e install "an ordinary user cannot open the authd or agent socket", authd `TestOtherLocalUsersGetNoAnswer` |
| T9 | A process running as `lpanel` but without the key forging agent requests | Ed25519 signature on every request. The key file is 0600 lpanel and read on use | e2e agent "a request signed with another key is refused", Go `TestCheck` |
| T10 | Replaying a captured agent request | Timestamp within 60 s plus a nonce cache. Changes are exactly-once per id through the journal | e2e agent "a replayed request…", "a stale request…", Go `TestChangesRunExactlyOncePerID` |
| T11 | The panel (if compromised) asking the agent for anything | The agent has no shell and no "run this"; only registered operations. Unknown argument fields are refused | Go `TestRefusals` (`system.shell`, `/etc/shadow` argument) |
| T12 | The panel (if compromised) using authd as a password oracle | authd checks only users in `authd.json` (root), with per-address lockout and a global per-minute limit the panel cannot change | authd `TestOnlyAllowlistedUsersAreChecked`, `TestGlobalLimitStopsALyingPanel` |
| T13 | Secrets in logs | Passwords are wrapped in `Secret`. Agent logs never include args. authd logs never include the password | e2e install "the password never reached a log", e2e agent "the log never contains the key or args" |
| T14 | The panel changing its own code | The code is root-owned. Only `system/` is writable by `lpanel` | e2e install "the panel cannot change its own code" |
| T15 | A broken customer site config taking the panel down | Separate nginx and PHP-FPM for the panel (ADR-0010) | by construction; watchdog in M10 |
| T16 | Two-factor secrets read from the database | Encrypted with APP_KEY (XChaCha20-Poly1305) | by construction (framework `Encrypter`) |
| T17 | The panel (if compromised) suspending, changing or deleting root, a system user or a person's own login through the account operations | The agent changes only users it made: comment `lpanel account`, uid ≥ 1000, home exactly `homes_base/<name>`. Usernames must match `^[a-z][a-z0-9]{1,15}$` | Go `TestOnlyLPanelAccountsAreTouched`, e2e agent "root is not the agent's to suspend", "nor is an ordinary local user" |
| T18 | A customer's symbolic link turning account removal into deleting files elsewhere | The agent removes the home by the path it works out itself, not the one in passwd, with `os.RemoveAll`, which does not follow links | Go `TestTerminateRemovesEverythingButFollowsNoLinks`, e2e agent "a link in the home did not lead the removal out of it" |
| T19 | One account filling the disk for everyone | Kernel user quotas, soft = hard (no grace). Set by the agent from the package | e2e agent "the kernel enforces the 2 MB quota", e2e install "the kernel holds it to its 3 MB" |
| T20 | A reseller reaching another reseller's accounts or packages | Every query is scoped by owner unless the login holds `accounts.all` / `packages.all`; anything out of scope is a 404, in pages and the API | `AccountsTest::test_resellers_see_only_their_own…`, `…cannot_use_another_resellers_package…`, the API test's other-reseller token |
| T21 | CSRF against the REST API, or a stolen API token database | API routes turn CSRF off, so the framework ignores the session cookie there: only a bearer token counts. Tokens are 32 random bytes, stored as SHA-256 only, shown once, expiring and revocable | `AccountsTest::test_the_api_works_with_a_token_and_never_with_a_session_cookie`, `…tokens_are_made_on_their_page_shown_once_and_revoked`, e2e install "a signed-in browser's cookie is not an API credential" |
| T23 | "Log in as" used to become someone the person may not, or to take over the target for good | Only root/administrators → resellers and owners; resellers → their own owners, if their ACL allows; never root, staff or yourself; anything else is a 404. While logged in as someone, their password, two-factor, sessions and API tokens cannot be changed (`no_impersonation`). Every change is audited as on behalf of the real person | `SessionsTest::test_who_may_become_whom`, `…root_logs_in_as_a_reseller_and_returns` |
| T24 | A "log in as" ticket stolen or replayed | 32 random bytes, stored as SHA-256, deleted on first use before anything else, expires after 60 s, and checked again against the target's status | `SessionsTest::test_a_reseller_logs_in_as_their_customer_with_a_one_time_ticket`, `…an_expired_ticket_is_refused`, e2e install "root logs in as the owner, across ports" |
| T25 | A session stolen before a password change or suspension staying useful | Session epochs: the session remembers the login's epoch; a new password, reset, disable, suspension or "sign out everywhere else" raises it | `SessionsTest::test_a_new_password_ends_every_other_session_but_this_one`, `…sign_out_everywhere_else`, `…a_password_reset_by_an_administrator…` |
| T26 | The Admin panel and User panel sharing one session (cookies ignore ports) | One session cookie per interface (`config/session.php`) | e2e install "each interface has its own session cookie" |
| T27 | A reseller selling more than their allowance, or acting outside their ACL | Allowance checked on every create, package move and package change, whoever acts; the ACL applied in the authorization filter, so pages, API and menus agree | `ResellersTest` (all) |
| T28 | A sub-user using features they were not given | `feature.*` capabilities narrowed to the package and the sub-user's grants in the authorization filter; sub-users cannot manage users | `SubusersTest::test_an_owner_makes_a_sub_user_who_gets_only_what_was_granted`, `…grants_follow_the_package` |
| T29 | Another service on the server (or a compromised one) talking to the agent or authd | Besides the file modes and SO_PEERCRED (T8), the SELinux module runs both in their own domains and lets only `httpd_t` connect to them, through sockets of their own type; `httpd_t` gets no other unconfined service and no generic run-time sockets | `dist/selinux/build.sh` checks "connect to the agent", "no other unconfined service", "no other generic run-time sockets"; on a VM, `tests/vm/run.sh` "nothing was denied during the whole run" |
| T30 | An account taking over another account's domain, or its www. name | Names are unique on the server: a domain, an alias and every www. form are checked against every account, and new accounts against every site. www. is never a domain of its own | `WebTest::test_names_folders_and_limits_are_checked` |
| T31 | A customer's symbolic link turning a document root into the agent making or chowning folders elsewhere | Document roots must be `public_html` or below. The agent walks from the home with `O_NOFOLLOW` directory descriptors, refuses any link on the way, and never takes over a folder someone else owns | Go `TestMakeDirsRefusesLinksAndSetsTheWebGroup`, e2e install "a link planted in public_html is refused, not followed" |
| T32 | A bad site config, or a hand-edited one elsewhere, taking every site down | Each candidate is tested whole (`httpd -t`, `php-fpm -t`) before anything live changes. After a reload, every site must serve the new revision, or the old files go back. A refused change is undone in the panel too | Go `TestARejectedCandidateChangesNothingLive`, `TestAFailedProbePutsTheOldFilesBack`, e2e install "a broken Apache config stops the change, and every site keeps running" |
| T33 | One account's PHP reading another's files or sessions | Each account's pool runs as its own user. `public_html` is 0750 with Apache's group, so only Apache and the owner may read it. Sessions and uploads go to the account's own `tmp/`. A pool's socket is usable only by Apache's group | e2e install "one account's PHP cannot read another's files" |
| T34 | Site config injected through anything an owner types: a domain, folder, redirect, error page, prompt, login, file kind or php.ini value (newlines, quotes, spaces, directives) | The panel and the agent both check every value against a strict rule before it is rendered. php.ini takes only 14 listed directives, each with its own value rule, so `disable_functions` or a `php_admin_value` cannot be set | Go `TestRefusals`, `TestFeatureRefusals`, `SiteFeaturesTest::test_bad_input_is_shown_on_the_page_and_never_reaches_the_server`, e2e install "settings that are not offered are refused" |
| T35 | The passwords of protected folders read by someone else | Kept only as bcrypt hashes, in the panel and on the server. The login files are 0640 root:apache, so other accounts cannot read them. Neither the API nor the pages ever show a hash, and passwords never reach the audit log | `SiteFeaturesTest::test_the_owner_sets_up_a_site_and_the_server_gets_all_of_it`, `…test_the_api`, e2e install "its logins are bcrypt hashes, 640 root:apache, unreadable to others" |
| T36 | A password on a whole site undone by the site's own "Require all granted" | The protected section is written after the site's own `<Directory>`, because of two sections for one path the later wins | Go `TestFeaturesAreRendered`, e2e install "a protected folder asks for its password, and takes only the right one" |
| T37 | The panel (or anyone who took it over) turning a PHP change into installing any package, or passing options to dnf | The agent takes only a version and extension names from its own catalogue and builds the package names itself; anything else is `bad_args` before dnf runs | Go `TestPHPVersionsAndExtensionsComeOnlyFromTheCatalogue`, `PhpVersionsTest::test_extensions_are_the_whole_set_and_the_panels_own_stay` |
| T38 | Removing PHP from under live sites, or from under the panel itself | A version is removed only when no pool uses it (checked by the agent while it holds the sites lock, and by the panel) and never the panel's own (`panel_php`), nor the extensions the panel needs. Only root, administrators and API tokens of theirs (`server.php`) may change PHP | Go `TestThePanelsOwnPHPAndAVersionInUseStay`, `PhpVersionsTest::test_resellers_and_owners_cannot_change_the_servers_php`, e2e install "a version a site uses stays", "the panel's own extensions stay" |
| T39 | A customer making their bandwidth look smaller, or another's larger | Bytes come from Apache's own access logs in `/var/log/httpd/lpanel` (root only), not from anything an account can write. Each file is read by inode and offset, so rotation neither loses nor repeats bytes, and totals are replaced, never added to, in the panel | Go `TestTrafficCountsEachByteOnceAcrossCallsAndRotation`, `BandwidthTest`, e2e install "after the logs rotate, nothing is lost or counted twice" |
| T40 | A visitor claiming another address through Apache behind nginx (hybrid), to dodge a site's IP rules or poison its logs | nginx sets `X-Forwarded-For` to the address it sees, never appending the visitor's own; Apache takes it only from 127.0.0.1 (`mod_remoteip`); Apache listens on 127.0.0.1:8080 only | e2e install (hybrid) "and a visitor cannot claim another one", "Apache is not reachable from outside" |
| T41 | nginx alone serving what Apache would not: hidden files (`.env`, `.git`, `.htaccess`), PHP source, or a file through a customer's link to another account's | Hidden files are refused except `.well-known`; `.php` goes only to the account's pool (a file kind in hotlink protection is checked in the rewrite phase, never as a location that could serve it); links are followed only to files of the link's owner (`disable_symlinks if_not_owner`). A protected folder repeats these rules inside its own location | Go `TestNginxAloneServesEverySiteWithItsFeatures` |
| T42 | A broken file elsewhere in nginx's or Apache's configuration making a reload take every site down | Each change is tested over the server's whole configuration (`httpd -t`; `nginx -t` over nginx's own `nginx.conf` with a staged `conf.d`) before anything live changes | Go `TestNginxTestsTheCandidateAmongEveryOtherAccount`, e2e install (each stack) "a broken … config stops the change, and every site keeps running" |
| T43 | The panel (or anyone who took it over) sending the agent to another certificate authority, or having it fetch arbitrary addresses | The authorities' directories come only from lpa.json; an operation names one of two authorities, never a URL. Names must be plain host names (no wildcard, no IP, no port). The agent's own check before ordering fetches only `http://<name>/.well-known/acme-challenge/<random token>` and compares the body; nothing fetched is returned | Go `TestIssueRefusals` |
| T44 | Private keys read by someone else | Keys are made on the server and never leave it: sites' keys in `/etc/pki/lpanel/sites/<user>/` (0700 root, files 0600), the hostname's in `/etc/lpanel/tls/` (0700 root), authority account keys in `/var/lib/lpa/acme/` (0600). A key an owner pastes passes through the panel once: it is neither stored there, shown again nor audited. A request (CSR) keeps its key on the server, so the certificate comes back without one | Go `TestCSRAndInstall`, `SslTest::test_the_owner_brings_a_certificate_and_chooses_https` ("the key is never sent back"), e2e install "keys stay root's alone" |
| T45 | An owner getting, or serving, a certificate for a name that is not theirs | AutoSSL orders only for the names of the account's own sites (names are unique on the server), and the authority's HTTP-01 check must still pass. A certificate the owner brings must cover the site's name. A site serves only a certificate stored under its own account: `web.apply` looks the id up in that account's folder alone | Go `TestHTTPSNeedsItsCertificate`, `SslTest::test_the_owner_brings_a_certificate_and_chooses_https` |
| T46 | The certificate-check path used to serve or plant content | The folder is root's and written only by the agent, with names that match base64url. Sites map only `[A-Za-z0-9_-]+` names under `/.well-known/acme-challenge/` to it, before their own rules (so a suspension, a redirect or a password does not stop a check), and nothing else | Go `TestApacheServesHTTPS`, `TestNginxServesHTTPS`, `TestHybridServesHTTPSInFront` |
| T47 | Failing orders driving the server into the authorities' rate limits or blocklists | Names that do not point here are left out before anything is ordered. Each failure waits 1, 2, 4… up to 24 hours. A failed check is not retried at the other authority. At most four orders run at once | Go `TestIssueFallsBack`, `SslTest::test_failures_wait_longer_each_time`, e2e install "fails, and waits before trying again" |
| T48 | A bad certificate taking a site's HTTPS, or the panel itself, down | The agent checks every certificate before storing it: the key matches, the chain is in order, and it is valid now. HTTPS goes through the usual test, reload and probe (over HTTPS), and is undone on failure. The hostname's certificate is tested with the panel's own nginx and probed on 7443, and the previous one goes back on failure. An expired certificate is not served, so the site stays reachable over HTTP | Go `TestCheck`, `TestHostInstall`, `SslTest::test_an_expired_certificate_is_not_served` |
| T50 | SQL injection into the agent's statements through a name, password, privilege or host | The agent builds SQL only from values it has checked: names must be the account's prefix then `[a-z0-9_]` (within 64/32 characters), passwords arrive only as `*` and 40 hex digits, privileges come from a fixed list, hosts from `[A-Za-z0-9.:%_/-]`. Nothing is ever quoted or escaped to make it fit | Go `TestApplyRefusesAnythingThatCouldReachSQLUnchecked` |
| T51 | An account reaching another account's databases, or the panel's | Users exist only with grants on the account's own databases, named with `_` escaped (a database-level grant's `_` is a wildcard: `bob_shop` would also match `bobxshop`). Never GRANT OPTION, FILE, SUPER or a global privilege. phpMyAdmin's sign-on user has the same and cannot make databases | Go `TestApplyCreatesDatabasesUsersAndExactGrants`, e2e install "sees its own database only", "does not reach shop1xstore", "phpMyAdmin shows the account's databases only" |
| T52 | Database passwords read from the panel's database, logs or the agent's journal | The panel stores only MariaDB's own hash (what MariaDB itself stores); the password goes to the server as that hash, never in SQL text or a log. The CLI reads passwords from standard input | `DatabasesTest::test_the_owner_makes_a_database_and_a_user_and_the_server_gets_them`, e2e install "only MariaDB's hash is kept" |
| T53 | A phpMyAdmin flaw reaching the panel, or a sign-on reused | phpMyAdmin runs as `lpanel-pma` in its own pool (open_basedir on its own folders), cannot read the panel's code, `.env` or sessions, and the panel cannot read its config. Tickets are random 256-bit names in a folder only the two can open, deleted on first read, good for a minute. The sign-on user's password is random, changed at each sign-on, and the user is removed eight hours after the last. Its config, setup and libraries are not served | `DatabasesTest::test_phpmyadmin_signs_the_owner_in_with_a_one_time_ticket`, e2e install "a sign-on ticket works once", "phpMyAdmin runs as its own user" |
| T54 | A customer ending the panel's, root's or MariaDB's own connections, or one database's growth filling the disk | `db.kill` ends only connections of account users. Database space comes off the home's kernel quota, so files and databases together stay within the package | Go `TestKillOnlyAnAccountsConnection`, e2e install "database space comes off the home's quota" |
| T55 | A record reaching PowerDNS that it would run or misread: LUA records, a second SOA, TXT text closing its quotes and adding a record of its own | The agent checks every record itself and builds PowerDNS's content from the parts: a fixed list of types (no LUA, no SOA from the panel), parsed addresses, host names and numbers, CAA values without quotes, and TXT text quoted by the agent with `"` and `\` escaped and other bytes as `\DDD` | Go `TestApplyRefusesWhatAZoneMayNotHold`, `TestTextCannotBreakOutOfItsRecord`, e2e install "a quote in TXT text stays inside its record" |
| T56 | An account changing or removing another account's zone, or the server's | Every zone carries its account in PowerDNS; `dns.apply` and `dns.remove` refuse a zone of anyone else (`dns_not_owner`), and `account.terminate` removes only zones marked with that account. A domain name can belong to one account only, and a server zone cannot take a name an account holds | Go `TestAZoneIsOnlyItsOwnAccounts`, `TestRemoveForTakesOnlyTheAccountsZones`, `DnsTest::test_the_owner_edits_records_and_mistakes_are_explained` |
| T57 | Someone other than the agent writing zones through PowerDNS's API | The API listens on 127.0.0.1 only and needs a key that is root's alone (`/etc/lpa/pdns.key` 0600, `pdns.conf` 0640 root:pdns). The panel never holds it | e2e install "its API only on 127.0.0.1, its key root's alone", "PowerDNS's API is not reachable from outside" |
| T58 | A wildcard certificate for a domain this server does not answer for, or a certificate check's record left in a zone | The agent asks for a wildcard only after its own random record, looked up as anyone would, comes back. The check's TXT value is put beside the owner's records and taken away when the order ends, whatever the outcome | Go `TestIssueWildcardThroughDNS`, `TestACheckValueSitsBesideTheOwnersRecordsAndGoesAgain`, `TestDelegatedLooksItsOwnRecordUpTheWayAnyoneWould`, e2e install "the check's records are gone afterwards" |
| T59 | Mail data reaching Exim as something it would run: a forwarder to a pipe, a file or an include, a second address hidden in one, a catch-all to a command | The agent checks every address (a narrow local-part alphabet, a domain name, never `|`, `/`, `:`, `,` or quotes) and writes only those. Exim's routers forbid files, pipes, includes and filters outright, and find each domain's file with `dsearch`, so no name from a message becomes a path | Go `TestApplyRefusesWhatMailMayNotHold`, PHP `MailTest::test_input_and_limits_are_checked_before_anything_is_sent` |
| T60 | A mailbox, or an account's PHP, sending as another account's domain, or getting it DKIM-signed | On submission, Exim refuses a sender or `From:` whose domain belongs to another account (from `owners/`). Mail is signed only with the `From:` domain's key, and only when that domain is the submitting account's (its login's domain, or the calling user for the sendmail command) | e2e install "a mailbox cannot send as another account's address", "a site's own mail is signed; another account's forgery is not" |
| T61 | Logging in to mail without TLS, as a system user, or while suspended; relaying | Dovecot's configuration replaces the system's whole: only `passwd.d` mailboxes log in, never PAM. Passwords are refused before TLS (away from localhost), logins offered on SMTP only after TLS, the submission ports need a login, and only localhost relays. A suspended account's mailboxes get `nologin`; Exim refuses their mail from the owner file, since Dovecot's auth does not apply `nologin` to Exim | e2e install "IMAP and POP3 over TLS; a wrong password, a system user and root are refused", "no password before TLS from outside", "no sending without a login, no relaying", "a suspended account receives mail but cannot log in or send" |
| T62 | One account's mail data read or changed by another | Exim's and Dovecot's data are root's, readable only by their own groups; a domain is written only for the account that holds it (`mail_not_owner`); autoresponders are readable by the account's group only. Mail lives in the account's home, as the account | Go `TestADomainIsOnlyItsOwnAccounts`, `TestRemoveForTakesOnlyTheAccountsDomains`, e2e install "their data is root's, readable by each server's group" |
| T63 | An autoresponder's text closing its Sieve string and adding commands (redirecting mail away) | The agent quotes every string itself (backslash and quote escaped) and strips quotes and angle brackets from the sender's name; Pigeonhole compiles the script before it is used | Go `TestAutoresponderTextStaysInsideItsStrings` |
| T65 | Spam or viruses reaching mailboxes; a sender faking its own spam score | Rspamd scores mail from outside and Exim refuses it at the server's score, or when ClamAV finds a virus. Exim removes any `X-LPanel-Spam-Score` a sender wrote before adding its own, and the score is never below 0, so a Sieve comparison cannot be fooled | e2e install "obvious spam (GTUBE) is refused", "mail from outside gets its score; a sender's own score header is dropped", "ClamAV refuses a virus" |
| T66 | A compromised mailbox or site sending spam in bulk | The package's sending limit, counted by Exim per account across its mailboxes, its sites and what its filters send; over it, mail is refused and the log says so | e2e install "the package's sending limit holds for mailboxes and sites, per account" |
| T67 | A filter's text or folder breaking out of its Sieve string, or a folder name reaching outside the mailbox | The agent checks every field (fixed fields, tests and actions; printable text; folder names without path characters or wildcards; forwarding addresses of a narrow alphabet) and quotes every string; `*` and `?` in "begins/ends with" are escaped | Go `TestBadFiltersAndSendersAreRefused`, `TestSpamFiltersAndResponderShareOneScript` |
| T68 | The relay host's password leaking | It goes to the agent once and is kept only in `smarthost_auth` (0640 root:exim, as Exim sends it) and `smarthost_login` (0600 root); the panel stores the user, never the password, and the audit log neither. Exim sends it only over TLS, only to the relay host | Go `TestRelayLoginIsWrittenReadyToSend`, PHP `test_the_administrator_sets_filtering_and_the_relay_login`, e2e "the relay host gets its login, over TLS; the password is kept nowhere else" |
| T69 | An account reading another's mail flow through delivery tracking | `mail.track` with an account returns only messages from or to its own domains (and of mail to it, only its recipients); the panel always passes the signed-in account | Go `TestTrackingShowsAnAccountOnlyItsOwnMail`, PHP `test_the_queue_and_delivery_tracking` |
| T70 | Webmail's sign-on giving a mailbox to someone else, or outliving the right to it | The password is random (192 bits), only for that mailbox, hashed in Dovecot's file and kept in clear only in a root-only file; it reaches Roundcube in a one-time ticket that lives a minute in a folder only the panel and Roundcube's pool share, and never appears in a URL, cookie or log. It goes when the mailbox goes, the account is suspended, its password changes, or its hours are up | Go `TestSignOnGivesAPasswordDovecotAccepts`, `TestSignOnRefusals`, PHP `test_webmail_opens_with_a_one_time_ticket`, e2e "lpanel mail:webmail opens it with no password, once" |
| T71 | Mail clients' setup requests used to read the panel, or a name from a client becoming a path | Sites pass only the two setup paths, to a port on 127.0.0.1 that serves only `/mail-setup/`; the answers carry servers and ports, nothing an account set. The request body is never parsed as XML (the address is matched by a narrow pattern). Exim finds an SNI certificate with dsearch over a pattern-checked name | PHP `test_mail_clients_find_their_settings`, Go `TestMailSetupAndWebmailOnEveryStack`, e2e "mail clients find their settings" |
| T72 | A site's certificate serving a mail name it does not cover, or an expired one | The agent copies a certificate for `mail.<domain>` only when it covers the name and is valid now; otherwise the hostname's is served | Go `TestEachMailDomainServesItsOwnCertificate` |
| T73 | The file manager used to read or change another account's or the system's files: a path with `..`, a link to `/etc` or a neighbour's home, a link swapped in mid-operation, or a root-owned file in the home | The agent never touches customers' files as root: each operation runs in a worker as the account's own user (its uid and group only), so the kernel's permissions decide; the worker opens the home as an `os.Root`, which refuses every path or link that leads out of it, at every step of the path. Paths are cleaned and `..` refused in the panel, the agent and the worker | Go `TestLinksDoNotLeadOutOfTheHome`, `TestPathsAreKeptInsideTheHome`, e2e "links out of the home lead nowhere", "the agent works as the account" |
| T74 | A staged file used to make the agent read a file it should not (a link or hard link to `/etc/shadow` put in the staging folder) | The staging folder is the panel's alone; the agent opens a staged file by its 64-hex name only, without following a link, and takes only a plain file with one link that root does not own. The worker gets the open descriptor, never the folder | Go `TestStagedFilesAreTakenOnlyAsThePanelMadeThem` |
| T75 | An archive writing outside its folder (zip-slip, a link in a tar followed by a file through it), filling the disk, or carrying setuid files | Entry names with `..` are left out; links and devices are never made, so nothing can lead through them; unpacking stops at 8 GB and 200,000 entries, and the account's quota holds anyway; no entry keeps setuid, setgid or sticky bits | Go `TestArchivesCannotWriteOutsideTheirFolder`, e2e "a zip-slip entry is left out" |
| T76 | A save over someone else's change, or a half-written file after a crash | A save names the SHA-256 the file had when opened and is refused if it changed; it writes a temporary file beside the target and renames it over | Go `TestListingReadingAndSaving`, PHP `test_the_editor_saves_through_staging_and_refuses_a_stale_save` |
| T77 | An FTP login used to reach another account's or the system's files, or to log in as a system user or anonymously | Every login is a Pure-FTPd virtual user that works as its own account's uid (1000 and up), shut in a folder of that account's home; PAM, system users and anonymous logins are off in the configuration the agent writes whole. The agent refuses a login another account has (`ftp_taken`) and makes a missing folder through an `os.Root` at the home | Go `TestApplyWritesLoginsShutInTheirFolders`, `TestALoginOfAnotherAccountIsRefused`, `TestAFolderThroughALinkOutOfTheHomeIsRefused`; e2e "a login sees only its folder", "system users and anonymous are refused" |
| T78 | FTP passwords read on the wire, or kept where they could be read | TLS is required for logins by default (FTPS with the hostname's certificate); `strict` requires it for transfers too. Only a bcrypt hash is kept, in the panel's table and in root's 0600 files; passwords come over HTTPS or standard input, never in arguments or the audit log | PHP `test_the_owner_makes_a_login_shut_in_a_folder`, e2e "plain FTP … refused", "never a password" |
| T79 | A login that was removed, re-passworded or moved to another folder, or a suspended account, still working through an open session | A change ends that login's sessions at once (and every session of a suspended account); a suspended account's logins are taken out of Pure-FTPd's file | Go `TestChangesAndSuspensionEndSessions`, e2e "a suspended account's logins are shut out" |
| T80 | Ending a process that is not an FTP session, or another account's session, through the panel | `ftp.kill` signals only a pid that `pure-ftpwho` lists, and with an account only one of that account's logins | Go `TestKillOnlyEndsAListedSessionOfTheAccount`, PHP `test_sessions_are_listed_and_ended_per_account` |
| T81 | Text in a login, hash or folder breaking Pure-FTPd's file (a colon or newline adding a field or a user) | The panel and the agent take only a fixed login pattern, bcrypt hashes and folders without colons, backslashes, control characters or `..` | Go `TestApplyRefusesBadInput`, PHP `test_the_owner_makes_a_login_shut_in_a_folder` |
| T82 | An account's SSH or SFTP session reaching another account's files or processes, or the system's | Every session runs inside bubblewrap (`lpanel-jail` is the login shell; it never falls back to an unjailed one): only its own home is mounted, `/usr` and the root are read-only, it has its own PID namespace and `/proc`, a passwd naming only itself, and `no_new_privs` (no setuid). SFTP goes through the shell too: the installer replaces sshd's `internal-sftp` with the `sftp-server` program | Go `TestArgs`, `TestParseAndInner`; e2e "a jailed shell: its home only, its own processes…", "an SFTP-only account … sees only its home" |
| T83 | A key added behind the panel's back (in `~/.ssh/authorized_keys`, by the account's PHP or the file manager), or a key with options that run a command or forward ports | sshd reads keys for `lpanel-users` only from `/etc/ssh/lpanel/keys/<account>`, root's; the agent refuses keys with options or a second line, rewrites each as `restrict,pty …`, and the drop-in turns off forwarding, agent forwarding, tunnels, X11 and `~/.ssh/rc` | Go `TestApplyWritesRestrictedLines`, `TestApplyRefuses`; e2e "a key in ~/.ssh, a password and forwarding are all refused" |
| T84 | SSH passwords guessed, or weak keys accepted | Accounts sign in with keys only (passwords and keyboard-interactive off for the group); DSA, RSA under 2048 bits and unknown types are refused in the panel and the agent | PHP `test_the_owner_adds_a_public_key`, Go `TestApplyRefuses` |
| T85 | A private key the panel made being kept, logged or shown again | The pair is made in the panel (libsodium) and the private half is answered once, on the page or API response that made it (`no-store`); it is not in the database, the session, the audit log or the agent | PHP `test_a_key_made_by_the_panel_is_shown_once_and_never_kept`, e2e "keeps no private key" |
| T86 | Backups read by whoever holds the storage (a cloud provider, a stolen disk, the SFTP host) | Every repository is encrypted by restic with a random 256-bit password that only the agent holds (`/etc/lpa/backup`, 0700 root); the panel never sees it | Go `TestALocalDestinationGetsARepositoryAndAPassword`, e2e "its password is root's alone" |
| T87 | A compromised panel reading the destinations' credentials, or pointing a backup at a system folder | S3/B2 keys go from the panel to the agent once and are never returned, logged or kept in the panel's database; the agent refuses local destinations in `/home`, `/etc` and other system folders, and every field is pattern-checked before it reaches restic's environment (never its arguments, never a shell) | PHP `test_secrets_go_to_the_server_and_stay_there`, Go `TestS3KeepsItsSecretsAndNeverShowsThem`, `TestDestinationsAreChecked` |
| T88 | One account's backup carrying another's data, or a database the panel names that is not the account's | The agent dumps only databases with the account's prefix, as MariaDB lists them, and each snapshot is the account's home and its own stage folder only | Go `TestARunBacksEachAccountUpAndKeepsGoing` |
| T89 | An existing repository overwritten, or backups lost to a destination the agent cannot open | A destination is kept only after restic opens (or makes) its repository; one made with another password is refused (`backup_wrong_password`), never re-initialized; removing a destination leaves its data | Go `TestAWrongPasswordOrNoAnswerIsNotKept`, e2e "a repository with another password is refused" |
| T90 | An owner restoring another account's data, or a path outside their home | The agent restores only from snapshots tagged with the account (checked on each operation, not taken from the panel); paths are relative, without `..` or patterns; databases only with the account's prefix; the panel also limits mailboxes and databases to the account's own | Go `TestOnlyTheAccountsOwnSnapshotsAndPaths`, PHP `test_an_owner_puts_files_back_from_their_own_snapshot` |
| T91 | A restore, run as root, writing outside the home through a link or hard link the owner planted | restic restores into a root-only staging folder; files are copied through an `os.Root` on the home (no escape), links met where folders go are replaced, files are written under new names and renamed over the old ones; the same for downloads' `lpanel-backups/` | Go `TestARestorePutsFilesBackAndKeepsNewerOnes`, `TestADownloadIsAnArchiveInTheHome`, e2e "a link out of the home is not followed" |
| T92 | A restored file giving its owner more than they had (set-id bits, root's ownership) | Every file put back is the account's, with its permission bits only (no set-id) | Go `TestARestorePutsFilesBackAndKeepsNewerOnes`, e2e "as the owner's" |
| T93 | An account made again from a snapshot taking another account's names, or a second factor carried over | It is made only when its username, domains, databases, database users and FTP logins are all free here; backups never hold second-factor secrets, so the owner sets theirs up again | PHP `test_a_terminated_account_is_made_again_from_its_snapshot` |
| T64 | Deleting mail outside the account's home through a link the owner made in it | Mail is deleted (`mail.purge`) through an `os.Root` at the home, which no link can lead out of, and only once the mailbox is no longer set up | Go `TestPurgeDoesNotFollowALinkOutOfTheHome`, `TestPurgeDeletesOnlyMailNoLongerSetUp` |
| T49 | A visitor claiming HTTPS to Apache behind nginx | nginx always sets `X-Forwarded-Proto` to its own `$scheme`; Apache turns it into `HTTPS=on` and listens only on 127.0.0.1 | Go `TestHybridServesHTTPSInFront`, e2e install "PHP knows the visit came over HTTPS" |
| T22 | A lost or repeated agent answer making an account twice, or leaving the panel and the server disagreeing | The operation and its id are written before sending; retries reuse the id and the journal answers with the first outcome | `AccountsTest::test_an_unreachable_agent_records_the_change_and_settle_finishes_it_exactly_once`, e2e agent "the same id again gets the first answer" |

## Known gaps

These are tracked, not forgotten. Each names the milestone that closes it.

- **PowerDNS answers every query from anywhere, and is not rate limited.**
  It is authoritative only (no recursion), so it cannot be used to look up
  other domains, but answers can still be used in reflection attacks.
  Response rate limiting comes with the firewall manager (M9).
- **PowerDNS has no SELinux domain of its own** on EL9: it runs as
  `unconfined_service_t`. Its own user (`pdns`) and its API key still keep
  it apart from the panel.
- **Rspamd has no SELinux domain of its own and no Redis**: it runs as
  `unconfined_service_t` under its own user, and learns nothing from what
  owners file as spam (no Bayes, no greylisting) until Redis comes.
- **Virus scanning is off by default** (ClamAV needs about 1.5 GB of
  memory); the administrator turns it on.
- **Mail between two mailboxes of this server, and mail a mailbox sends, is
  not scanned**; only mail from other servers is.
- **Webmail and phpMyAdmin share the User panel's port and hostname.** Each
  runs as its own user in its own pool, and their cookies are limited to
  their own paths, but a browser treats them as one origin. A separate
  hostname for each is planned with the white-label settings (M11).
- **The file manager's worker runs in the agent's SELinux domain** (an
  unconfined one) under the account's uid, and accounts' SSH sessions run
  as the login's usual `unconfined_t`: the kernel's permissions, the
  `os.Root` and bubblewrap's namespaces keep them to the home, not SELinux.
  A confined user domain for both needs an enforcing machine to test on
  (the VM run) and comes with M9's isolation hardening.
- **The mail servers' SELinux rules are checked against EL's policy only**
  (dist/selinux check), not yet on an enforcing machine (the VM run).
- **Remote database access is not limited by the firewall yet.** MariaDB
  listens on 3306 and only accepts users from the hosts each account lists,
  but the port itself is not closed to other addresses. The firewall manager
  opens it per listed host (M9).
- **A database over quota keeps growing.** Its space comes off the home's
  quota, so files stop first, but MariaDB itself is not stopped from writing
  once the package's space is used up. Revoking INSERT/UPDATE then is planned
  with the alerts (M10).

- **ZeroSSL's EAB HMAC key is stored in the panel's database in clear.**
  Anyone who can read the panel's database (the panel itself, or root) can
  read it, and could register ZeroSSL accounts in the host's name with it.
  It is never shown again in the panel or the API. Encrypting panel secrets
  at rest with `APP_KEY` closes this (M11).

- **Wildcard names get no certificate yet.** HTTP-01 cannot prove `*.name`,
  so AutoSSL leaves wildcard aliases out. DNS-01 through the local PowerDNS
  adds them (M5).

- **An FTP login's folder is not a hard boundary within its own account.**
  EL's Pure-FTPd is built with a virtual chroot, which follows a link out of
  the folder: a link the account itself made (from its PHP or the file
  manager) can lead a folder-limited login to the rest of the home, or to
  what the account's uid may read elsewhere. It never gives more than the
  account's own uid has; FTP itself cannot make links. Kernel-level link
  protection for homes comes with account isolation hardening (M9).
- **Pure-FTPd runs unconfined.** EL's SELinux policy has no rule for it
  (its binary is `bin_t`), so systemd starts it as `unconfined_service_t`,
  as it does Rspamd. Sessions still drop to the account's uid (privilege
  separation), and the kernel's permissions keep accounts apart. A confined
  domain needs testing on an enforcing machine (`tests/vm/run.sh`).
- **No FTP brute-force blocking yet**, and transfers in the default
  `required` mode may be plain (logins are always encrypted). Blocking comes
  with M9; administrators can choose `strict`.
- **FTP transfers do not count towards bandwidth yet.** Pure-FTPd logs them
  (`/var/log/pureftpd.log`); metering them with web traffic is M10's.

- **Losing the server loses the repository passwords** unless root kept
  them (`lpa backup-password <id>`, as the panel says when a destination is
  added): restic repositories cannot be opened without them. Exporting them
  to the administrator's own records in the Admin panel needs root's
  confirmation; for now another server opens a repository only with the
  password root copies by hand.
- **Certificates are not in backups**: a site made again waits for AutoSSL
  (custom certificates are uploaded again). Sub-users are not made again
  with their account.
- **A download counts towards the account's disk** but is written by root,
  so it may take the account over its quota.
- **Backups are not verified** (`restic check`) on a schedule yet, and a
  failed run is only shown in the Admin panel; alerts are M10's.
- **A database is dumped with `--single-transaction`**: consistent for
  InnoDB tables, not for MyISAM ones written during the dump.

- **A jailed session still reaches the network** like the account's PHP
  does (outgoing connections, local services such as MariaDB and the mail
  ports). Per-account outgoing rules come with the firewall manager (M9).
- **The jail is for SSH only.** Accounts' PHP-FPM pools and cron jobs (M10)
  run unjailed under the account's uid, as before: the kernel's permissions
  keep accounts apart there. A CageFS-like view for PHP is a v2 item.
- **No SSH brute-force blocking yet** (keys only, so guessing is not a
  threat to accounts, but the log noise and root's own login are the
  administrator's): M9. The SSH port is assumed to be 22 in the User panel.
- **Inside the jail, files the system owns show as `nobody`** (root is not
  mapped into the account's user namespace), and setuid tools such as
  `sendmail`, `su` and `sudo` do not work; scripts send mail through SMTP.

- **The panel's nginx and the sites' nginx share the `nginx` user.** With
  nginx serving sites (alone, or in front of Apache as a member of its
  group), the panel's own nginx workers could read what the sites' nginx may
  read: customers' `public_html`. Both are started by root and serve only
  configuration LPanel writes, so this needs a flaw in nginx itself. A
  dedicated user for the panel's nginx closes it (M9).

- **SELinux enforcing has not yet been run on a VM.** The policy module is
  checked against EL9's compiled policy, and that check found and fixed real
  denials. Only a machine with SELinux enforcing proves nothing else gets
  denied at run time: `tests/vm/run.sh` does that, and it has not been run
  yet (M1c).
- **SELinux does not separate the panel's PHP from customers' web servers.**
  From M2, customers' Apache and PHP-FPM also run as `httpd_t`, so at the
  SELinux level they may connect to the agent's socket like the panel does.
  The socket's mode (0660 root:lpanel), SO_PEERCRED and the request
  signature still refuse them. M2 decides whether the panel's own PHP-FPM
  gets a domain of its own.
- **The certificate is self-signed.** Browsers warn once, and admins may learn
  to click through warnings. Closes in M3 (panel hostname certificate).
- **API tokens are not scoped, and do not follow session epochs.** A token
  can do everything its login can, and survives a password change (revoke it
  on the API tokens page). Scopes come in M11.
- **The User panel has no REST API yet.** Sub-user management is in the
  pages only; the User panel API comes with M11's completeness pass.
- **Disabling a reseller leaves their accounts running.** Suspending a
  reseller's customers along with them is a later option.
- **`config/session.php` reads the request path.** Freezing configuration
  with `config:cache` would give both interfaces one cookie again. The
  installer does not run it.
- **Brute-force blocking stops at the panel.** A blocked address can still try
  SSH, FTP and mail. Closes in M9 (firewall-level blocking).
- **Customer isolation is partial.** Homes are 0711 and `public_html` 0750
  with Apache's group, accounts have no login shell unless their package
  grants it, and each account's PHP runs as its own user (M2a). `hidepid`
  and private `/tmp` come in M9, and jailed SSH in M7. Until M7 the `ssh`
  feature gives a plain bash shell.
- **Customers' PHP has wide SELinux booleans.** `httpd_can_network_connect`,
  `_connect_db` and `can_sendmail` are on, because ordinary sites call APIs,
  remote databases and the mail server. SELinux therefore does not limit what
  a customer's PHP may reach on the network; outbound limits come from the
  firewall (M9) and mail limits (M6).
- **Sites are plain HTTP until M3.** Certificates and HTTPS come with AutoSSL.
- **Apache's per-account logs are not rotated yet.** They are written to
  `/var/log/httpd/lpanel/`, which EL's logrotate rule does not cover. M2b
  adds rotation together with bandwidth metering.
- **Ubuntu and Debian are untested.** authd's Debian PAM file exists, but the
  installer refuses anything except AlmaLinux and Rocky 9 (v1.x).
