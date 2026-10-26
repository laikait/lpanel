<?php

declare(strict_types=1);

namespace App\Engine\Scheduler\Locks;

use App\Engine\Scheduler\ScheduleLock;

/**
 * A lock that lives for one process.
 *
 * For tests, and for a command that fans work out and drains it in the same
 * run. It is **not** the default and must never be: the scheduler's process is
 * started fresh by cron every minute and exits, so a lock held in its memory is
 * gone before the run it was meant to exclude ever starts. A memory lock in
 * production is a lock that has never once stopped anything.
 *
 * What it is genuinely good for is reading. Expiry and the taking-over of an
 * abandoned lock are three lines here and a filesystem race there, so this is
 * the implementation to read first and the conformance suite is what keeps the
 * other one honest against it.
 */
final class MemoryLock implements ScheduleLock
{
    /** @var array<string, int> key => unix time it expires */
    private array $locks = [];

    public function describe(): string
    {
        return 'memory (this process only)';
    }

    public function acquire(string $key, int $seconds): bool
    {
        if (($this->locks[$key] ?? 0) > \time()) {
            return false;
        }

        $this->locks[$key] = \time() + \max(1, $seconds);

        return true;
    }

    public function release(string $key): bool
    {
        if (!isset($this->locks[$key])) {
            return false;
        }

        unset($this->locks[$key]);

        return true;
    }

    public function heldUntil(string $key): ?int
    {
        $expires = $this->locks[$key] ?? null;

        return $expires !== null && $expires > \time() ? $expires : null;
    }

    public function held(): array
    {
        $keys = [];

        foreach ($this->locks as $key => $expires) {
            if ($expires > \time()) {
                $keys[] = $key;
            }
        }

        \sort($keys);

        return $keys;
    }

    public function flush(): int
    {
        $count = \count($this->locks);
        $this->locks = [];

        return $count;
    }
}
