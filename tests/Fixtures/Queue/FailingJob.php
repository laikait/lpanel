<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Queue;

use App\Engine\Queue\Job;

/** A job that always throws, for the retry and failure paths. */
final class FailingJob implements Job
{
    public static int $attempts = 0;

    public function __construct(public readonly string $reason = 'it went wrong') {}

    public function handle(): void
    {
        ++self::$attempts;

        throw new \RuntimeException($this->reason);
    }

    public static function reset(): void
    {
        self::$attempts = 0;
    }
}
