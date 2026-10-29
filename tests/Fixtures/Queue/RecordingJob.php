<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Queue;

use App\Engine\Queue\Job;

/**
 * A job that says it ran.
 *
 * Static, because the whole point of a queue is that the object that runs is
 * not the object that was dispatched -- it is one rebuilt from a payload in
 * another process. A property could not carry the answer back.
 */
final class RecordingJob implements Job
{
    /** @var list<string> */
    public static array $ran = [];

    public function __construct(public readonly string $label = 'job') {}

    public function handle(): void
    {
        self::$ran[] = $this->label;
    }

    public static function reset(): void
    {
        self::$ran = [];
    }
}
