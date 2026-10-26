<?php

declare(strict_types=1);

namespace App\Engine\Logging;

use App\Engine\System\Audit\AuditOutcome;
use App\Engine\System\Audit\AuditRecord;

/**
 * The bridge from system.audit to the log.
 *
 * ScheduleLog's arrangement again: engine/System announces what it did and
 * holds no logger; this listens and writes. Records go to their own `audit`
 * channel, so they can be sent somewhere longer-lived than the application log
 * by the channel name alone.
 *
 * The level says who should read it:
 *
 *   refused    warning   a security event: a policy, allowlist or role said no
 *   failed     error     an operation that ran and did not work
 *   otherwise  info
 */
final class SystemAuditLog
{
    public const CHANNEL = 'audit';

    public function __construct(private readonly LogManager $logs) {}

    public function __invoke(AuditRecord $record): void
    {
        $this->logs->channel(self::CHANNEL)->log(
            self::levelFor($record->outcome),
            \sprintf('%s %s', $record->event, $record->outcome->value),
            $record->toArray(),
        );
    }

    public static function levelFor(AuditOutcome $outcome): Level
    {
        return match ($outcome) {
            AuditOutcome::Refused => Level::Warning,
            AuditOutcome::Failed => Level::Error,
            AuditOutcome::Started, AuditOutcome::Succeeded => Level::Info,
        };
    }
}
