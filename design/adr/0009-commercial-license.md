# ADR-0009: Commercial license per server

- Status: Accepted (2026-10-29)

## Context
LPanel is sold commercially, per server, like cPanel.

## Decision
- Each server needs a license key, which is bound to the server's main IP and
  a machine id.
- The panel checks the key against the LPanel license server at activation and
  every 24 hours. The license server returns a signed license file (Ed25519)
  that the panel verifies offline.
- If the license server can't be reached, a 7-day grace period applies. After
  that, admin screens show a banner and new accounts can't be created. Existing
  sites, mail and DNS keep running, because customers' websites must never stop
  because of licensing.
- The license server is a separate project. It is not part of this repo.
- A 15-day trial key is issued from the installer.

## Consequences
- License tiers (for example by account count) are fields in the signed
  license, so prices can change without a panel release.
- The license check sits in the `System` module, away from the agent: a
  licensing bug can never stop system operations.
