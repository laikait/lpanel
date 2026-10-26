<?php

declare(strict_types=1);

namespace App\Engine\Queue;

/**
 * What happened to one attempt.
 *
 * Four cases, and every one of them is a thing a person watching a worker wants
 * counted separately. "Processed" alone hides the difference between a queue
 * that is working and one that is retrying the same job forever.
 */
enum JobOutcome: string
{
    /** There was nothing to do. */
    case Idle = 'idle';

    /** It ran and was acknowledged. */
    case Completed = 'completed';

    /** It threw, and has attempts left, so it is back on the queue. */
    case Released = 'released';

    /** It threw for the last time, and is in the failed list. */
    case Failed = 'failed';

    public function isFailure(): bool
    {
        return $this === self::Failed || $this === self::Released;
    }
}
