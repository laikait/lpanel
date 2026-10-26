# ADR-0001: LPA is written in Go

- **Status:** Accepted (product owner, 2026-10-26)
- **Context:** LPA runs on every managed server with elevated privileges. Plan §3.2 forbids it sharing LPanel's PHP runtime or privileges. The options were PHP (reusing `engine/System`), Go or Rust.

## Decision

LPA is a Go program, in its own repository (`laikait/lpa`). It ships as a static binary in a `.deb` package for each supported OS ([ADR-0004](0004-os-matrix.md)).

## Consequences

- Hosting servers need no PHP just for the agent, and the agent's PHP can never collide with customer PHP-FPM versions.
- A second language and toolchain. Protocol types are defined once in [AGENT_PROTOCOL.md](../AGENT_PROTOCOL.md) and kept in sync by golden test vectors (JSON fixtures signed by each side and checked by the other side's tests).
- `engine/System` is still the reference for the rules LPA must meet: argv only, no shell, timeouts, output caps, a minimal environment, allowlisted services, contained paths, and audit without argument values. Its code is not reused.
- Use the Go standard library where it is enough (`crypto/ed25519`, `crypto/tls`, `net/http`, `os/exec` with argv). Every third-party module needs a reason in `go.mod` review, and must pass `govulncheck`.
