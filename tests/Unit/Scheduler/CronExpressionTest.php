<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Engine\Scheduler\CronExpression;
use App\Engine\Scheduler\SchedulerException;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The parser everything else in the scheduler trusts.
 *
 * Worth testing harder than most of this framework. A schedule is checked by
 * nobody: if an expression is read a minute off, or an hour off, or on the
 * wrong days, the symptom is work that quietly did not happen, discovered at
 * month end. There is no request to fail, no response to be wrong, and no
 * operator watching.
 */
final class CronExpressionTest extends TestCase
{
    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    // ---- matching -------------------------------------------------------------

    /** @return array<string, array{string, string, bool}> */
    public static function moments(): array
    {
        return [
            'every minute' => ['* * * * *', '2026-09-14 10:07:00', true],
            'exact minute' => ['7 10 * * *', '2026-09-14 10:07:00', true],
            'wrong minute' => ['8 10 * * *', '2026-09-14 10:07:00', false],
            'wrong hour' => ['7 11 * * *', '2026-09-14 10:07:00', false],
            'step matches' => ['*/5 * * * *', '2026-09-14 10:05:00', true],
            'step misses' => ['*/5 * * * *', '2026-09-14 10:07:00', false],
            'list matches' => ['5,7,9 * * * *', '2026-09-14 10:07:00', true],
            'list misses' => ['5,9 * * * *', '2026-09-14 10:07:00', false],
            'range matches' => ['0-10 * * * *', '2026-09-14 10:07:00', true],
            'range misses' => ['0-6 * * * *', '2026-09-14 10:07:00', false],
            'range with step' => ['0-10/7 * * * *', '2026-09-14 10:07:00', true],
            'day of month' => ['0 0 14 * *', '2026-09-14 00:00:00', true],
            'wrong day of month' => ['0 0 15 * *', '2026-09-14 00:00:00', false],
            'month by name' => ['0 0 14 SEP *', '2026-09-14 00:00:00', true],
            'wrong month by name' => ['0 0 14 OCT *', '2026-09-14 00:00:00', false],
            'weekday by number' => ['0 0 * * 1', '2026-09-14 00:00:00', true],
            'weekday by name' => ['0 0 * * MON', '2026-09-14 00:00:00', true],
            'wrong weekday' => ['0 0 * * TUE', '2026-09-14 00:00:00', false],
            'sunday as seven' => ['0 0 * * 7', '2026-09-13 00:00:00', true],
            'sunday as zero' => ['0 0 * * 0', '2026-09-13 00:00:00', true],
            'weekday range' => ['0 9 * * 1-5', '2026-09-14 09:00:00', true],
            'weekend excluded' => ['0 9 * * 1-5', '2026-09-13 09:00:00', false],
            'macro daily' => ['@daily', '2026-09-14 00:00:00', true],
            'macro hourly' => ['@hourly', '2026-09-14 10:00:00', true],
            'macro monthly misses' => ['@monthly', '2026-09-14 00:00:00', false],
            'macro is case insensitive' => ['@DAILY', '2026-09-14 00:00:00', true],
        ];
    }

    #[DataProvider('moments')]
    public function test_an_expression_matches_the_minutes_it_should(
        string $expression,
        string $moment,
        bool $due,
    ): void {
        self::assertSame($due, (new CronExpression($expression))->isDue($this->at($moment)));
    }

    /**
     * Cron's own rule, copied deliberately.
     *
     * With both day fields restricted the two are ORed, so "the 13th and every
     * Friday" rather than "Friday the 13th". It surprises everybody once, and
     * an expression pasted out of a real crontab has to mean here what it means
     * there.
     */
    public function test_both_day_fields_restricted_means_either(): void
    {
        $expression = new CronExpression('0 0 13 * FRI');

        // The 13th, which is a Sunday in September 2026.
        self::assertTrue($expression->isDue($this->at('2026-09-13 00:00:00')));
        // A Friday that is not the 13th.
        self::assertTrue($expression->isDue($this->at('2026-09-18 00:00:00')));
        // Neither.
        self::assertFalse($expression->isDue($this->at('2026-09-14 00:00:00')));
    }

    public function test_one_day_field_restricted_means_that_one(): void
    {
        // Day of week alone: every Monday, whatever the date.
        self::assertTrue((new CronExpression('0 0 * * MON'))->isDue($this->at('2026-09-14 00:00:00')));
        // Day of month alone: the 14th, whatever the weekday.
        self::assertTrue((new CronExpression('0 0 14 * *'))->isDue($this->at('2026-09-14 00:00:00')));
        self::assertFalse((new CronExpression('0 0 14 * *'))->isDue($this->at('2026-09-15 00:00:00')));
    }

