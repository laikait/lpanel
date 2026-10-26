<?php

declare(strict_types=1);

namespace App\Engine\Queue;

/**
 * Something to do later.
 *
 * A job is an ordinary class in a module's Jobs/ directory. It is not
 * registered anywhere: the class name is the registration, which is the whole
 * difference between this and a worker that needs a map of names to handlers
 * kept in step by hand.
 *
 *     final class SendInvoice implements Job
 *     {
 *         public function __construct(private readonly int $invoiceId) {}
 *
 *         public function handle(InvoiceRepository $invoices, Logger $log): void
 *         {
 *             ...
 *         }
 *     }
 *
 * Note where the two kinds of thing live. **The constructor takes data**, and
 * that data is what gets written to the queue. **handle() takes collaborators**,
 * and they are injected when the job runs, out of the container of whatever
 * process picks it up. Serialising a repository into a queue file and hoping
 * its database handle still works an hour later is the failure this shape
 * avoids by construction.
 *
 * The interface declares nothing, and that is deliberate rather than lazy. An
 * interface method fixes its signature for every implementation, and the
 * signature of handle() is exactly the part that has to differ: one job wants a
 * repository, the next wants a mailer and a logger. What would be gained by
 * declaring handle(): void is a compile-time check; what it would cost is
 * injection. A job with no handle() is refused at dispatch instead -- loudly,
 * before it is queued, rather than an hour later in a worker.
 */
interface Job
{
    /**
     * Nothing. See above: handle() is called through the container, so its
     * signature belongs to the job rather than to this interface.
     */
}
