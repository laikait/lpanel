<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Engine\Cli\CommandCollector;
use App\Engine\Cli\CommandDispatcher;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\Output;
use App\Engine\Container\Container;
use App\Engine\Hook\HookEngine;
use App\Engine\Queue\JobRunner;
use App\Engine\Queue\Queue;
use App\Engine\Queue\Stores\MemoryStore;
use App\Engine\Scheduler\Locks\MemoryLock;
use App\Engine\Scheduler\Schedule;
use App\Engine\Scheduler\ScheduleCollector;
use App\Engine\Scheduler\ScheduleOutcome;
use App\Engine\Scheduler\Scheduler;
use App\Engine\Scheduler\ScheduleRegistry;
use App\Engine\Scheduler\ScheduleResult;
use App\Engine\Scheduler\SchedulerException;
use App\Engine\Scheduler\ScheduleTarget;
use App\Tests\Fixtures\Queue\RecordingJob;
use App\Tests\Fixtures\Scheduler\UnschedulableJob;
use App\Tests\Support\TestCase;

/**
 * The scheduler over real collaborators.
 *
 * The container, the console registry and the queue are the production ones,
 * because the interesting behaviour is entirely in how they compose: a
 * scheduled command really is dispatched through the console, and a scheduled
 * job really is pushed onto a queue. Only the lock is swapped for the memory
 * one, for the obvious reason that these tests must not write to the machine's
 * system/Schedule directory.
 */
final class SchedulerTest extends TestCase
{
    private CommandRegistry $commands;

    private ScheduleRegistry $schedules;

    private MemoryLock $locks;

    private MemoryStore $queued;

    private HookEngine $hooks;

    private Container $container;

    protected function setUp(): void
    {
        RecordingJob::reset();

        $this->commands = new CommandRegistry();
        $this->schedules = new ScheduleRegistry();
        $this->locks = new MemoryLock();
        $this->queued = new MemoryStore();
        $this->hooks = new HookEngine();
        $this->container = new Container();

        $collector = new CommandCollector($this->commands, 'tests');

        $collector->add('demo:quiet', static fn(): int => 0)
            ->describe('Succeeds and says nothing.');

        $collector->add('demo:talk', static function (Output $output, ?string $label = null): int {
            $output->line('spoke ' . ($label ?? 'plainly'));

            return 0;
        })->describe('Prints a line.')->option('label', 'What to say.');

        $collector->add('demo:refuse', static fn(): int => 3)
            ->describe('Exits non-zero, the way a failing command does.');

        $collector->add('demo:throw', static function (): int {
            throw new \RuntimeException('the command exploded');
        })->describe('Throws.');
    }

    private function collector(): ScheduleCollector
    {
        return new ScheduleCollector($this->schedules, $this->commands, 'tests');
    }

