<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Showcase\Plugins\Example\Jobs;

use App\Engine\Logging\Logger;
use App\Engine\Queue\Job;
use App\Tests\Fixtures\Showcase\Plugins\Example\Data\CustomerQuery;

/**
 * The work that follows registering a customer, done outside the request.
 *
 * A job lives in the module that owns the work -- there is no global Jobs
 * directory and nothing to register. The class name is the registration, and a
 * worker in another process finds it through the same autoloader everything
 * else uses.
 *
 * Look at what crosses the process boundary. **The constructor takes an id**,
 * because the id is what will be written to the queue; the customer this names
 * may be edited between dispatching this and running it, and reading it fresh
 * is almost always what was meant anyway. Serialising the model instead would
 * work right up until somebody changed a field.
 *
 * **handle() takes the collaborators**, and they come from the container of
 * whatever process runs the job. Nothing here is serialised, so there is no
 * database handle in the queue file that has to still be valid an hour later.
 *
 * In an application that has not configured a queue this runs immediately, in
 * the request that registered the customer. Deferring it is one line of
 * configuration and a worker -- and not one line of this file.
 */
final class WelcomeCustomer implements Job
{
    public function __construct(private readonly int $customerId) {}

    public function handle(CustomerQuery $customers, Logger $log): void
    {
        $log->info('Welcoming a customer', [
            'id' => $this->customerId,
            // Read now rather than carried from the dispatch, which is the
            // point of taking an id: this is the count as it is when the work
            // happens.
            'customers' => $customers->total(),
        ]);
    }

    public function customerId(): int
    {
        return $this->customerId;
    }
}
