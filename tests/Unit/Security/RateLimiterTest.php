<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Engine\Security\Counters\MemoryStore;
use App\Engine\Security\RateLimiter;
use App\Engine\Security\SecurityException;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RateLimiterTest extends TestCase
{
    private RateLimiter $limiter;

    protected function setUp(): void
    {
        $this->limiter = new RateLimiter(new MemoryStore());
    }

    // ---- reading a limit -------------------------------------------------------

    /** @return array<string, array{string, int, int}> */
    public static function limits(): array
    {
        return [
            'seconds' => ['5/30s', 5, 30],
            'a bare unit means one' => ['60/m', 60, 60],
            'minutes' => ['60/1m', 60, 60],
            'quarter hour' => ['5/15m', 5, 900],
            'hours' => ['1000/1h', 1000, 3600],
            'days' => ['10/1d', 10, 86400],
            'uppercase' => ['10/1H', 10, 3600],
            'whitespace' => [' 10 / 2 m ', 10, 120],
        ];
    }

    #[DataProvider('limits')]
    public function test_a_limit_is_read_as_attempts_and_seconds(string $limit, int $attempts, int $window): void
    {
        self::assertSame([$attempts, $window], RateLimiter::parse($limit));
    }

    /**
     * Refused rather than guessed at. A limit comes from route metadata, so a
     * typo would otherwise become a limit of zero or a window of forever, and
     * both fail in a direction somebody notices late.
     *
     * @return array<string, array{string}>
     */
    public static function nonsense(): array
    {
        return [
            'empty' => [''],
            'no window' => ['60'],
            'no attempts' => ['/1m'],
            'zero attempts' => ['0/1m'],
            'zero window' => ['60/0m'],
            'an unknown unit' => ['60/1y'],
            'words' => ['sixty per minute'],
            'a decimal' => ['1.5/1m'],
        ];
    }

    #[DataProvider('nonsense')]
    public function test_a_limit_that_cannot_be_read_is_refused(string $limit): void
    {
        $this->expectException(SecurityException::class);

        RateLimiter::parse($limit);
    }

    public function test_the_refusal_shows_the_form_that_works(): void
    {
        try {
            RateLimiter::parse('sixty per minute');
            self::fail('nonsense was accepted');
        } catch (SecurityException $e) {
            self::assertStringContainsString('60/1m', $e->getMessage());
        }
    }

    // ---- counting --------------------------------------------------------------

    public function test_attempts_are_allowed_up_to_the_limit_and_not_past_it(): void
    {
        $allowed = [];

        for ($i = 0; $i < 5; ++$i) {
            $allowed[] = $this->limiter->attempt('a', '3/1m')->allowed;
        }

        self::assertSame([true, true, true, false, false], $allowed);
    }

    public function test_what_is_left_counts_down_and_stops_at_zero(): void
    {
        $remaining = [];

        for ($i = 0; $i < 4; ++$i) {
            $remaining[] = $this->limiter->attempt('a', '3/1m')->remaining;
        }

        self::assertSame([2, 1, 0, 0], $remaining);
    }

    /**
     * A limiter that stopped counting once the limit was reached would let a
     * client that keeps hammering start again the instant the window ends --
     * rewarding exactly the behaviour it exists to discourage.
     */
    public function test_a_refused_attempt_is_still_counted(): void
    {
        for ($i = 0; $i < 6; ++$i) {
            $this->limiter->attempt('a', '2/1m');
        }

        self::assertSame(6, $this->limiter->store()->peek('a')?->count);
    }

    public function test_keys_are_counted_separately(): void
    {
        $this->limiter->attempt('a', '1/1m');

        self::assertFalse($this->limiter->attempt('a', '1/1m')->allowed);
        self::assertTrue($this->limiter->attempt('b', '1/1m')->allowed);
    }

    /** What a successful login calls, so an earlier typo is not still punished. */
    public function test_clearing_a_key_lets_it_through_again(): void
    {
        $this->limiter->attempt('a', '1/1m');

        self::assertFalse($this->limiter->attempt('a', '1/1m')->allowed);
        self::assertTrue($this->limiter->clear('a'));
        self::assertTrue($this->limiter->attempt('a', '1/1m')->allowed);
    }

    // ---- checking without spending ---------------------------------------------

    public function test_checking_does_not_use_an_attempt(): void
    {
        $first = $this->limiter->check('a', '3/1m');
        $second = $this->limiter->check('a', '3/1m');

        self::assertSame(3, $first->remaining);
        self::assertSame(3, $second->remaining, 'checking again spent nothing');
        self::assertTrue($this->limiter->attempt('a', '3/1m')->allowed);
        self::assertSame(2, $this->limiter->check('a', '3/1m')->remaining);
    }

    public function test_checking_reports_a_key_that_is_already_over(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->limiter->attempt('a', '2/1m');
        }

        self::assertFalse($this->limiter->check('a', '2/1m')->allowed);
    }

    // ---- the headers -----------------------------------------------------------

    public function test_an_allowed_request_reports_its_standing_without_retry_after(): void
    {
        $headers = $this->limiter->attempt('a', '10/1m')->headers();

        self::assertSame('10', $headers['RateLimit-Limit']);
        self::assertSame('9', $headers['RateLimit-Remaining']);
        // Sending Retry-After on an allowed request has been known to make a
        // well-behaved client wait for no reason.
        self::assertArrayNotHasKey('Retry-After', $headers);
    }

    /**
     * The header is the whole point of a 429. Without it a client has no
     * information except "not now", and a well-written one retries at once.
     */
    public function test_a_refused_request_says_when_to_come_back(): void
    {
        $this->limiter->attempt('a', '1/1m');
        $headers = $this->limiter->attempt('a', '1/1m')->headers();

        self::assertSame('0', $headers['RateLimit-Remaining']);
        self::assertArrayHasKey('Retry-After', $headers);
        self::assertGreaterThan(0, (int) $headers['Retry-After']);
        self::assertLessThanOrEqual(60, (int) $headers['Retry-After']);
    }
}
