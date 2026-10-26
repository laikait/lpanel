<?php

declare(strict_types=1);

namespace App\Engine\Queue;

/**
 * Where queued work waits.
 *
 * The one method that is not obvious is reserve(), and it is the only one that
 * is hard. Everything else is a list operation; reserve() has to hand the same
 * job to exactly one worker when several ask at the same moment. A store that
 * gets it wrong looks perfectly healthy right up to the day a customer is
 * charged twice.
 *
 * So the contract is stated rather than implied:
 *
 *   - reserve() must be atomic. Two workers calling it concurrently must never
 *     receive the same job.
 *   - A reservation expires. A worker that is killed between reserving and
 *     acknowledging must not take the job to the grave with it: once
 *     reservedUntil has passed, the job is available again. That is what makes
 *     an interrupted worker safe, and it is why no signal handling is needed
 *     for correctness.
 *   - Reserving increments attempts. The count therefore includes the attempt
 *     in progress, which is what lets a job that kills its worker every time
 *     eventually exhaust its tries instead of retrying forever.
 *
 * Failures live in the same store but not on the queue: fail() takes a job off
 * and keeps it, failed() lists what has accumulated, and forget() discards one.
 * A failed job that vanishes is a failed job nobody can retry.
 */
interface QueueStore
{
    /** One line for queue:status and about, e.g. "file system/Queue". */
    public function describe(): string;

    public function push(QueuedJob $job): bool;

    /**
     * Claim the next job that is due on this queue, or null when there is none.
     *
     * @param int $seconds how long the claim lasts before the job becomes available again
     */
    public function reserve(string $queue, int $seconds): ?QueuedJob;

    /** Done with it: take it off the queue for good. */
    public function acknowledge(QueuedJob $job): bool;

    /** Put it back, with whatever availableAt the envelope now carries. */
    public function release(QueuedJob $job): bool;

    /** Take it off the queue and keep it in the failed list. */
    public function fail(QueuedJob $job): bool;

    /** @return list<QueuedJob> */
    public function failed(): array;

    /** Discard one failed job. */
    public function forget(string $id): bool;

    /** How many jobs are waiting, reserved ones included. */
    public function size(string $queue): int;

    /** @return list<string> every queue that currently holds anything */
    public function queues(): array;

    /** Everything on one queue, or on all of them. Failed jobs are not touched. */
    public function clear(?string $queue = null): bool;
}
