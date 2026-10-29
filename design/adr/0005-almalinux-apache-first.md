# ADR-0005: AlmaLinux 9 + Apache first; OS matrix

- Status: Accepted (2026-10-29)

## Context
Most cPanel servers run AlmaLinux, Rocky or CloudLinux with Apache. They are
the servers our customers will migrate from.

## Decision
- Development and tests target **AlmaLinux 9 with Apache 2.4 + PHP-FPM** first.
- v1 supports AlmaLinux/Rocky 8, 9 and 10.
- v1.x adds Ubuntu 22.04, 24.04 and 26.04 and Debian 12 and 13.
- v2 adds CloudLinux with LVE/CageFS.
- All three web stacks ship in v1, chosen per server:
  - Apache only
  - Nginx only
  - Nginx in front of Apache

  OpenLiteSpeed/LiteSpeed comes in v2.
- PHP comes from Remi on EL and Sury on Debian/Ubuntu, with several versions
  installed side by side.
- Every OS difference (package names, paths, service names, firewall tool) sits
  behind one `platform` package in the agent. The panel never sees it.

## Consequences
- Package tests run in AlmaLinux 9 containers or VMs from M0 on. Other
  OSes are added to the same suite later.
