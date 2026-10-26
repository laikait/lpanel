<?php

declare(strict_types=1);

namespace App\Engine\Logging;

use App\Engine\Scheduler\ScheduleOutcome;
use App\Engine\Scheduler\ScheduleResult;

/**
 * The bridge from schedule.finished to the log.
 *
 * ErrorLog's twin, and the same demonstration: the scheduler announces, logging
 * listens, and engine/Scheduler contains no reference to a logger. Delete this
 * class and schedules stop being logged; nothing else changes.
 *
 * It earns its place more obviously than most listeners. Work that runs at
 * three in the morning has no operator and no response -- if it is not written
 * down, nothing happened as far as anybody can ever tell. That is the ordinary
 * failure of cron: output goes to a MAILTO nobody set, and a task that stopped
 * working six weeks ago looks exactly like one that has nothing to do.
 *
 * The level says what happened, so a log filtered to warnings and above is
 * still the right place to look:
 *
 *   failed     error    the task threw, or a command exited non-zero
 *   skipped    notice   a lock or a condition said no; not an incident, but
 *                       a schedule that is always skipped is a problem
 *   otherwise  info     it ran
 */
final class ScheduleLog
{
    public const CHANNEL = 'schedule';

    public function __construct(private readonly LogManager $logs) {}

    public function __invoke(ScheduleResult $result): void
    {
        $context = $result->toArray();

        if ($result->error !== null) {
            $context['exception'] = $result->error;
        }

        // Whatever the task printed. Captured rather than discarded, because on
        // a normal machine this is the only copy: nothing is attached to the
        // scheduler's stdout at the moment a cron line runs it.
        if ($result->output !== '') {
            $context['output'] = \trim($result->output);
        }

        $this->logs->channel(self::CHANNEL)->log(
            self::levelFor($result->outcome),
            \sprintf('Schedule %s %s', $result->id(), $result->outcome->value),
            $context,
        );
    }

    public static function levelFor(ScheduleOutcome $outcome): Level
    {
        return match ($outcome) {
            ScheduleOutcome::Failed => Level::Error,
            ScheduleOutcome::Skipped => Level::Notice,
            ScheduleOutcome::Ran, ScheduleOutcome::Queued => Level::Info,
        };
    }
}
