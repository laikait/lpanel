# ADR-0002: The agent pulls work from the panel

- **Status:** Accepted (product owner, 2026-10-26)
- **Context:** Plan §3.1 describes LPanel dispatching to LPA, while §12 prefers outbound agent connections. LPHP has no resident process and no long-lived connections (`docs/what-is-not-built.md`), so it can't hold open sockets to agents.

## Decision

LPA opens every connection, outbound to LPanel over HTTPS. It sends heartbeats, claims queued operations, extends their leases and posts results. LPanel never connects to a managed server.

Authentication is layered:

1. **TLS server authentication.** The agent pins the panel's certificate (SPKI SHA-256 fingerprint), learned during enrollment.
2. **TLS client certificate.** The agent's certificate is self-signed. Panel Nginx requests it with `ssl_verify_client optional_no_ca` and passes `$ssl_client_fingerprint` to PHP, which matches it to the agent's record. This is mTLS without running a CA.
3. **Ed25519-signed envelopes in both directions.** Agent requests are signed with the agent key. Every operation is signed with the panel's operation-signing key, and the agent verifies that signature before acting.

The full wire format is in [AGENT_PROTOCOL.md](../AGENT_PROTOCOL.md).

## Consequences

- Managed servers open no management port.
- Latency depends on the poll interval. MVP uses short polling: idle 5s ± jitter, an immediate re-poll after an operation, and backoff when the panel is unreachable. Long polling would tie up one PHP-FPM worker per agent and is deferred.
- The "dispatched" state of plan §10 becomes "claimed". A claim is a lease ([ADR-0006](0006-operations-separate-from-queue.md)).
- The panel signs operations, so a party that can inject HTTP responses but doesn't hold the panel signing key still can't make an agent act. The signing key is kept outside the database ([THREAT_MODEL.md](../THREAT_MODEL.md)).
- A local same-host deployment uses the same HTTPS path to `127.0.0.1:8443`. A Unix-socket mode is not built for MVP.
