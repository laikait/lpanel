# ADR-0003: Panel talks to the agent over a local Unix socket

- Status: Accepted (2026-10-29)

## Context
The earlier attempt used a pull transport: the agent enrolled with a central
panel over mTLS and fetched work from it. With one install per server
(ADR-0001), that transport isn't needed and is pure risk.

## Decision
- `lpa` listens on `/run/lpa/lpa.sock`. The socket is owned by root, with
  group `lpanel` and mode 0660.
- On every connection the agent checks the caller with `SO_PEERCRED`. Only the
  `lpanel` uid (panel PHP-FPM, CLI and scheduler) is accepted.
- Every request is signed with an Ed25519 key that only the panel can read
  (`/etc/lpanel/agent.key`, 0600, owner `lpanel`). The agent checks the
  signature, timestamp and nonce. This means a different process that somehow
  gains the `lpanel` group still can't send commands.
- Requests name an operation from a fixed registry and carry typed arguments
  and an idempotency key. The agent journals every operation and its result.
- Long operations return at once with an operation id. The panel polls for
  the result or receives it as an event.

## Consequences
- Nothing is reachable over the network except the panel's HTTPS ports.
- The enrolment, heartbeat and key-rotation code from the old `lpa` is dropped.
  The `execd` socket and peer-credential code is reused.
