<?php

declare(strict_types=1);

namespace App\Engine\Queue;

use App\Engine\Hook\HookEngine;
use App\Engine\Observability\Tracer;
use App\Engine\Support\Sealer;

/**
 * The queue an application is handed.
 *
 *     public function __construct(private readonly Queue $queue) {}
 *
 *     $this->queue->push(new SendInvoice($invoice->identity()));
 *     $this->queue->later(3600, new ChaseInvoice($invoice->identity()), queue: 'billing');
 *
 * Injected, like everything else. There is no Queue::push() and no dispatch()
 * helper, for the same reason there is no Cache::get(): a class that queues
 * work has a dependency, and a dependency belongs in a constructor where a test
 * can replace it with a memory store and then read what was queued.
 *
 * This is the half with the behaviour -- naming, serialising, hooks -- and
 * QueueStore is the half with the storage. What that split buys is the sync
 * store: "run it now" is a store like any other, so nothing in an application
 * changes between a development machine with no worker and a server with four.
 *
 * Two refusals happen at dispatch rather than in a worker, because both are
 * mistakes in the line being written and neither should be discovered an hour
 * later by whoever reads the failed list: a job with no handle(), and a job
 * that cannot be serialised.
 */
final class Queue
{
    public const DEFAULT = 'default';

    public const NAME_PATTERN = '/^[a-z][a-z0-9_-]*$/';

    public function __construct(
        private readonly QueueStore $store,
        private readonly JobRunner $runner,
        private readonly HookEngine $hooks,
        private readonly string $default = self::DEFAULT,
        private readonly ?Tracer $tracer = null,
        /** Seals each payload, so a worker unserializes only what was queued here. */
        private readonly Sealer $sealer = new Sealer(),
    ) {}

    public function store(): QueueStore
    {
        return $this->store;
    }

    public function defaultQueue(): string
    {
        return $this->default;
    }

    /**
     * Queue a job.
     *
     * @param int $delay seconds before a worker may pick it up
     *
     * @return string the id, which is what queue:failed prints and what retry() takes
     */
    public function push(Job $job, ?string $queue = null, int $delay = 0): string
    {
        $this->runner->assertHandleable($job);

        $queued = new QueuedJob(
            id: $this->identifier(),
            queue: $this->name($queue),
            class: $job::class,
            payload: $this->serialise($job),
            availableAt: \time() + \max(0, $delay),
            createdAt: \time(),
            // The chain this job belongs to. The worker that runs it, maybe an
            // hour later on another machine, logs under this correlation, which
            // is what makes "what did that request cause" answerable.
            correlationId: $this->tracer?->current()->correlationId,
        );

        $this->hooks->do('job.queued', $queued, $job);

        $this->store->push($queued);

        return $queued->id;
    }

    /** The same thing, said in the order people think about it. */
    public function later(int $delay, Job $job, ?string $queue = null): string
    {
        return $this->push($job, $queue, $delay);
    }

    public function pending(?string $queue = null): int
    {
        return $this->store->size($this->name($queue));
    }

    /** @return list<string> */
    public function queues(): array
    {
        return $this->store->queues();
    }

    /** @return list<QueuedJob> */
    public function failed(): array
    {
        return $this->store->failed();
    }

    /**
     * Put a failed job back on its queue, with its attempt count reset.
     *
     * Reset rather than continued: somebody has looked at the failure and
     * decided the reason is gone -- the gateway is back, the bad data is fixed
     * -- and a retry that immediately exhausts the attempts it had already used
     * would answer a question nobody asked.
     */
    public function retry(string $id): bool
    {
        foreach ($this->store->failed() as $failure) {
            if ($failure->id !== $id) {
                continue;
            }

            $this->store->forget($id);

            return $this->store->push(new QueuedJob(
                id: $failure->id,
                queue: $failure->queue,
                class: $failure->class,
                payload: $failure->payload,
                availableAt: \time(),
                createdAt: $failure->createdAt,
                // Still the chain that caused it. Somebody retrying a failure is
                // usually reading its log lines at the same time.
                correlationId: $failure->correlationId,
            ));
        }

        return false;
    }

    public function forget(string $id): bool
    {
        return $this->store->forget($id);
    }

    public function clear(?string $queue = null): bool
    {
        return $this->store->clear($queue === null ? null : $this->name($queue));
    }

    /**
     * A queue name, checked.
     *
     * It becomes a directory in one store and a key in another, so it is held
     * to the same rule as a log channel and a cache namespace rather than being
     * escaped differently in each.
     */
    public function name(?string $queue): string
    {
        $name = $queue ?? $this->default;

        return \preg_match(self::NAME_PATTERN, $name) === 1
            ? $name
            : throw QueueException::unusableQueueName($name);
    }

    /**
     * Serialised now, not later.
     *
     * A job holding a database connection or a closure is a job that cannot be
     * queued, and the moment to say so is while dispatching it -- where the
     * stack trace points at the line that built it.
     */
    private function serialise(Job $job): string
    {
        try {
            return $this->sealer->seal(\serialize($job));
        } catch (\Throwable $e) {
            throw QueueException::notStorable($job::class, $e);
        }
    }

    /**
     * Sortable and unique: time first, then randomness.
     *
     * The time prefix is not decoration. Failed jobs are listed in the order
     * they were created without storing a second index, and a directory of job
     * files reads chronologically.
     */
    private function identifier(): string
    {
        return \sprintf('%010d%s', \time(), \bin2hex(\random_bytes(8)));
    }
}
