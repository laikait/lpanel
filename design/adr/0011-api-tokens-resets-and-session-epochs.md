# ADR-0011: API tokens, password resets and session epochs

- **Status:** Proposed. Implemented in M2b (2026-10-27); for the product owner to confirm.
- **Context:** M2b adds two credentials besides the password, API tokens and emailed reset links, and needs a way to end a person's other sessions. Each touches root's special position (ADR-0010): root signs in only with the server's password, from allowed addresses, behind a strict lockout. A second way in as root would bypass all three.

## Decision

**API tokens**

| | |
|---|---|
| Format | `lpat_` + 43 characters of base64url (32 random bytes). Shown once, on the response that creates it (`Cache-Control: no-store`) |
| Storage | `api_tokens.fingerprint` = SHA-256 of the token (`TokenAuthenticator::fingerprint()`); plus the first 12 characters, so people can tell tokens apart. A copy of the table is not a set of working credentials |
| Lifetime | 30, 90, 180 or 365 days. No token lives for ever. At most 25 active per user |
| Rights | the owner's roles **as they are at each request**. A suspended owner's tokens stop working at once. Narrower token scopes (DATA_MODEL `role_id`) are not built yet |
| Where | only under `/api/token/v1` (`'csrf' => false`). With CSRF off, LPHP ignores the session cookie, so a browser cannot be made to call these as whoever is signed in. `/api/v1` stays cookie + CSRF. A token presented to a cookie route still meets the CSRF check |
| Root | **has no tokens.** Automation runs as an administrator |
| Audit | an action through a token is recorded with `actor_type = token` and `via: api:<token id>` |
| Revocation | by the owner (tokens page), or by an administrator with "sign out everywhere" |

**Password reset by email**

- LPHP's `Auth\PasswordReset`: a stateless link signed with APP_KEY, valid for one hour, bound to the current password hash. Setting a password spends it and every other outstanding link. No table.
- The forgot form gives the same answer for every login, registered or not. It is limited to 3 per login and 10 per address per 15 minutes.
- **Root never gets a link.** `emailFor()` returns null for the system account, and `resetPassword()` refuses it. Root's password is changed on the server with `passwd`.
- The reset page sends `Referrer-Policy: no-referrer`, because the token is in its URL.
- A reset does not sign anyone in. The person signs in with the new password, and their second factor if they have one.

**Session epochs**

- `users.session_epoch` is an integer. A session stores it at login (`auth.login` → `identity.epoch`).
- On each request, `PanelUserProvider::byId()` treats the session's own user as nobody when the numbers differ. The guard then sends a page to the sign-in screen, and an API gets 401.
- The number moves on a password reset, a password set by an administrator, a password change (the session that made the change is carried over under a new id), and "sign out everywhere" (which also revokes the user's tokens).
- Suspension needs no epoch: a suspended account is inactive, so no session or token names anyone.

**Who may change whom** (`Users\UserAdmin`, shared by the screens and the API)

- Root's row is never changed through the panel.
- Only root creates, changes or grants administrators.
- Nobody changes their own roles or status on the users screens.
- Every change, allowed or refused, is audited.

## Consequences

- Root's only ways in stay those of ADR-0010.
- Root cannot script the panel API directly. An administrator account is the documented route.
- Changing root's password on the server does not end root's panel sessions. Root sessions are bounded by the 12-hour absolute lifetime (`config/session.php`). Re-checking with `lpanel-authd` on each request was rejected: it would put PAM on every page load.
- Narrower token scopes need a design (role per token, or capability lists) before customers get API access in M6.
