<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Engine\Scheduler\Locks\FileLock;
use App\Engine\Scheduler\Locks\MemoryLock;
use App\Engine\Scheduler\ScheduleLock;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What it means to be a lock, asserted against every implementation there is.
 *
 * The third conformance suite in this framework, after the cache's and the
 * queue's, and the one where a divergence is hardest to notice and most
 * expensive. A lock that is subtly wrong does not throw and does not log: it
 * says yes twice, and the only symptom is two copies of a nightly task having
 * both done the same work.
 *
 * Everything here is one of the two properties in ScheduleLock's contract --
 * a second acquire is refused, and an expired lock is not held -- said in
 * every way the implementations could disagree about them.
 *
 * A database or Redis lock added later becomes conformant by appearing in one
 * provider here and passing without a line of this file changing.
 */
final class LockConformanceTest extends TestCase
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

    /** @return array<string, array{\Closure(): ScheduleLock}> */
    public static function locks(): array
    {
        return [
            'memory' => [static fn(): ScheduleLock => new MemoryLock()],
            'file' => [static function (): ScheduleLock {
                $directory = \sys_get_temp_dir() . '/schedule-lock-' . \bin2hex(\random_bytes(6));
                self::$directories[] = $directory;

                return new FileLock($directory);
            }],
        ];
    }

    /** @param \Closure(): ScheduleLock $make */
    #[DataProvider('locks')]
    public function test_a_free_lock_is_granted(\Closure $make): void
    {
        self::assertTrue($make()->acquire('billing:recalculate', 60));
    }

    /**
     * The one property everything else rests on.
     *
     * @param \Closure(): ScheduleLock $make
     */
    #[DataProvider('locks')]
    public function test_a_held_lock_is_refused(\Closure $make): void
    {
        $lock = $make();

        self::assertTrue($lock->acquire('billing:recalculate', 60));
        self::assertFalse($lock->acquire('billing:recalculate', 60));
        self::assertFalse($lock->acquire('billing:recalculate', 60));
    }

    /** @param \Closure(): ScheduleLock $make */
    #[DataProvider('locks')]
    public function test_locks_do_not_collide_across_keys(\Closure $make): void
    {
        $lock = $make();

        self::assertTrue($lock->acquire('billing:recalculate', 60));
        self::assertTrue($lock->acquire('customer:cleanup', 60));
    }

    /** @param \Closure(): ScheduleLock $make */
    #[DataProvider('locks')]
    public function test_a_released_lock_can_be_taken_again(\Closure $make): void
    {
        $lock = $make();

        self::assertTrue($lock->acquire('customer:cleanup', 60));
        self::assertTrue($lock->release('customer:cleanup'));
        self::assertTrue($lock->acquire('customer:cleanup', 60));
    }

    /**
     * Releasing something nobody holds is not an error.
     *
     * schedule:unlock walks a list and calls this; a false is how it reports
     * that there was nothing to do, not a reason to stop.
     *
     * @param \Closure(): ScheduleLock $make
     */
    #[DataProvider('locks')]
    public function test_releasing_an_unheld_lock_reports_false(\Closure $make): void
    {
        self::assertFalse($make()->release('nothing:here'));
    }

    /**
     * The property that makes a killed process survivable.
     *
     * A process killed outright releases nothing, so a lock that never expired
     * would stop its task permanently and silently. One second, waited out for
     * real, because a lock whose expiry is only ever tested by moving a fake
     * clock is a lock whose expiry might not exist.
     *
     * @param \Closure(): ScheduleLock $make
     */
    #[DataProvider('locks')]
    public function test_an_abandoned_lock_expires_and_is_taken_over(\Closure $make): void
    {
        $lock = $make();

        self::assertTrue($lock->acquire('invoice:send-reminders', 1));
        self::assertFalse($lock->acquire('invoice:send-reminders', 1), 'it is held while it is fresh');

        \sleep(2);

        self::assertNull($lock->heldUntil('invoice:send-reminders'), 'an expired lock is not held');
        self::assertTrue($lock->acquire('invoice:send-reminders', 60), 'and can be taken over');
    }

    /** @param \Closure(): ScheduleLock $make */
    #[DataProvider('locks')]
    public function test_a_held_lock_reports_when_it_expires(\Closure $make): void
    {
        $lock = $make();
        $before = \time();

        self::assertNull($lock->heldUntil('never:taken'), 'a lock nobody holds has no expiry');
        self::assertTrue($lock->acquire('customer:cleanup', 120));

        $until = $lock->heldUntil('customer:cleanup');

        self::assertNotNull($until);
        self::assertGreaterThanOrEqual($before + 120, $until);
    }

    /** @param \Closure(): ScheduleLock $make */
    #[DataProvider('locks')]
    public function test_held_lists_what_is_held_and_nothing_else(\Closure $make): void
    {
        $lock = $make();

        self::assertSame([], $lock->held());

        $lock->acquire('billing:recalculate', 60);
        $lock->acquire('customer:cleanup', 60);

        self::assertSame(['billing:recalculate', 'customer:cleanup'], $lock->held());

        $lock->release('billing:recalculate');

        self::assertSame(['customer:cleanup'], $lock->held());
    }

    /**
     * A key is not a filename, and the lock is responsible for the difference.
     *
     * @param \Closure(): ScheduleLock $make
     */
    #[DataProvider('locks')]
    public function test_a_key_with_punctuation_in_it_round_trips(\Closure $make): void
    {
        $lock = $make();

        self::assertTrue($lock->acquire('Example:nightly.run', 60));
        self::assertFalse($lock->acquire('Example:nightly.run', 60));
        self::assertSame(['Example:nightly.run'], $lock->held());
    }
}
