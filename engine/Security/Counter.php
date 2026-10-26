<?php

declare(strict_types=1);

namespace App\Engine\Security;

/**
 * How many times something has happened, and when that stops mattering.
 *
 * Two numbers together rather than two calls, because they are read under a
 * lock and a second call would be a second lock -- and, worse, a window that
 * expired between the two would give a count from one window and an expiry from
 * the next.
 */
final class Counter
{
    public function __construct(
        public readonly int $count,
        public readonly int $expiresAt,
    ) {}

    public function secondsRemaining(?int $now = null): int
    {
        return \max(0, $this->expiresAt - ($now ?? \time()));
    }

    public function hasExpired(?int $now = null): bool
    {
        return $this->expiresAt <= ($now ?? \time());
    }
}
