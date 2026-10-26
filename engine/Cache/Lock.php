<?php

declare(strict_types=1);

namespace App\Engine\Cache;

/**
 * Only one process at a time.
 *
 *     $lock = $cache->lock('invoice:' . $id, seconds: 60);
 *
 *     if ($lock->get()) {
 *         try { ... } finally { $lock->release(); }
 *     }
 *
 *     $lock->block(5, fn() => ...);   // wait up to 5 seconds, run, release
 *
 * **Every lock expires.** A process killed while holding one releases nothing,
 * so the seconds given are how long the work may take at most; after that the
 * lock is free again. Pick a little more than the longest run.
 *
 * **Only the holder releases it.** Each Lock has its own random owner token,
 * and release() removes the key only while it still holds that token. A
 * process that overran its lock and lost it to another does not, on finishing,
 * release the other's.
 *
 * **Shared as widely as the store.** On the file store, across every process on
 * one machine; on the database store, across machines; on the array store,
 * within one process only -- which is what tests want and production does not.
 */
final class Lock
{
    public const PREFIX = 'lock.';

    private readonly string $owner;

    public function __construct(
        private readonly AtomicStore $store,
        private readonly string $key,
        private readonly int $seconds,
        ?string $owner = null,
    ) {
        $this->owner = $owner ?? \bin2hex(\random_bytes(16));
    }

    /** Take it if it is free. True when this lock now holds it. */
    public function get(): bool
    {
        return $this->store->add($this->key, $this->owner, $this->seconds);
    }

    /**
     * Wait for it, up to $seconds, then take it. With a callback, run it while
     * holding the lock, release it afterwards whatever happens, and return
     * what the callback returned.
     *
     * @template T
     *
     * @param ?\Closure(): T $then
     *
     * @return ($then is null ? true : T)
     *
     * @throws CacheException when the lock was not free in time
     */
    public function block(float $seconds, ?\Closure $then = null): mixed
    {
        $deadline = \microtime(true) + $seconds;

        while (!$this->get()) {
            if (\microtime(true) >= $deadline) {
                throw CacheException::lockTimeout($this->key, $seconds);
            }

            \usleep(50_000);
        }

        if ($then === null) {
            return true;
        }

        try {
            return $then();
        } finally {
            $this->release();
        }
    }

    /** Let it go, if this lock still holds it. */
    public function release(): bool
    {
        return $this->store->forgetIf($this->key, $this->owner);
    }

    /** The token that identifies this holder: pass it to another process to let it release the lock. */
    public function owner(): string
    {
        return $this->owner;
    }

    /** Whether the key is held now, by anyone. */
    public function isHeld(): bool
    {
        return $this->store->get($this->key) !== null;
    }
}
