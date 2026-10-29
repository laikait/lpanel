<?php

declare(strict_types=1);

namespace App\Tests\Unit\Queue;

use App\Engine\Queue\Backoff;
use App\Tests\Support\TestCase;

/**
 * How long to wait before trying again.
 *
 * Small enough to be obvious, and worth pinning anyway: the cap is what stops
 * the fifteenth attempt being scheduled nine hours out, which for an invoice
 * reminder is indistinguishable from never.
 */
final class BackoffTest extends TestCase
{
    public function test_it_doubles(): void
    {
        $backoff = new Backoff(base: 5, multiplier: 2, cap: 600);

        self::assertSame(5, $backoff->after(1));
        self::assertSame(10, $backoff->after(2));
        self::assertSame(20, $backoff->after(3));
        self::assertSame(40, $backoff->after(4));
    }

    public function test_it_stops_growing_at_the_cap(): void
    {
        $backoff = new Backoff(base: 5, multiplier: 2, cap: 30);

        self::assertSame(30, $backoff->after(10));
        self::assertSame(30, $backoff->after(100));
    }

    public function test_a_fixed_backoff_does_not_grow(): void
    {
        $backoff = Backoff::fixed(15);

        self::assertSame(15, $backoff->after(1));
        self::assertSame(15, $backoff->after(9));
    }

    public function test_the_first_attempt_waits_the_base(): void
    {
        // Attempt counts arrive from a job that has been tried at least once,
        // but a zero should not produce something strange.
        self::assertSame(5, (new Backoff(base: 5))->after(0));
    }

    /**
     * A hundred jobs that failed together retry together, hit the recovering
     * service simultaneously and knock it over again. Jitter is the few seconds
     * of spread that prevents it.
     */
    public function test_jitter_spreads_retries_without_exceeding_the_delay(): void
    {
        $backoff = new Backoff(base: 100, multiplier: 1, cap: 100, jitter: 0.5);

        $seen = [];

        for ($i = 0; $i < 40; ++$i) {
            $delay = $backoff->after(1);

            self::assertGreaterThanOrEqual(50, $delay);
            self::assertLessThanOrEqual(100, $delay);

            $seen[$delay] = true;
        }

        self::assertGreaterThan(1, \count($seen), 'jitter that never varies is not jitter');
    }

    public function test_no_jitter_is_deterministic(): void
    {
        $backoff = new Backoff(base: 7, multiplier: 1, cap: 7);

        self::assertSame($backoff->after(3), $backoff->after(3));
    }

    public function test_it_can_describe_itself(): void
    {
        self::assertStringContainsString('5s', (new Backoff())->describe());
        self::assertStringContainsString('between attempts', Backoff::fixed(9)->describe());
    }
}
