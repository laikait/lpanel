# ADR-0005: LPanel switches off `engine/System`

- **Status:** Accepted (product owner, 2026-10-27)
- **Context:** `engine/System` lets the PHP process run commands, restart services and write files. By default `system.commands.allowed` is `null`, meaning anything on PATH may run (`docs/reference/system.md:230-262`). Plan §1.1 and §3.2 say LPanel has no direct root access and makes no system mutations. All of that belongs to LPA.

## Decision

Ship `config/system.php` with `'enabled' => false`. No LPanel module depends on a `System\*` manager.

Nothing that needs those managers runs in production:

- The panel's own systemd units (queue worker, scheduler) and its cron line are installed by the **LPanel package's install script**, not by `php laika system:*` at runtime.
- `system:info` for the panel host comes from the LPA that runs on it, like any other server.

## Consequences

- A remote-code-execution bug in a panel request can't reach a ready-made command runner.
- A test checks that `config/system.php` keeps `enabled => false`, and that no module under `modules/` imports `App\Engine\System\`.
- Developers can't use `php laika system:systemd:install` for the panel. The install script does that job instead.
