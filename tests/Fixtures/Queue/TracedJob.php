<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Queue;

use App\Engine\Observability\Trace;
use App\Engine\Observability\Tracer;
use App\Engine\Queue\Job;

/** A job that remembers which unit of work it ran as, so a test can check the correlation survived. */
final class TracedJob implements Job
{
    public static ?Trace $ranAs = null;

    public function __construct(private readonly bool $fail = false) {}

    public function handle(Tracer $tracer): void
    {
        self::$ranAs = $tracer->current();

        if ($this->fail) {
            throw new \RuntimeException('the traced job failed on purpose');
        }
    }

    public static function reset(): void
    {
        self::$ranAs = null;
    }
}
