# ADR-0010: Root logs in with the server's root password, checked by `lpanel-authd`

- **Status:** Accepted (product owner, 2026-10-27: "use root like cPanel", plus an IP allowlist and a strict lockout for root)
- **Context:** WHM's administrator logs in as the system's `root` with its real password. The panel itself never runs as root (plan §3.2), and the `lpanel` user can't read `/etc/shadow`. Checking root's password therefore needs a privileged process. Every other design rule still applies: no shell, smallest possible surface, and a compromised panel must not become a password-guessing oracle.

## Decision

A separate, root-owned helper, **`lpanel-authd`**, answers exactly one question: *is this the password of this allowlisted system user?*

| | |
|---|---|
| Language | Go, with cgo against `libpam` (no third-party modules). Built on Debian 12, the oldest glibc in the matrix (ADR-0004) |
| Transport | Unix socket `/run/lpanel-authd/authd.sock`, created by `lpanel-authd.socket` with `SocketUser=root`, `SocketGroup=lpanel`, `SocketMode=0660`; handed over by socket activation, so the daemon needs no capabilities to set ownership |
| Peer check | `SO_PEERCRED` uid must be the `lpanel` user's; anything else is refused before the request is read |
| Request | one line of JSON, at most 4 KiB, 5 s to arrive: `{"v":1,"user":"root","password":"…","ip":"203.0.113.9"}` |
| Answer | `{"v":1,"ok":true}` or `{"v":1,"ok":false,"code":"denied|locked|ip_not_allowed|bad_request|unavailable","retry_after":0}` |
| Who can be checked | only users in `/etc/lpanel/authd.json` → `users` (default `["root"]`), so the socket is not an oracle for every system account |
| How | PAM service `lpanel` (`/etc/pam.d/lpanel`: `@include common-auth`, `@include common-account`): `pam_authenticate` with `PAM_DISALLOW_NULL_AUTHTOK`, then `pam_acct_mgmt`, with `PAM_RHOST` set to the client IP. Calls are serialised; the password buffer is zeroed after use |
| Root IP allowlist | `allow_from` per user, a list of CIDRs; empty means anywhere. Checked before PAM |
| Strict lockout | 5 failures from one IP within 15 min block that IP for 15 min (configurable) |
| Global limit | at most 30 checks per minute in total, whatever IP the panel reports. A compromised panel process can lie about the IP, so per-IP limits alone would not stop it brute-forcing root through the socket |
| Logging | journald: user, IP, outcome. Never the password |
| Sandbox | `PrivateNetwork=yes`, `RestrictAddressFamilies=AF_UNIX`, `ProtectSystem=strict`, `ProtectHome=yes`, `NoNewPrivileges=yes`, no capabilities. Root reads `/etc/shadow` as its owner; the only capability kept is `CAP_AUDIT_WRITE`, for PAM's audit records (netlink) |

**Panel side.** In the Identity module:

- `root` is a row in `users` with `kind = system` and **no password hash**. LPHP's `AuthManager::attempt()` refuses an account without a hash (`Account::canUsePassword()`), so a panel password can never log in as root.
- When the login name is `root`, `RootLogin` asks the `RootVerifier`. On `ok`, it hands the account to `TwoFactor::start()`, so TOTP applies to root too once enabled. That ends in `AuthManager::login()`, which regenerates the session.
- The `root` role (`*`) is attached by the provider, not stored. It can't be removed in the database or granted to anyone else.
- The answer shown to the user is always the same generic failure. The reason (`denied`, `locked`, `ip_not_allowed`) goes only to the audit log.

**Development.** Windows has no PAM. `Identity.root.verifier = 'dev'` checks a bcrypt hash from config instead. It is accepted only when `app.env` is `local` or `testing`; any other environment refuses to boot with it.

## Consequences

- One more root process on the panel host, but it has no network access, a fixed question, and a fixed list of users.
- Two-factor stays optional (product owner, 2026-10-27). Root is protected by the system password, the IP allowlist, the strict lockout and the global limit.
- Customers' panel logins use panel passwords for now. Whether hosting accounts later log in with their Linux passwords, as in cPanel, is a separate decision for M6. `authd`'s user allowlist is where that would be widened.
- The LPanel package (M5) installs the two units, the PAM file and `authd.json`. M2a ships the source, unit files and tests.
