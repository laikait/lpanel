<?php

declare(strict_types=1);

namespace App\Engine\Scheduler;

/**
 * What happened to one scheduled task.
 *
 * Skipped is the case worth having separately. A task that did not run because
 * the previous run of itself is still going is not a failure and is not a
 * success, and collapsing it into either is how a schedule that has silently
 * stopped keeping up looks fine on a dashboard. `schedule:list` counts them on
 * their own for exactly that reason.
 */
enum ScheduleOutcome: string
{
    /** It ran here, and returned without throwing. */
    case Ran = 'ran';

    /** It was handed to the queue. Whether that means "ran" depends on the store. */
    case Queued = 'queued';

    /** A lock or a condition said not this time. Nothing ran. */
    case Skipped = 'skipped';

    /** It ran and threw, or a command exited non-zero. */
    case Failed = 'failed';

    public function isFailure(): bool
    {
        return $this === self::Failed;
    }
}
