# ADR-0006: Mail with Exim + Dovecot, Rspamd + ClamAV

- Status: Accepted (2026-10-29)

## Context
Exim + Dovecot is the most common mail stack on the shared-hosting servers our
customers migrate from. Importing their account backups (v1.x) is easier when
the mail layout and routing rules match. The alternative, Postfix, is simpler
but would mean translating per-domain routing, forwarders and filters during
every migration.

## Decision
- MTA: Exim, with LPanel-generated config driven by flat lookup files under
  `/etc/lpanel/mail/` (domains, aliases, forwarders, limits).
- IMAP/POP3: Dovecot with Maildir under `/home/<user>/mail/<domain>/<box>`.
- Spam: Rspamd. Viruses: ClamAV. Filters: Sieve (Pigeonhole).
- DKIM signing is done by Exim, with one key for each domain.

## Consequences
- Outbound limits per account and delivery tracking use Exim's own
  features.
- Maildir data from other Exim + Dovecot servers imports with path changes
  only.
