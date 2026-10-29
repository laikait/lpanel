<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logging;

use App\Engine\Logging\Level;
use App\Tests\Support\TestCase;

/**
 * The eight severities.
 *
 * The direction is the thing worth pinning: syslog counts *down* to more
 * severe, and every tool that reads the output agrees with syslog, so the
 * framework agrees with syslog too.
 */
final class LevelTest extends TestCase
{
    public function test_the_backing_values_are_the_syslog_codes(): void
    {
        self::assertSame(0, Level::Emergency->value);
        self::assertSame(3, Level::Error->value);
        self::assertSame(7, Level::Debug->value);
    }

    public function test_there_are_exactly_eight(): void
    {
        self::assertCount(8, Level::cases(), 'the RFC 5424 severities, and no invented ninth');
    }

    /**
     * "log" appears in the specification's list but is not a severity: it is
     * the name of the method that takes one. A ninth case would make "is this
     * severe enough to write" unanswerable.
     */
    public function test_log_is_not_one_of_them(): void
    {
        self::assertNotContains('log', Level::names());
    }

    public function test_a_threshold_includes_everything_at_least_as_severe(): void
    {
        self::assertTrue(Level::Error->isAtLeast(Level::Warning));
        self::assertTrue(Level::Warning->isAtLeast(Level::Warning));
        self::assertFalse(Level::Info->isAtLeast(Level::Warning));
    }

    public function test_severity_compares_the_way_a_person_would_say_it(): void
    {
        self::assertTrue(Level::Critical->isMoreSevereThan(Level::Error));
        self::assertFalse(Level::Error->isMoreSevereThan(Level::Critical));
        self::assertFalse(Level::Error->isMoreSevereThan(Level::Error));
    }

    /** The line every alerting rule starts from. */
    public function test_failure_starts_at_error(): void
    {
        self::assertTrue(Level::Error->isFailure());
        self::assertTrue(Level::Emergency->isFailure());
        self::assertFalse(Level::Warning->isFailure());
    }

    public function test_names_are_lowercase_and_ordered_most_severe_first(): void
    {
        self::assertSame(
            ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'],
            Level::names(),
        );
    }

    // ---- parsing --------------------------------------------------------------

    public function test_a_configured_name_is_read_case_insensitively(): void
    {
        self::assertSame(Level::Warning, Level::fromName('warning'));
        self::assertSame(Level::Warning, Level::fromName(' WARNING '));
    }

    /** A typo in a configured level must not stop an application from starting. */
    public function test_an_unknown_name_falls_back_rather_than_throwing(): void
    {
        self::assertSame(Level::Info, Level::fromName('wraning', Level::Info));
        self::assertSame(Level::Info, Level::fromName('', Level::Info));
        self::assertSame(Level::Info, Level::fromName(null, Level::Info));
        self::assertSame(Level::Info, Level::fromName(['warning'], Level::Info));
    }

    public function test_a_level_passes_through_itself(): void
    {
        self::assertSame(Level::Alert, Level::fromName(Level::Alert));
    }
}
