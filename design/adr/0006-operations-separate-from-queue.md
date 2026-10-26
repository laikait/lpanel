# ADR-0006: Agent operations have their own state machine, separate from the LPHP queue

- **Status:** Accepted (product owner, 2026-10-27)
- **Context:** Plan §10 needs these states: `queued → dispatched → running → succeeded | failed | cancelled | needs_attention`. It also needs leases, idempotency keys, partial failure, and *no blind retry of non-idempotent work*. The LPHP queue retries anything that throws, until its attempts run out, and never looks at why (`docs/reference/queue.md:101-121`). Its table is named `jobs` by default. And agents pull their work ([ADR-0002](0002-agent-pull-transport.md)), so no PHP worker hands operations to agents.

## Decision

- The **Operations** module owns `operations` and `operation_events`, see [DATA_MODEL.md](../DATA_MODEL.md). The word "job" is kept for LPHP queue jobs only.
- An operation's lifecycle:
  `queued → claimed → running → succeeded | failed | cancelled | needs_attention`, plus `expired` (never claimed before `not_after`).
- **Claim = lease.** An agent claims with a guarded `UPDATE`, the same pattern as LPHP's DB queue (`lockForUpdate` plus a conditional update). The lease has an expiry, which the agent extends while it runs.
- **When a lease lapses:**
  - an idempotent operation goes back to `queued` with `attempt + 1`, until `max_attempts`;
  - a non-idempotent one goes to `needs_attention`.

  A scheduled LPHP task (`$module->schedules()`) sweeps lapsed leases every minute.
- The LPHP queue (`database` store) is still used for panel-side background work: sending mail, alert fan-out, pruning.

## Consequences

- Retry policy is per operation and explicit, because the operation definition declares `idempotent` ([AGENT_PROTOCOL.md §6.2](../AGENT_PROTOCOL.md#62-operation-definitions)).
- The agent is the second line of idempotency: it keeps a local journal of `idempotency_key → result`, so a re-delivered operation returns the stored result instead of running again.
- Two mechanisms with similar names. Naming discipline is enforced in review: Operations module code never imports `App\Engine\Queue\*`.
