<?php

declare(strict_types=1);

namespace App\Engine\Security;

/**
 * Counting things that happen, in a way two processes can agree on.
 *
 * Separate from CacheStore, which is the obvious place to put this and the
 * wrong one. A cache is allowed to forget: that is its whole contract, and a
 * store that may drop an entry at any time is a rate limiter that may forget
 * how many login attempts have been made. More decisively, a counter has to be
 * incremented **atomically** -- read, add, write as one operation -- and a
 * cache's get/set cannot do that. Two requests arriving together would both
 * read 5, both write 6, and the limit would be off by exactly as much as the
 * traffic it exists to stop.
 *
 * So hit() is the only way the count goes up, and it returns the value after
 * incrementing. There is deliberately no increment-by, no decrement and no set.
 *
 * The same shape as CacheStore, QueueStore and ScheduleLock, and for the same
 * reason: several machines behind a load balancer need a counter they share,
 * which is an implementation of this interface rather than a change to the
 * limiter.
 */
interface CounterStore
{
    /** One line for `security:check`, naming where counts live. */
    public function describe(): string;

    /**
     * Add one, atomically, and report the result.
     *
     * The window starts when the first hit lands and is not extended by later
     * ones. A window that slid forward on every attempt would mean a client
     * making one request a second was never allowed through again, which is a
     * ban rather than a limit.
     *
     * @param int $window seconds the count survives
     */
    public function hit(string $key, int $window): Counter;

    /** The count without changing it, or null when nothing is being counted. */
    public function peek(string $key): ?Counter;

    /** Forget one key. This is what "the password was finally right" calls. */
    public function clear(string $key): bool;

    /** Forget everything. For tests, and for an operator who has decided to. */
    public function flush(): bool;
}
