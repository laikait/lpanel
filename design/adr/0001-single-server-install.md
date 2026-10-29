# ADR-0001: One install manages one server

- Status: Accepted (2026-10-29)

## Context
A panel can be built in one of two ways:

- **Central:** one panel drives many servers through remote agents, like
  Virtualmin Cloudmin or RunCloud.
- **Local:** the panel is installed on every server, like cPanel/WHM, Plesk
  and DirectAdmin.

Our customers are hosting companies moving away from cPanel. Their staff and
end users expect the local model. Their billing systems expect to call each
server directly.

## Decision
LPanel is installed on each server and manages only that machine. There is no
central controller in v1.

Features that involve more than one server are add-ons built on the public API
of each server:

- server-to-server account transfer (v2)
- the DNS cluster (v2)

## Consequences
- There is no network transport between panel and agent, so no enrolment and no
  mTLS (see ADR-0003). This is a big reduction in attack surface and in work.
- Each server needs its own license (ADR-0009).
- A future "fleet view" would be a separate product that calls each server's
  API. It does not change this design.
