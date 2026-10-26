<?php

declare(strict_types=1);

namespace App\Engine\Queue\Stores;

use App\Engine\Queue\JobRunner;
use App\Engine\Queue\QueuedJob;
use App\Engine\Queue\QueueStore;

/**
 * No queue at all: a job runs where it was dispatched, immediately.
 *
 * The default, and the reason is the alternative. A memory store in a web
 * request accepts a job and drops it when the response is sent -- work that
 * silently never happens, which is the worst failure a queue can have. A file
 * store writes the job down and waits for a worker that nobody has started yet,
 * which is the second worst. Running it inline is neither: an application works
 * out of the box, and going asynchronous is a configuration change and a
 * process to run.
 *
 * **Exceptions propagate.** There is no retry, no backoff and no failed list,
 * because there is nothing here to retry from -- the caller is still on the
 * stack, and swallowing its exception would be pretending to be a queue. That
 * is the honest expression of "this is the absence of a queue", and it is also
 * what makes a development environment tell the truth: a job that throws breaks
 * the request that dispatched it, exactly as the code would have if it had not
 * been deferred at all.
 *
 * So dispatching work is safe everywhere, and the decision to actually defer it
 * is one line of configuration plus one process.
 */
final class SyncStore implements QueueStore
{
    public function __construct(private readonly JobRunner $runner) {}

    public function describe(): string
    {
        return 'sync (jobs run immediately, where they are dispatched)';
    }

    public function push(QueuedJob $job): bool
    {
        // The delay is ignored rather than slept through. Blocking a web
        // request for the five minutes somebody asked to wait would be a
        // faithful reading and a useless one.
        $this->runner->run($job->reservedFor(0));

        return true;
    }

    public function reserve(string $queue, int $seconds): ?QueuedJob
    {
        return null;
    }

    public function acknowledge(QueuedJob $job): bool
    {
        return true;
    }

    public function release(QueuedJob $job): bool
    {
        return true;
    }

    public function fail(QueuedJob $job): bool
    {
        return true;
    }

    public function failed(): array
    {
        return [];
    }

    public function forget(string $id): bool
    {
        return true;
    }

    public function size(string $queue): int
    {
        return 0;
    }

    public function queues(): array
    {
        return [];
    }

    public function clear(?string $queue = null): bool
    {
        return true;
    }
}
