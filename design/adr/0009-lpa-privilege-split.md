# ADR-0009: LPA is two processes, an unprivileged network agent and a privileged executor

- **Status:** Accepted (product owner, 2026-10-26). Built in M6a; the socket protocol is [ADR-0013](0013-hosting-accounts-on-a-server.md), which also has `lpa-exec` verify the panel's signature itself, so a compromised `lpa-agent` gets less than this ADR allowed for.
- **Context:** LPA must parse network input: TLS, JSON and signatures. It must also, later, create users, write `/etc/nginx` and reload services. If one root process does both, a parser bug becomes root.

## Decision

| Process | Runs as | Does | Doesn't |
|---|---|---|---|
| `lpa-agent` | `lpa` (system user, no shell) | TLS to the panel, signature checks, schema validation, journal, read-only operations that need no privilege | touch privileged paths; it has no capabilities |
| `lpa-exec` | root, systemd-hardened | runs **only** registry operations received over a local Unix socket (`/run/lpa/exec.sock`, `0660 root:lpa`), after checking the peer with `SO_PEERCRED` (must be the `lpa` uid) and re-validating the payload | open network sockets (`RestrictAddressFamilies=AF_UNIX`), accept any operation not in its compiled-in registry |

- `lpa-exec` re-validates everything itself, including schemas, path containment and identifier formats. It doesn't trust `lpa-agent`.
- Phase 1 has read-only operations only. If none of them needs root, `lpa-exec` can ship in the first mutating milestone. The socket protocol is still defined now.
- systemd hardening for both units: `NoNewPrivileges`, `ProtectSystem=strict` with explicit `ReadWritePaths`, `PrivateTmp`, `ProtectHome` (relaxed for `lpa-exec` once it manages home directories), `CapabilityBoundingSet` trimmed per unit, and `SystemCallFilter=@system-service`. Validate every directive on both OSes ([ADR-0004](0004-os-matrix.md)).

## Consequences

- An extra process and socket to package and test.
- A compromised `lpa-agent` can ask for allowlisted operations, the same power a compromised panel has, but gets no root shell.
