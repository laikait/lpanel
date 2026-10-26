<?php

declare(strict_types=1);

namespace App\Engine\Queue;

/**
 * How long to wait before trying a job again.
 *
 * Retrying immediately is the wrong answer to almost every reason a job fails.
 * The payment gateway is rate limiting, the database is failing over, the
 * remote host is restarting: all of them are fixed by waiting, and none of them
 * is fixed by hammering. Exponential growth is the cheap way to wait long
 * enough without knowing which it was.
 *
 * The cap matters as much as the growth. Without one, the fifteenth attempt is
 * scheduled nine hours out, which for an invoice reminder is indistinguishable
 * from never.
 *
 * Jitter is off by default and worth turning on for anything that talks to a
 * shared service. A hundred jobs that failed together retry together, hit the
 * recovering service simultaneously, and knock it over again -- a stampede a
 * few seconds of spread prevents entirely.
 */
final class Backoff
{
    // A readonly class would say this once. The properties carry it
    // individually instead, because composer.json declares PHP 8.1 and
    // readonly classes arrived in 8.2; readonly properties did not.
    public function __construct(
        /** Seconds before the second attempt. */
        public readonly int $base = 5,
        /** Multiplier per attempt: 2 gives 5, 10, 20, 40. 1 gives a fixed delay. */
        public readonly int $multiplier = 2,
        /** Never wait longer than this. */
        public readonly int $cap = 600,
        /** Spread retries by up to this fraction of the delay, 0.0 to 1.0. */
        public readonly float $jitter = 0.0,
    ) {}

    /** A fixed wait, for a queue whose failures are not about load. */
    public static function fixed(int $seconds): self
    {
        return new self(base: $seconds, multiplier: 1, cap: $seconds);
    }

    /**
     * Seconds to wait before the given attempt number is tried again.
     *
     * @param int $attempts how many attempts have already been made, at least 1
     */
    public function after(int $attempts): int
    {
        $delay = $this->base;

        for ($i = 1; $i < \max(1, $attempts); ++$i) {
            $delay *= \max(1, $this->multiplier);

            if ($delay >= $this->cap) {
                break;
            }
        }

        $delay = \min($delay, $this->cap);

        if ($this->jitter <= 0.0) {
            return \max(0, $delay);
        }

        $spread = (int) \round($delay * \min(1.0, $this->jitter));

        return \max(0, $delay - ($spread === 0 ? 0 : \random_int(0, $spread)));
    }

    public function describe(): string
    {
        return $this->multiplier <= 1
            ? \sprintf('%ds between attempts', $this->base)
            : \sprintf('%ds, times %d per attempt, capped at %ds', $this->base, $this->multiplier, $this->cap);
    }
}
