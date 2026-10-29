<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Scheduler;

use App\Engine\Queue\Job;

/**
 * A job that cannot be scheduled, because it needs to be told something.
 *
 * Perfectly good as a queued job -- whatever knows the invoice pushes it. A
 * schedule knows only the clock, so there is nothing it could pass, and the
 * scheduler refuses the declaration rather than discovering it at 3am.
 */
final class UnschedulableJob implements Job
{
    public function __construct(private readonly int $invoiceId) {}

    public function handle(): void
    {
        // Never reached; the declaration is refused before this can run.
    }

    public function invoiceId(): int
    {
        return $this->invoiceId;
    }
}
