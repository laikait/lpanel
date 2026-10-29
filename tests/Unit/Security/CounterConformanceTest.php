<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Engine\Security\Counters\FileStore;
use App\Engine\Security\Counters\MemoryStore;
use App\Engine\Security\CounterStore;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What it means to count, asserted against every store there is.
 *
 * The fourth conformance suite, after the cache's, the queue's and the schedule
 * lock's. The property that matters here is the one a test cannot easily prove
 * -- that hit() is atomic -- so what this suite does instead is pin every
 * observable consequence of the window rule, which is where two
 * implementations actually drift: whether a later hit extends the window,
 * whether an expired counter restarts at one, and whether peek() has side
 * effects.
 *
 * A Redis or database store added later becomes conformant by appearing in one
 * provider here and passing without a line of this file changing.
 */
final class CounterConformanceTest extends TestCase
{
    /** @var list<string> */
    private static array $directories = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$directories as $directory) {
            foreach (\glob($directory . \DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @\unlink($file);
            }

            @\rmdir($directory);
        }

        self::$directories = [];
    }

    /** @return array<string, array{\Closure(): CounterStore}> */
    public static function stores(): array
    {
        return [
            'memory' => [static fn(): CounterStore => new MemoryStore()],
            'file' => [static function (): CounterStore {
                $directory = \sys_get_temp_dir() . '/counters-' . \bin2hex(\random_bytes(6));
                self::$directories[] = $directory;

                return new FileStore($directory);
            }],
        ];
    }

    /** @param \Closure(): CounterStore $make */
    #[DataProvider('stores')]
    public function test_the_first_hit_is_one(\Closure $make): void
    {
        self::assertSame(1, $make()->hit('a', 60)->count);
    }

    /** @param \Closure(): CounterStore $make */
    #[DataProvider('stores')]
    public function test_hits_accumulate(\Closure $make): void
    {
        $store = $make();

        self::assertSame(1, $store->hit('a', 60)->count);
        self::assertSame(2, $store->hit('a', 60)->count);
        self::assertSame(3, $store->hit('a', 60)->count);
    }

    /** @param \Closure(): CounterStore $make */
    #[DataProvider('stores')]
    public function test_keys_do_not_collide(\Closure $make): void
    {
        $store = $make();

        $store->hit('a', 60);
        $store->hit('a', 60);

        self::assertSame(1, $store->hit('b', 60)->count);
        self::assertSame(2, $store->peek('a')?->count);
    }

    /** @param \Closure(): CounterStore $make */
    #[DataProvider('stores')]
    public function test_nothing_counted_is_nothing_to_peek_at(\Closure $make): void
    {
        self::assertNull($make()->peek('never-touched'));
    }

    /** @param \Closure(): CounterStore $make */
    #[DataProvider('stores')]
    public function test_peeking_does_not_count(\Closure $make): void
    {
        $store = $make();
        $store->hit('a', 60);

        self::assertNotNull($store->peek('a'));
        self::assertSame(1, $store->peek('a')->count);
        self::assertSame(2, $store->hit('a', 60)->count);
    }

    /**
     * The rule that keeps a limit a limit rather than a ban.
     *
     * A window that slid forward on every hit would mean a client making one
     * request a second was never let through again.
     *
     * @param \Closure(): CounterStore $make
     */
    #[DataProvider('stores')]
    public function test_a_later_hit_does_not_extend_the_window(\Closure $make): void
    {
        $store = $make();

        $first = $store->hit('a', 60);
        $second = $store->hit('a', 3600);

        self::assertSame($first->expiresAt, $second->expiresAt, 'the window belongs to the first hit');
    }

    /** @param \Closure(): CounterStore $make */
    #[DataProvider('stores')]
    public function test_an_expired_counter_starts_again_at_one(\Closure $make): void
    {
        $store = $make();

        // Two seconds, not one: time() is whole seconds, and two hits that
        // straddle a second boundary would see a one-second window expire
        // between them. That was an intermittent failure, not a store bug.
        self::assertSame(1, $store->hit('a', 2)->count);
        self::assertSame(2, $store->hit('a', 2)->count);

        \sleep(3);

        self::assertNull($store->peek('a'), 'an expired counter is not a counter');
        self::assertSame(1, $store->hit('a', 60)->count);
    }

    /** What a successful login calls, so an earlier typo is not still punished. */
    #[DataProvider('stores')]
    public function test_clearing_forgets_one_key(\Closure $make): void
    {
        $store = $make();

        $store->hit('a', 60);
        $store->hit('b', 60);

        self::assertTrue($store->clear('a'));
        self::assertNull($store->peek('a'));
        self::assertSame(1, $store->peek('b')?->count);
    }

    /** @param \Closure(): CounterStore $make */
    #[DataProvider('stores')]
    public function test_clearing_something_untouched_reports_false(\Closure $make): void
    {
        self::assertFalse($make()->clear('never-touched'));
    }

    /** @param \Closure(): CounterStore $make */
    #[DataProvider('stores')]
    public function test_flushing_forgets_everything(\Closure $make): void
    {
        $store = $make();

        $store->hit('a', 60);
        $store->hit('b', 60);

        self::assertTrue($store->flush());
        self::assertNull($store->peek('a'));
        self::assertNull($store->peek('b'));
    }

    /**
     * A key is an IP address, a username or a token -- not a filename.
     *
     * @param \Closure(): CounterStore $make
     */
    #[DataProvider('stores')]
    public function test_a_key_with_punctuation_in_it_counts_normally(\Closure $make): void
    {
        $store = $make();
        $key = 'route:api.v1.customers.index|203.0.113.7';

        self::assertSame(1, $store->hit($key, 60)->count);
        self::assertSame(2, $store->hit($key, 60)->count);
        self::assertSame(2, $store->peek($key)?->count);
    }

    /** @param \Closure(): CounterStore $make */
    #[DataProvider('stores')]
    public function test_a_counter_reports_how_long_it_has_left(\Closure $make): void
    {
        $counter = $make()->hit('a', 120);

        self::assertGreaterThan(115, $counter->secondsRemaining());
        self::assertLessThanOrEqual(120, $counter->secondsRemaining());
        self::assertFalse($counter->hasExpired());
    }
}