    // ---- the next run ---------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function nextRuns(): array
    {
        return [
            'every minute' => ['* * * * *', '2026-09-14 10:08'],
            'next step' => ['*/5 * * * *', '2026-09-14 10:10'],
            'later today' => ['0 22 * * *', '2026-09-14 22:00'],
            'tomorrow' => ['0 2 * * *', '2026-09-15 02:00'],
            'next weekday' => ['30 3 * * MON', '2026-09-21 03:30'],
            'next month' => ['0 0 1 * *', '2026-10-01 00:00'],
            'next year' => ['0 0 1 1 *', '2027-01-01 00:00'],
            'the next leap day' => ['0 0 29 2 *', '2028-02-29 00:00'],
        ];
    }

    #[DataProvider('nextRuns')]
    public function test_the_next_run_is_found(string $expression, string $expected): void
    {
        $next = (new CronExpression($expression))->nextRunAfter($this->at('2026-09-14 10:07:00'));

        self::assertNotNull($next);
        self::assertSame($expected, $next->format('Y-m-d H:i'));
    }

    /** The next run is strictly after now, even when now matches. */
    public function test_a_due_expression_still_reports_a_later_next_run(): void
    {
        $expression = new CronExpression('7 10 * * *');
        $now = $this->at('2026-09-14 10:07:00');

        self::assertTrue($expression->isDue($now));

        $next = $expression->nextRunAfter($now);

        self::assertNotNull($next);
        self::assertSame('2026-09-15 10:07', $next->format('Y-m-d H:i'));
    }

    /**
     * An expression that parses and can never match says so.
     *
     * February the 30th. Reporting "never" is the honest answer and the useful
     * one -- schedule:list prints it, which is how a typo like this is found at
     * all rather than by noticing nothing ever ran.
     */
    public function test_an_impossible_date_reports_never(): void
    {
        self::assertNull((new CronExpression('0 0 30 2 *'))->nextRunAfter($this->at('2026-09-14 10:07:00')));
    }

    /** Seconds are not part of a cron minute, so they must not shift the answer. */
    public function test_seconds_are_ignored(): void
    {
        $expression = new CronExpression('*/15 * * * *');

        self::assertTrue($expression->isDue($this->at('2026-09-14 10:15:59')));

        $next = $expression->nextRunAfter($this->at('2026-09-14 10:15:59'));

        self::assertNotNull($next);
        self::assertSame('2026-09-14 10:30', $next->format('Y-m-d H:i'));
    }

    // ---- refusals -------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function refusals(): array
    {
        return [
            'too few fields' => ['* * * *'],
            'too many fields' => ['* * * * * *'],
            'empty' => [''],
            'minute out of range' => ['60 * * * *'],
            'hour out of range' => ['0 24 * * *'],
            'day of month is zero' => ['0 0 0 * *'],
            'month out of range' => ['0 0 1 13 *'],
            'weekday out of range' => ['0 0 * * 8'],
            'a backwards range' => ['30-10 * * * *'],
            'a zero step' => ['*/0 * * * *'],
            'a word' => ['every * * * *'],
            'an unknown month name' => ['0 0 1 SMARCH *'],
            'an unknown macro' => ['@fortnightly'],
            'reboot, which there is no daemon for' => ['@reboot'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_malformed_expression_is_refused(string $expression): void
    {
        $this->expectException(SchedulerException::class);

        new CronExpression($expression);
    }

    /** The message names the expression, because it came from a module.php somewhere. */
    public function test_the_refusal_quotes_what_was_written(): void
    {
        try {
            new CronExpression('0 99 * * *');
            self::fail('an hour of 99 was accepted');
        } catch (SchedulerException $e) {
            self::assertStringContainsString('0 99 * * *', $e->getMessage());
            self::assertStringContainsString('0-23', $e->getMessage());
        }
    }

    // ---- description ----------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function descriptions(): array
    {
        return [
            'every minute' => ['* * * * *', 'every minute'],
            'a step' => ['*/15 * * * *', 'every 15 minutes'],
            'hourly' => ['0 * * * *', 'hourly at :00'],
            'hourly at' => ['20 * * * *', 'hourly at :20'],
            'daily' => ['@daily', 'daily at 00:00'],
            'daily at' => ['30 2 * * *', 'daily at 02:30'],
            'weekly' => ['0 3 * * MON', 'weekly on MON at 03:00'],
            'monthly' => ['0 4 1 * *', 'monthly on day 1 at 04:00'],
            // Deliberately not translated: an uneven step does not mean what
            // "every 7 minutes" would say across an hour boundary.
            'an uneven step' => ['*/7 * * * *', '*/7 * * * *'],
            'something complicated' => ['15,45 9-17 * * 1-5', '15,45 9-17 * * 1-5'],
        ];
    }

    #[DataProvider('descriptions')]
    public function test_the_common_shapes_read_as_english(string $expression, string $expected): void
    {
        self::assertSame($expected, (new CronExpression($expression))->describe());
    }
}
