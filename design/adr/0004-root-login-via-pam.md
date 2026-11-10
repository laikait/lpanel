# ADR-0004: Root logs in with the server's real root password

- Status: Accepted (2026-10-29)

## Context
Hosting companies' admins are used to logging in to their server panel as
`root` with the server's root password. Their runbooks assume it.

## Decision
- The username `root` on the admin port is checked by `lpanel-authd`. It is a
  socket-activated root helper that runs PAM service `lpanel` and answers only
  yes or no. Nobody stores, logs or caches the password.
- Optional TOTP 2FA applies on top. The admin can make it mandatory.
- Staff who should not know the root password get panel admin users with
  roles instead.
- Failed root logins feed the brute-force blocker.

## Consequences
- Changing the root password on the server changes the panel login, and all
  root sessions end.
- The authd design from the earlier attempt (git history, `helpers/authd`) is
  reused.
