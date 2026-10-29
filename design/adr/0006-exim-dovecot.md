# ADR-0006: Mail with Exim + Dovecot, Rspamd + ClamAV

- Status: Accepted (2026-10-29)

## Context
cPanel uses Exim and Dovecot. Importing cpmove archives (v1.x) is easier when
the mail layout and routing rules match. The alternative, Postfix, is simpler
but would mean translating cPanel's per-domain routing, forwarders and
filters.

## Decision
- MTA: Exim, with LPanel-generated config driven by flat lookup files under
  `/etc/lpanel/mail/` (domains, aliases, forwarders, limits).
- IMAP/POP3: Dovecot with Maildir under `/home/<user>/mail/<domain>/<box>`.
- Spam: Rspamd. Viruses: ClamAV. Filters: Sieve (Pigeonhole).
- DKIM signing is done by Exim, with one key for each domain.

## Consequences
- Outbound limits per account and delivery tracking use Exim's own
  features.
- The cPanel mail layout imports with path changes only.
