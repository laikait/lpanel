# ADR-0007: DNS with PowerDNS (database backend)

- Status: Accepted (2026-10-29)

## Context
cPanel uses BIND zone files. PowerDNS keeps zones in a database and has an
HTTP API. It also supports native and catalog-zone replication, which makes
the v2 DNS cluster much simpler.

## Decision
- PowerDNS Authoritative with the MySQL backend, in its own `pdns` database on
  the server's MariaDB.
- The agent writes zones through the PowerDNS API, bound to localhost with an
  API key only the agent holds. The panel never connects to PowerDNS directly.
- DNSSEC (v1.x) uses PowerDNS's built-in signing.
- cpmove BIND zone files are parsed and imported record by record.

## Consequences
- Zone changes are atomic and immediate; no zone-file reloads.
- Wildcard certificates via DNS-01 are a local API call.
