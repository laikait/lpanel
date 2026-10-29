# ADR-0008: Disk limits with kernel filesystem quotas

- Status: Accepted (2026-10-29)

## Context
Hosting companies sell disk space and need it enforced as a hard limit. The
alternative is to scan usage periodically and suspend accounts over their
limit, but that is not a real limit.

## Decision
- User quotas (blocks and inodes) on the filesystem holding `/home`:
  - XFS: `uquota` mount option. This is the AlmaLinux default.
  - ext4: `usrquota`.
- The installer checks for quota support and offers to enable it, which needs
  a reboot for `/` on XFS. Installation stops with a clear message if quotas
  can't be enabled.
- Database and mail sizes count toward the quota:
  - Mail lives in the user's home, so the kernel counts it.
  - Database size is measured and added to the displayed usage. MariaDB files
    belong to the `mysql` user, so the kernel can't count them.

## Consequences
- Usage is shown live from the kernel. Warning emails go out at 80%, 90% and
  100%.
- A server without quota support can't host accounts. This is intentional.
