<?php

declare(strict_types=1);

namespace App\Engine\Queue\Stores;

use App\Engine\Queue\QueuedJob;
use App\Engine\Queue\QueueStore;

/**
 * A queue that lives for one process.
 *
 * For tests, and for a command that wants to fan work out and then drain it
 * itself in the same run. It is not for a web request: a job dispatched into
 * this from a controller is gone when the response is sent, which is why it is
 * not the default and why queue:status says what it is.
 *
 * Reserving here cannot race, because there is only ever one process looking.
 * That makes it the store where the conformance suite's expiry and ordering
 * rules are easiest to read, and the file store is where they are hard.
 */
final class MemoryStore implements QueueStore
{
    /** @var array<string, list<QueuedJob>> queue name => jobs, in the order pushed */
    private array $jobs = [];

    /** @var list<QueuedJob> */
    private array $failures = [];

    public function describe(): string
    {
        return 'memory (this process only)';
    }

    public function push(QueuedJob $job): bool
    {
        $this->jobs[$job->queue][] = $job;

        return true;
    }

    public function reserve(string $queue, int $seconds): ?QueuedJob
    {
        $now = \time();
        $chosen = null;
        $at = null;

        // Due order, not push order. The file store gets this for free from the
        // due time in its filenames, and a store that answered in push order
        // instead would make a job's delay mean something different depending
        // on which store an application had configured. The conformance suite
        // caught exactly that.
        foreach ($this->jobs[$queue] ?? [] as $index => $job) {
            if (!$job->isAvailable($now)) {
                continue;
            }

            if ($at === null || $job->availableAt < $at) {
                $chosen = $index;
                $at = $job->availableAt;
            }
        }

        if ($chosen === null) {
            return null;
        }

        $jobs = $this->jobs[$queue];
        $reserved = $jobs[$chosen]->reservedFor($seconds, $now);
        $jobs[$chosen] = $reserved;
        $this->jobs[$queue] = \array_values($jobs);

        return $reserved;
    }

    public function acknowledge(QueuedJob $job): bool
    {
        return $this->remove($job->queue, $job->id);
    }

    public function release(QueuedJob $job): bool
    {
        $this->remove($job->queue, $job->id);

        return $this->push($job);
    }

    public function fail(QueuedJob $job): bool
    {
        $this->remove($job->queue, $job->id);
        $this->failures[] = $job;

        return true;
    }

    public function failed(): array
    {
        return $this->failures;
    }

    public function forget(string $id): bool
    {
        $this->failures = \array_values(\array_filter(
            $this->failures,
            static fn(QueuedJob $job): bool => $job->id !== $id,
        ));

        return true;
    }

    public function size(string $queue): int
    {
        return \count($this->jobs[$queue] ?? []);
    }

    public function queues(): array
    {
        $names = [];

        foreach ($this->jobs as $queue => $jobs) {
            if ($jobs !== []) {
                $names[] = $queue;
            }
        }

        \sort($names);

        return $names;
    }

    public function clear(?string $queue = null): bool
    {
        if ($queue === null) {
            $this->jobs = [];

            return true;
        }

        unset($this->jobs[$queue]);

        return true;
    }

    private function remove(string $queue, string $id): bool
    {
        $before = \count($this->jobs[$queue] ?? []);

        $this->jobs[$queue] = \array_values(\array_filter(
            $this->jobs[$queue] ?? [],
            static fn(QueuedJob $job): bool => $job->id !== $id,
        ));

        return \count($this->jobs[$queue]) < $before;
    }
}