    private function scheduler(): Scheduler
    {
        $runner = new JobRunner($this->container, $this->hooks);
        $queue = new Queue($this->queued, $runner, $this->hooks);

        $this->container->instance(Container::class, $this->container);

        return new Scheduler(
            $this->schedules,
            $this->locks,
            $this->container,
            $this->commands,
            new CommandDispatcher($this->container, $this->hooks),
            $queue,
            $this->hooks,
            'UTC',
        );
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    // ---- declaring ------------------------------------------------------------

    public function test_a_command_schedule_takes_the_command_name_as_its_id(): void
    {
        $schedule = $this->collector()->command('demo:quiet');

        self::assertSame('demo:quiet', $schedule->id());
        self::assertSame(ScheduleTarget::Command, $schedule->target);
        self::assertSame('tests', $schedule->module);
    }

    public function test_a_job_schedule_names_itself_after_the_class(): void
    {
        self::assertSame('job:recording-job', $this->collector()->job(RecordingJob::class)->id());
    }

    public function test_scheduling_an_unknown_command_is_refused_where_it_is_declared(): void
    {
        $this->expectException(SchedulerException::class);
        $this->expectExceptionMessage('demo:nonexistent');

        $this->collector()->command('demo:nonexistent');
    }

    /**
     * A misspelt option is caught by parsing the declaration, not by running it.
     *
     * Parsing needs the command definition, which is why this can only happen
     * once commands are registered -- and it is why the manager replays
     * schedules after commands rather than before.
     */
    public function test_an_option_the_command_does_not_have_is_refused(): void
    {
        $this->expectException(\App\Engine\Cli\ConsoleException::class);

        $this->collector()->command('demo:talk', '--volume=11');
    }

    public function test_scheduling_something_that_is_not_a_job_is_refused(): void
    {
        $this->expectException(SchedulerException::class);

        $this->collector()->job(\stdClass::class);
    }

    public function test_a_job_that_needs_arguments_cannot_be_scheduled(): void
    {
        $this->expectException(SchedulerException::class);
        $this->expectExceptionMessage('constructor requires arguments');

        $this->collector()->job(UnschedulableJob::class);
    }

    /**
     * Checked once the declarations are in, not as each arrives.
     *
     * An id is not final while its schedule is still being configured, because
     * ->identify() is allowed to change it. Checking on the way in would refuse
     * the very declaration that was about to fix the clash.
     */
    public function test_two_schedules_cannot_share_an_id(): void
    {
        $this->collector()->command('demo:quiet');
        $this->collector()->command('demo:quiet');

        $this->expectException(SchedulerException::class);
        $this->expectExceptionMessage('demo:quiet');

        $this->schedules->assertUnique();
    }

    public function test_the_same_command_can_be_scheduled_twice_under_two_ids(): void
    {
        $this->collector()->command('demo:quiet')->dailyAt('02:00');
        $this->collector()->command('demo:quiet')->identify('demo:quiet-monday')->weeklyOn(1);

        $this->schedules->assertUnique();

        self::assertSame(['demo:quiet', 'demo:quiet-monday'], $this->schedules->ids());
    }

    public function test_only_a_job_may_be_given_a_queue(): void
    {
        $this->expectException(SchedulerException::class);
        $this->expectExceptionMessage('only a scheduled job goes through one');

        $this->collector()->command('demo:quiet')->onQueue('billing');
    }

    public function test_a_time_of_day_must_be_a_time_of_day(): void
    {
        $this->expectException(SchedulerException::class);

        $this->collector()->command('demo:quiet')->dailyAt('2am');
    }

    public function test_an_unknown_timezone_is_refused(): void
    {
        $this->expectException(SchedulerException::class);

        $this->collector()->command('demo:quiet')->timezone('Mars/Olympus');
    }

    // ---- what is due ----------------------------------------------------------

    public function test_only_what_matches_this_minute_is_due(): void
    {
        $this->collector()->command('demo:quiet')->dailyAt('02:00');
        $this->collector()->command('demo:talk')->everyMinute();

        $due = $this->scheduler()->due($this->at('2026-09-14 10:07:00'));

        self::assertCount(1, $due);
        self::assertSame('demo:talk', $due[0]->id());
    }

    /**
     * A schedule reads its own clock, which is the whole reason timezone()
     * exists: an ERP with month ends in two countries has two midnights.
     */
    public function test_a_schedule_is_due_on_its_own_timezone(): void
    {
        $this->collector()->command('demo:quiet')->dailyAt('02:00')->timezone('Europe/Berlin');

        // 00:00 UTC in September is 02:00 in Berlin.
        self::assertSame(['demo:quiet'], $this->ids($this->scheduler()->due($this->at('2026-09-14 00:00:00'))));
        self::assertSame([], $this->ids($this->scheduler()->due($this->at('2026-09-14 02:00:00'))));
    }

    // ---- running --------------------------------------------------------------

    public function test_a_scheduled_command_runs_and_its_output_is_captured(): void
    {
        $schedule = $this->collector()->command('demo:talk', '--label=clearly');

        $result = $this->scheduler()->runOne($schedule);

        self::assertSame(ScheduleOutcome::Ran, $result->outcome);
        self::assertStringContainsString('spoke clearly', $result->output);
    }

    /**
     * The most valuable thing this class does: a scheduled command's output
     * would otherwise go to a crontab MAILTO nobody has set.
     */
    public function test_the_captured_output_is_what_a_cron_line_would_have_thrown_away(): void
    {
        $result = $this->scheduler()->runOne($this->collector()->command('demo:talk'));

        self::assertStringContainsString('spoke plainly', $result->output);
    }

    public function test_a_command_that_exits_non_zero_is_a_failure(): void
    {
        $result = $this->scheduler()->runOne($this->collector()->command('demo:refuse'));

        self::assertSame(ScheduleOutcome::Failed, $result->outcome);
        self::assertTrue($result->failed());
        self::assertStringContainsString('exited 3', $result->message);
    }

    public function test_a_command_that_throws_is_a_failure_and_carries_the_exception(): void
    {
        $result = $this->scheduler()->runOne($this->collector()->command('demo:throw'));

        self::assertSame(ScheduleOutcome::Failed, $result->outcome);
        self::assertInstanceOf(\RuntimeException::class, $result->error);
        self::assertSame('the command exploded', $result->message);
    }

    /** A scheduled job is pushed, not run. Where it then runs is the queue's business. */
    public function test_a_scheduled_job_goes_onto_the_queue(): void
    {
        $result = $this->scheduler()->runOne($this->collector()->job(RecordingJob::class));

        self::assertSame(ScheduleOutcome::Queued, $result->outcome);
        self::assertSame([], RecordingJob::$ran, 'the scheduler pushed it, it did not run it');
        self::assertSame(1, $this->queued->size('default'));
    }

    public function test_a_scheduled_job_can_name_its_queue(): void
    {
        $this->scheduler()->runOne($this->collector()->job(RecordingJob::class)->onQueue('billing'));

        self::assertSame(1, $this->queued->size('billing'));
        self::assertSame(0, $this->queued->size('default'));
    }

    public function test_a_callback_is_called_with_its_parameters_injected(): void
    {
        $seen = null;

        $schedule = $this->collector()->call(
            'demo:callback',
            static function (Container $container) use (&$seen): void {
                $seen = $container;
            },
        );

        $result = $this->scheduler()->runOne($schedule);

        self::assertSame(ScheduleOutcome::Ran, $result->outcome);
        self::assertInstanceOf(Container::class, $seen);
    }

    /**
     * One broken task must not take the rest of the night with it.
     */
    public function test_a_failing_task_does_not_stop_the_run(): void
    {
        $this->collector()->command('demo:throw')->everyMinute();
        $this->collector()->command('demo:talk')->everyMinute();

        $results = $this->scheduler()->run($this->at('2026-09-14 10:07:00'));

        self::assertCount(2, $results);
        self::assertSame(ScheduleOutcome::Failed, $results[0]->outcome);
        self::assertSame(ScheduleOutcome::Ran, $results[1]->outcome);
    }

    // ---- not twice ------------------------------------------------------------

    public function test_a_task_whose_lock_is_held_is_skipped(): void
    {
        $schedule = $this->collector()->command('demo:talk');

        $this->locks->acquire('demo:talk', 600);

        $result = $this->scheduler()->runOne($schedule);

        self::assertSame(ScheduleOutcome::Skipped, $result->outcome);
        self::assertSame('', $result->output, 'nothing ran');
        self::assertStringContainsString('schedule:unlock', $result->message);
    }

    /** The lock is given back, so the next minute is not skipped too. */
    public function test_the_lock_is_released_when_the_task_finishes(): void
    {
        $this->scheduler()->runOne($this->collector()->command('demo:talk'));

        self::assertSame([], $this->locks->held());
    }

    /** Including when it blew up, which is the case that would wedge a schedule. */
    public function test_the_lock_is_released_when_the_task_throws(): void
    {
        $this->scheduler()->runOne($this->collector()->command('demo:throw'));

        self::assertSame([], $this->locks->held());
    }

    public function test_a_task_that_allows_overlapping_takes_no_lock(): void
    {
        $schedule = $this->collector()->command('demo:talk')->allowOverlapping();

        $this->locks->acquire('demo:talk', 600);

        self::assertSame(ScheduleOutcome::Ran, $this->scheduler()->runOne($schedule)->outcome);
    }

    // ---- conditions -----------------------------------------------------------

    public function test_a_condition_that_says_no_skips_the_task(): void
    {
        $schedule = $this->collector()->command('demo:talk')->when(static fn(): bool => false);

        $result = $this->scheduler()->runOne($schedule);

        self::assertSame(ScheduleOutcome::Skipped, $result->outcome);
        self::assertSame('', $result->output);
    }

    public function test_a_condition_that_says_yes_lets_it_through(): void
    {
        $schedule = $this->collector()->command('demo:talk')->when(static fn(): bool => true);

        self::assertSame(ScheduleOutcome::Ran, $this->scheduler()->runOne($schedule)->outcome);
    }

    /** A condition that forgets to return is not consent. */
    public function test_a_condition_that_returns_nothing_skips(): void
    {
        $schedule = $this->collector()->command('demo:talk')->when(static function (): void {});

        self::assertSame(ScheduleOutcome::Skipped, $this->scheduler()->runOne($schedule)->outcome);
    }

    /** A skipped task never takes the lock, so it cannot block the next attempt. */
    public function test_a_condition_that_says_no_takes_no_lock(): void
    {
        $this->scheduler()->runOne($this->collector()->command('demo:talk')->when(static fn(): bool => false));

        self::assertSame([], $this->locks->held());
    }

    // ---- announcements --------------------------------------------------------

    public function test_every_outcome_is_announced_on_one_hook(): void
    {
        $seen = [];

        $this->hooks->add('schedule.finished', static function (ScheduleResult $result) use (&$seen): void {
            $seen[] = $result->outcome->value;
        });

        $scheduler = $this->scheduler();
        $scheduler->runOne($this->collector()->command('demo:talk'));
        $scheduler->runOne($this->collector()->command('demo:refuse'));
        $scheduler->runOne($this->collector()->command('demo:quiet')->when(static fn(): bool => false));

        self::assertSame(['ran', 'failed', 'skipped'], $seen);
    }

    public function test_a_failure_is_announced_with_its_exception(): void
    {
        $caught = null;

        $this->hooks->add('schedule.failed', static function (\Throwable $e) use (&$caught): void {
            $caught = $e;
        });

        $this->scheduler()->runOne($this->collector()->command('demo:throw'));

        self::assertInstanceOf(\RuntimeException::class, $caught);
    }

    public function test_a_started_task_is_announced_before_it_runs(): void
    {
        $order = [];

        $this->hooks->add('schedule.started', static function (Schedule $schedule) use (&$order): void {
            $order[] = 'started ' . $schedule->id();
        });
        $this->hooks->add('schedule.finished', static function (ScheduleResult $result) use (&$order): void {
            $order[] = 'finished ' . $result->id();
        });

        $this->scheduler()->runOne($this->collector()->command('demo:talk'));

        self::assertSame(['started demo:talk', 'finished demo:talk'], $order);
    }

    /** A skipped task never started, so nothing claims it did. */
    public function test_a_skipped_task_is_not_announced_as_started(): void
    {
        $started = 0;

        $this->hooks->add('schedule.started', static function () use (&$started): void {
            ++$started;
        });

        $this->scheduler()->runOne($this->collector()->command('demo:talk')->when(static fn(): bool => false));

        self::assertSame(0, $started);
    }

    /**
     * @param list<Schedule> $schedules
     *
     * @return list<string>
     */
    private function ids(array $schedules): array
    {
        return \array_map(static fn(Schedule $schedule): string => $schedule->id(), $schedules);
    }
}
