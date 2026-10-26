# ADR-0004: Supported operating systems for the first release

- **Status:** Accepted (product owner, 2026-10-26). Revised the same day: the first version listed only Ubuntu 24.04 and Debian 12, before it was noticed that Ubuntu 26.04 LTS and Debian 13 (current stable since 2025-08) exist.
- **Context:** LPHP needs PHP `^8.2`. Ubuntu 22.04 ships PHP 8.1, so it is excluded.

## Decision

| OS | Status upstream (2026-10) | Panel host | Managed server (LPA) |
|---|---|---|---|
| Ubuntu 24.04 LTS "noble" (amd64) | LTS | supported | supported |
| Ubuntu 26.04 LTS (amd64) | LTS (newest) | supported | supported |
| Debian 12 "bookworm" (amd64) | oldstable | supported | supported |
| Debian 13 "trixie" (amd64) | stable | supported | supported |

Everything else is unsupported, including arm64. The installer refuses an unsupported OS unless given an explicit override flag, which is logged.

LPA is one static Go binary, so **one `.deb` is built and tested on all four** instead of one per OS. If a distro ever needs different maintainer scripts, the package splits.

## Distro facts

Measured by `test/packages/run.sh` in the lpa repository, which prints them from
a clean container on every run (2026-10-27):

| Package | Ubuntu 24.04 | Ubuntu 26.04 | Debian 12 | Debian 13 |
|---|---|---|---|---|
| PHP (default) | 8.3 | 8.5 | 8.2 | 8.4 |
| Nginx | 1.24.0 | 1.28.3 | 1.22.1 | 1.26.3 |
| Apache | 2.4.58 | 2.4.66 | 2.4.68 | 2.4.69 |
| MariaDB | 10.11.14 | 11.8.6 | 10.11.19 | 11.8.6 |
| systemd | 255 | 259 | 252 | 257 |

LPHP's PHPStan range is `80200`–`80500` (`phpstan.neon`), so every distro default (8.2–8.5) is inside it. Ubuntu 26.04's 8.5 is at the top edge: run LPanel's suite on it before calling the panel host supported there.

Customer PHP is only the distro default in MVP. Multi-version PHP becomes a later ADR.

## Consequences

- Package tests run on `ubuntu:24.04`, `ubuntu:26.04`, `debian:12` and `debian:13` Docker images, booted with systemd as PID 1 (privileged container, cgroup v2).
- Windows/XAMPP is a development host for the PHP panel UI only.
