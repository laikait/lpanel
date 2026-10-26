# ADR-0007: Nginx hosting adapter first; Apache as the next milestone

- **Status:** Accepted (product owner, 2026-10-27)
- **Context:** Plan §3.4 wants Nginx and Apache both offered at onboarding in the first release. Each adapter needs its own templates, candidate validation, rollback, drift detection, isolation tests and end-to-end tests. Doing both at once doubles the riskiest phase.

## Decision

- Define the hosting-backend adapter contract in LPA first:
  - `capabilities`, `preflight`, `install`
  - `render(website settings) → candidate files`, `validate(candidate)`
  - `apply`, `health`, `drift`, `rollback`
- Implement **Nginx** against that contract in the website milestone.
- **Apache** is the milestone straight after. It must pass the same contract test suite before the public beta.
- The onboarding UI shows both choices from the start, with Apache marked "not available yet" until its adapter ships. The data model stores `desired_backend` / `detected_backend` from day one.
- OpenLiteSpeed stays out of scope until after the beta.

## Consequences

- The public beta still offers Nginx and Apache, as the plan requires. Only the build order changes.
- A server switching backends (a migration, plan §3.4) needs both adapters, so it ships with or after Apache.
