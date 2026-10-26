<?php

declare(strict_types=1);

namespace App\Engine\Scheduler;

/**
 * What one scheduled task did, kept so that something else can report it.
 *
 * The `output` field is the point of this class existing at all. A cron line
 * that runs a command writes its output to whatever the crontab's MAILTO says,
 * which on almost every machine is nowhere -- so the single most common way to
 * lose the explanation of a failure is to have scheduled the thing that printed
 * it. The scheduler captures it into here instead, and the log listener writes
 * it out with everything else.
 */
final class ScheduleResult
{
    public function __construct(
        public readonly Schedule $schedule,
        public readonly ScheduleOutcome $outcome,
        public readonly string $message = '',
        public readonly string $output = '',
        public readonly float $seconds = 0.0,
        public readonly ?\Throwable $error = null,
    ) {}

    public function id(): string
    {
        return $this->schedule->id();
    }

    public function failed(): bool
    {
        return $this->outcome->isFailure();
    }

    /** Milliseconds, which is the unit a schedule's duration is readable in. */
    public function milliseconds(): int
    {
        return (int) \round($this->seconds * 1000);
    }

    /** @return array<string, mixed> for a log record's context */
    public function toArray(): array
    {
        return [
            'schedule' => $this->id(),
            'module' => $this->schedule->module,
            'outcome' => $this->outcome->value,
            'runs' => $this->schedule->summary(),
            'ms' => $this->milliseconds(),
            'message' => $this->message,
        ];
    }
}
