<?php

declare(strict_types=1);

namespace App\Engine\System\Audit;

use App\Engine\Hook\HookEngine;

/**
 * Where system operations announce what they did.
 *
 *     $audit = new SystemAudit($hooks);
 *     $services = new ServiceManager($executor, $policy, audit: $audit);
 *
 * **It announces; it does not write.** Every record is fired on the
 * `system.audit` hook, and Logging\SystemAuditLog -- attached at bootstrap --
 * writes it to the `audit` channel. That is the scheduler's arrangement with
 * the log, for the same reason: engine/System never holds a logger, and an
 * application that wants audit records somewhere else, or somewhere as well,
 * adds a listener.
 *
 * **Opt-in, per manager.** Each manager takes a SystemAudit or nothing. Records
 * are made for everything that changes the machine or is refused -- a command
 * run, a service changed, a cron job installed, a file written, a mode changed,
 * an authorization decided -- and never for reads.
 */
final class SystemAudit
{
    public const HOOK = 'system.audit';

    public function __construct(private readonly HookEngine $hooks) {}

    /** @param array<string, string|int|float|bool|null> $context no secret values -- see AuditRecord */
    public function record(string $event, AuditOutcome $outcome, ?string $target = null, array $context = []): void
    {
        $this->hooks->do(self::HOOK, new AuditRecord($event, $outcome, $target, $context));
    }
}
