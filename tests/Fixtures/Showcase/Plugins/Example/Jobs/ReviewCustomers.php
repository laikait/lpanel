<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Showcase\Plugins\Example\Jobs;

use App\Engine\Logging\Logger;
use App\Engine\Queue\Job;
use App\Tests\Fixtures\Showcase\Plugins\Example\Data\CustomerQuery;

/**
 * The periodic pass over the customer list.
 *
 * A scheduled job rather than a scheduled command, and the difference is worth
 * reading the two side by side for. A scheduled command runs inside
 * `schedule:run`, which cron starts every minute -- fine for something quick,
 * and wrong for anything that might take ten minutes, because for those ten
 * minutes the process holding up every other schedule is this one.
 *
 * This class is pushed instead. The scheduler decides *when*, the queue decides
 * *where*, and on a machine with a worker the scheduler is finished in
 * milliseconds. On a machine with no worker configured the sync store runs it
 * inline and nothing is lost -- the same property that makes the queue usable
 * before anybody has read the deployment notes.
 *
 * **The constructor takes nothing**, which is what makes it schedulable. A
 * schedule has no data to hand a job; the clock is the only thing it knows. A
 * job that needs an invoice id belongs on a queue, pushed by whatever knows the
 * invoice, and the scheduler refuses to schedule one at the line that tried.
 */
final class ReviewCustomers implements Job
{
    public function handle(CustomerQuery $customers, Logger $log): void
    {
        $log->info('Reviewed the customer list', [
            'customers' => $customers->total(),
        ]);
    }
}
