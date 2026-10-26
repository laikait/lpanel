<?php

declare(strict_types=1);

namespace App\Engine\Security;

/**
 * The answer to one rate-limit question, and the headers that go with it.
 *
 * A limiter that returned a bool would be half a feature. A client that is
 * being throttled needs to know how much is left and when to come back, and a
 * client that is not being throttled needs the same numbers to avoid becoming
 * one. Those are the RateLimit-* headers, and returning them from here means
 * they cannot be forgotten at the call site.
 */
final class RateLimit
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $limit,
        public readonly int $remaining,
        public readonly int $retryAfter,
    ) {}

    /**
     * The headers describing this decision.
     *
     * RateLimit-* are the names from the IETF draft that Cloudflare, GitHub and
     * others already emit; X-RateLimit-* are the older spelling. Only the
     * unprefixed ones are sent: two spellings of the same number is how they
     * end up disagreeing.
     *
     * Retry-After is only meaningful once the answer is no, and sending it on
     * an allowed request has been known to make well-behaved clients wait.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [
            'RateLimit-Limit' => (string) $this->limit,
            'RateLimit-Remaining' => (string) \max(0, $this->remaining),
            'RateLimit-Reset' => (string) $this->retryAfter,
        ];

        if (!$this->allowed) {
            $headers['Retry-After'] = (string) $this->retryAfter;
        }

        return $headers;
    }
}
