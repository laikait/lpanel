# ADR-0002: Panel in PHP (LPHP), agent in Go

- Status: Accepted (2026-10-29)

## Context
The panel is a large web application: many screens, forms, permissions and
translations. The work that changes the system needs root, must be
predictable, and should ship as a single binary with no runtime to install.

## Decision
- The panel is built on the LPHP framework in this repo (PHP 8.3, one module
  per feature area). The framework's `engine/` is not edited.
- All privileged work is done by `lpa`, a Go program running as root.
  `lpanel-authd`, a separate small Go program, checks passwords.

## Consequences
- The panel runs as an unprivileged `lpanel` user. A bug in PHP code cannot
  touch the system except through the agent's fixed list of operations.
- Two languages to maintain. Shared contracts (operation names, argument
  schemas, signature test vectors) live in the agent repo and are copied into
  this repo's test fixtures.
