<?php

declare(strict_types=1);

namespace App\Engine\Scheduler;

/**
 * What it takes to stop a scheduled task running twice at once.
 *
 * Four operations, and the only one that is difficult is acquire(). It has to
 * be **atomic** -- two processes calling it in the same millisecond must not
 * both be told yes -- and it has to **expire**, because a process killed with
 * SIGKILL never gets to release anything and a lock without an expiry would
 * stop its task permanently. Those two requirements together are the entire
 * contract; everything else here exists so an operator can see what is held.
 *
 * The same shape as CacheStore and QueueStore, and for the same reason: where
 * a lock lives is a deployment decision. A single machine wants a file. Several
 * machines behind a load balancer, each with cron running, want something they
 * share -- a row in the database, a Redis key -- and that is a store written
 * against this interface rather than a change to the scheduler.
 *
 * There is deliberately no null implementation. A cache that stores nothing is
 * a slow cache; a lock that locks nothing is two invoice runs, and shipping one
 * would make the most dangerous configuration the easiest to reach. A schedule
 * that genuinely may overlap says so with ->allowOverlapping(), where the
 * decision is visible next to the task it affects.
 */
interface ScheduleLock
{
    /** One line for `schedule:list` and `about`, naming where locks live. */
    public function describe(): string;

    /**
     * Take the lock, or report that somebody else has it.
     *
     * @param int $seconds how long the lock survives without being released
     */
    public function acquire(string $key, int $seconds): bool;

    /** Give it back. False when it was not held, which is not an error. */
    public function release(string $key): bool;

    /** The unix time the lock expires, or null when it is not held. */
    public function heldUntil(string $key): ?int;

    /**
     * Every key currently held.
     *
     * For `schedule:list` and `schedule:unlock --all`. An expired lock is not
     * held, so it does not appear here even if its record is still on disk.
     *
     * @return list<string>
     */
    public function held(): array;
}
