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
| T22 | A lost or repeated agent answer making an account twice, or leaving the panel and the server disagreeing | The operation and its id are written before sending; retries reuse the id and the journal answers with the first outcome | `AccountsTest::test_an_unreachable_agent_records_the_change_and_settle_finishes_it_exactly_once`, e2e agent "the same id again gets the first answer" |

## Known gaps

These are tracked, not forgotten. Each names the milestone that closes it.

- **SELinux enforcing is not yet verified.** The installer sets port and file
  labels. Whether the policy allows the custom PHP-FPM unit to reach the two
  sockets and MariaDB has only been tested in containers, where SELinux is off.
  M0c's container tests do not cover it. Closes with the first VM test (M1).
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
- **Customer isolation is partial.** Homes are 0711 and `public_html` 0750,
  and accounts have no login shell unless their package grants it. Per-user
  PHP-FPM arrives in M2, `hidepid` and private `/tmp` in M9, and jailed SSH in
  M7. Until M7 the `ssh` feature gives a plain bash shell.
- **Ubuntu and Debian are untested.** authd's Debian PAM file exists, but the
  installer refuses anything except AlmaLinux and Rocky 9 (v1.x).
