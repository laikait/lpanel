<?php

declare(strict_types=1);

namespace App\Engine\Security;

/**
 * How often something may happen, and what to say when it happens more.
 *
 *     $limit = $limiter->attempt('login:' . $request->ip(), '5/15m');
 *
 *     if (!$limit->allowed) {
 *         throw HttpException::tooManyRequests($limit->retryAfter);
 *     }
 *
 * This is the half with the policy and CounterStore is the half with the
 * counting, the same split the cache and the queue make. What that buys is a
 * limiter whose behaviour can be read without a filesystem and a store that can
 * become Redis without the policy changing.
 *
 * **The key is the caller's decision, and it has to be.** A limit per IP
 * address protects against one machine; per username protects one account from
 * every machine; per API token protects a quota. Those are different threats
 * and a framework that picked one would be wrong for the other two. What is
 * offered instead is a place to put the answer -- route metadata, read by
 * Guard -- and the recommendation to be specific: `login:<ip>` rather than
 * `<ip>`, so that a shared office address exhausting a login limit does not
 * also stop the office reading the site.
 *
 * **The window is fixed, not sliding, and that is a real limitation.** A count
 * starts when the first attempt lands and resets when the window ends, which
 * means a client can spend its whole allowance at the end of one window and the
 * whole of the next at the start -- twice the rate, briefly, across the
 * boundary. The honest alternatives both cost more than they are worth here: a
 * sliding log stores every timestamp, and a sliding counter needs two windows
 * read atomically. For "five login attempts" and "sixty API calls a minute" the
 * boundary burst is not the thing that matters, and saying so beats implying a
 * precision this does not have.
 */
final class RateLimiter
{
    /** attempts/window, where a window is a number and one of s, m, h, d. */
    public const PATTERN = '/^\s*([0-9]+)\s*\/\s*([0-9]*)\s*([smhd])\s*$/i';

    public const UNITS = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400];

    public function __construct(private readonly CounterStore $counters) {}

    public function store(): CounterStore
    {
        return $this->counters;
    }

    /**
     * Count this attempt and say whether it is allowed.
     *
     * The attempt is counted whatever the answer, including when the answer is
     * no. A limiter that stopped counting once the limit was reached would let
     * a client that keeps hammering start again the moment the window ends,
     * which rewards exactly the behaviour it is there to discourage -- and the
     * numbers would stop reflecting what is happening.
     *
     * @param string $limit "60/1m", "5/15m", "1000/1h"
     */
    public function attempt(string $key, string $limit): RateLimit
    {
        [$attempts, $window] = self::parse($limit);

        $counter = $this->counters->hit($key, $window);

        return new RateLimit(
            allowed: $counter->count <= $attempts,
            limit: $attempts,
            remaining: \max(0, $attempts - $counter->count),
            retryAfter: $counter->secondsRemaining(),
        );
    }

    /**
     * The current standing, without counting an attempt.
     *
     * For a page that wants to show "3 attempts remaining" without spending
     * one of them.
     */
    public function check(string $key, string $limit): RateLimit
    {
        [$attempts, $window] = self::parse($limit);

        $counter = $this->counters->peek($key);

        if ($counter === null) {
            return new RateLimit(true, $attempts, $attempts, $window);
        }

        return new RateLimit(
            allowed: $counter->count < $attempts,
            limit: $attempts,
            remaining: \max(0, $attempts - $counter->count),
            retryAfter: $counter->secondsRemaining(),
        );
    }

    /**
     * Forget this key.
     *
     * What a successful login calls. A throttle that survived the correct
     * password would punish somebody for having mistyped it earlier, which is
     * the common case rather than the attack.
     */
    public function clear(string $key): bool
    {
        return $this->counters->clear($key);
    }

    /**
     * "60/1m" as attempts and seconds.
     *
     * Refused rather than guessed at. A limit comes from route metadata or
     * configuration, so a typo would otherwise become a limit of zero or a
     * window of forever, and both fail in a direction somebody notices late.
     *
     * @return array{int, int}
     */
    public static function parse(string $limit): array
    {
        if (\preg_match(self::PATTERN, $limit, $parts) !== 1) {
            throw SecurityException::unusableLimit($limit);
        }

        $attempts = (int) $parts[1];
        $count = $parts[2] === '' ? 1 : (int) $parts[2];
        $unit = self::UNITS[\strtolower($parts[3])];

        if ($attempts < 1 || $count < 1) {
            throw SecurityException::unusableLimit($limit);
        }

        return [$attempts, $count * $unit];
    }
}
