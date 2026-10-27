<?php

declare(strict_types=1);

namespace App\Modules\Operations\Commands;

use App\Engine\Cli\Output;
use App\Modules\Operations\Store\OperationStore;

/** operations:sweep -- expire unclaimed work; retry or settle lapsed leases (ADR-0006). Every minute. */
final class Sweep
{
    public function __construct(private readonly OperationStore $operations) {}

    public function __invoke(Output $output): int
    {
        $done = $this->operations->sweep();
        $output->line(\sprintf(
            'Expired %d, re-queued %d, failed %d, needs attention %d, cancelled %d.',
            $done['expired'],
            $done['requeued'],
            $done['failed'],
            $done['needs_attention'],
            $done['cancelled'],
        ));

        return 0;
    }
}
