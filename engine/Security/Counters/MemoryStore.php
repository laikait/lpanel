<?php

declare(strict_types=1);

namespace App\Engine\Security\Counters;

use App\Engine\Security\Counter;
use App\Engine\Security\CounterStore;

/**
 * Counts that live for one process.
 *
 * For tests, and honest about being nothing else. A web request is a process
 * that ends, so a limiter counting here has forgotten every attempt by the time
 * the next one arrives -- which is not a lenient rate limiter, it is no rate
 * limiter at all. `security:check` reports it as a problem for that reason.
 *
 * What it is good for is reading: the window rule is four lines here and a
 * locked read-modify-write there, and the conformance suite is what keeps the
 * file store honest against this one.
 */
final class MemoryStore implements CounterStore
{
    /** @var array<string, Counter> */
    private array $counters = [];

    public function describe(): string
    {
        return 'memory (this process only)';
    }

    public function hit(string $key, int $window): Counter
    {
        $existing = $this->peek($key);

        $counter = $existing === null
            ? new Counter(1, \time() + \max(1, $window))
            : new Counter($existing->count + 1, $existing->expiresAt);

        $this->counters[$key] = $counter;

        return $counter;
    }

    public function peek(string $key): ?Counter
    {
        $counter = $this->counters[$key] ?? null;

        if ($counter === null) {
            return null;
        }

        if ($counter->hasExpired()) {
            unset($this->counters[$key]);

            return null;
        }

        return $counter;
    }

    public function clear(string $key): bool
    {
        if (!isset($this->counters[$key])) {
            return false;
        }

        unset($this->counters[$key]);

        return true;
    }

    public function flush(): bool
    {
        $this->counters = [];

        return true;
    }
}
