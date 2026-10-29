<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Cli\CommandDispatcher;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\ConsoleKernel;
use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Engine\Error\ErrorHandler;
use App\Engine\Hook\HookEngine;
use App\Engine\Logging\LogManager;
use App\Engine\Queue\Queue;
use App\Engine\Scheduler\ScheduleLock;
use App\Engine\Scheduler\ScheduleOutcome;
use App\Engine\Scheduler\Scheduler;
use App\Engine\Scheduler\ScheduleRegistry;
use App\Tests\Fixtures\Logging\CollectingWriter;
use App\Tests\Support\TestCase;

/**
 * The scheduler through a real application.
 *
 * The claim being checked is the one that makes a scheduler worth having in a
 * framework rather than in a crontab: a module declares when its own work runs,
 * installing the module installs the schedule, and the machine needs exactly
 * one cron line that never changes.
 *
 * The lock is forced to memory throughout. These tests must not write to the
 * machine's system/Schedule directory, and -- more to the point -- a test that
 * left a lock behind would silently skip the next test that used the same id.
 */
final class SchedulerSliceTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function app(array $config = []): Application
    {
        $config['scheduler']['lock'] = 'memory';

        return $this->application($config)->boot();
    }

    // ---- wiring --------------------------------------------------------------

    public function test_the_scheduler_is_injectable_and_knows_its_clock(): void
    {
        $scheduler = $this->app()->container()->get(Scheduler::class);

        self::assertSame('UTC', $scheduler->timezone());
    }

    /**
     * app.timezone unless the scheduler is told otherwise, so "daily at 02:00"
     * means the same thing as every other date in the application.
     */
    public function test_the_clock_follows_the_application_unless_overridden(): void
    {
        self::assertSame(
            'Europe/Berlin',
            $this->app(['app' => ['timezone' => 'Europe/Berlin']])->container()->get(Scheduler::class)->timezone(),
        );

        self::assertSame(
            'UTC',
            $this->app([
                'app' => ['timezone' => 'Europe/Berlin'],
                'scheduler' => ['timezone' => 'UTC'],
            ])->container()->get(Scheduler::class)->timezone(),
        );
    }

    /** A lock that locks nothing is not a configuration this framework offers. */
    public function test_an_unknown_lock_falls_back_to_the_file_lock(): void
    {
        $lock = $this->application(['scheduler' => ['lock' => 'zookeeper']])
            ->boot()
            ->container()
            ->get(ScheduleLock::class);

        self::assertStringStartsWith('file ', $lock->describe());
    }

    // ---- a module's schedules --------------------------------------------------

    /**
     * Nothing is scanned for and nothing is registered centrally: the demo
     * plugin declares these in its own module.php, and they are here because
     * the module is installed.
     */
    public function test_a_module_brings_its_schedules_with_it(): void
    {
        $schedules = $this->app()->container()->get(ScheduleRegistry::class);

        self::assertSame(
            ['customer:sync', 'job:review-customers', 'customer:refresh-counts'],
            $schedules->ids(),
        );

        foreach ($schedules->all() as $schedule) {
            self::assertSame('Example', $schedule->module, 'a schedule knows who declared it');
        }
    }

    public function test_a_fixture_application_with_no_schedules_has_none(): void
    {
        $config = ['scheduler' => ['lock' => 'memory']];

        self::assertSame(0, $this->fixtureApplication($config)->boot()->container()
            ->get(ScheduleRegistry::class)->count());
    }

    // ---- the three targets ------------------------------------------------------

    public function test_a_scheduled_command_runs_through_the_console(): void
    {
        $app = $this->app();
        $scheduler = $app->container()->get(Scheduler::class);
        $schedule = $scheduler->schedules()->get('customer:sync');

        self::assertNotNull($schedule);

        $result = $scheduler->runOne($schedule);

        self::assertSame(ScheduleOutcome::Ran, $result->outcome);
        // The command printed, and the scheduler kept it rather than letting it
        // go to a cron MAILTO nobody has configured.
        self::assertStringContainsString('customer(s)', $result->output);
    }

    /**
     * The queue integration, in one assertion: the scheduler pushes and
     * returns, and where the work happens is the queue's configuration.
     */
    public function test_a_scheduled_job_is_handed_to_the_queue(): void
    {
        $app = $this->app(['queue' => ['store' => 'memory']]);
        $scheduler = $app->container()->get(Scheduler::class);
        $schedule = $scheduler->schedules()->get('job:review-customers');

        self::assertNotNull($schedule);

        $result = $scheduler->runOne($schedule);

        self::assertSame(ScheduleOutcome::Queued, $result->outcome);
        self::assertSame(1, $app->container()->get(Queue::class)->pending());
    }

    /**
     * And under the default sync store the very same declaration runs inline,
     * which is what makes a machine with no worker still do the work.
     */
    public function test_the_same_scheduled_job_runs_inline_with_no_queue_configured(): void
    {
        $app = $this->app();
        $writer = new CollectingWriter();
        $app->container()->get(LogManager::class)->add($writer);

        $scheduler = $app->container()->get(Scheduler::class);
        $schedule = $scheduler->schedules()->get('job:review-customers');

        self::assertNotNull($schedule);
        self::assertSame(ScheduleOutcome::Queued, $scheduler->runOne($schedule)->outcome);

        // Two records: the job's own, and the scheduler's -- in that order,
        // because under the sync store the job finishes before push() returns.
        self::assertSame(
            ['Reviewed the customer list', 'Schedule job:review-customers queued'],
            \array_map(static fn(object $record): string => (string) $record->message, $writer->records),
        );
    }

    public function test_a_scheduled_callback_is_called_with_its_dependencies(): void
    {
        $app = $this->app();
        $scheduler = $app->container()->get(Scheduler::class);
        $schedule = $scheduler->schedules()->get('customer:refresh-counts');

        self::assertNotNull($schedule);
        self::assertSame(ScheduleOutcome::Ran, $scheduler->runOne($schedule)->outcome);
    }

    // ---- logging ----------------------------------------------------------------

    /**
     * The reason the bridge exists. Work that runs with nobody watching has to
     * leave a record, or a schedule that stopped six weeks ago looks exactly
     * like one with nothing to do.
     */
    public function test_a_run_is_written_to_the_log_without_the_scheduler_knowing(): void
    {
        $app = $this->app();
        $writer = new CollectingWriter();
        $app->container()->get(LogManager::class)->add($writer);

        $scheduler = $app->container()->get(Scheduler::class);
        $schedule = $scheduler->schedules()->get('customer:refresh-counts');

        self::assertNotNull($schedule);
        $scheduler->runOne($schedule);

        $records = \array_values(\array_filter(
            $writer->records,
            static fn(object $record): bool => \str_starts_with((string) $record->message, 'Schedule '),
        ));

        self::assertCount(1, $records);
        self::assertSame('Schedule customer:refresh-counts ran', $records[0]->message);
        self::assertSame('customer:refresh-counts', $records[0]->context['schedule']);
        self::assertSame('Example', $records[0]->context['module']);
    }

    /** A command's output reaches the log, which is where cron normally loses it. */
    public function test_what_a_scheduled_command_printed_reaches_the_log(): void
    {
        $app = $this->app();
        $writer = new CollectingWriter();
        $app->container()->get(LogManager::class)->add($writer);

        $scheduler = $app->container()->get(Scheduler::class);
        $schedule = $scheduler->schedules()->get('customer:sync');

        self::assertNotNull($schedule);
        $scheduler->runOne($schedule);

        $records = \array_values(\array_filter(
            $writer->records,
            static fn(object $record): bool => \str_starts_with((string) $record->message, 'Schedule '),
        ));

        self::assertCount(1, $records);
        self::assertArrayHasKey('output', $records[0]->context);
        self::assertStringContainsString('customer(s)', (string) $records[0]->context['output']);
    }

    // ---- the console -------------------------------------------------------------

    public function test_schedule_list_shows_every_task_and_when_it_next_runs(): void
    {
        [$status, $output] = $this->console($this->app(), 'schedule:list');

        self::assertSame(ConsoleKernel::SUCCESS, $status);
        self::assertStringContainsString('customer:sync', $output);
        self::assertStringContainsString('daily at 02:00', $output);
        self::assertStringContainsString('job:review-customers', $output);
        self::assertStringContainsString('Example', $output);
        // The thing somebody reads this command to find out.
        self::assertStringContainsString('schedule:run', $output);
    }

    public function test_schedule_list_can_be_narrowed_to_a_module(): void
    {
        [, $output] = $this->console($this->app(), 'schedule:list', '--module=ExampleGateway');

        self::assertStringContainsString('Nothing matches.', $output);
    }

    public function test_schedule_run_does_nothing_when_nothing_is_due(): void
    {
        $app = $this->fixtureApplication(['scheduler' => ['lock' => 'memory']])->boot();

        [$status, $output] = $this->console($app, 'schedule:run');

        self::assertSame(ConsoleKernel::SUCCESS, $status);
        self::assertStringContainsString('Nothing is due', $output);
    }

    public function test_schedule_run_forces_one_task_by_id(): void
    {
        [$status, $output] = $this->console(
            $this->app(),
            'schedule:run',
            '--id=customer:sync',
            '--force',
        );

        self::assertSame(ConsoleKernel::SUCCESS, $status);
        self::assertStringContainsString('ran', $output);
        // The command's own output, indented under the line that ran it.
        self::assertStringContainsString('| Synced', $output);
    }

    public function test_schedule_run_refuses_an_unknown_id(): void
    {
        [$status, $output] = $this->console($this->app(), 'schedule:run', '--id=nope');

        self::assertSame(ConsoleKernel::FAILURE, $status);
        self::assertStringContainsString('schedule:list', $output);
    }

    public function test_schedule_run_will_not_run_something_that_is_not_due(): void
    {
        [$status, $output] = $this->console($this->app(), 'schedule:run', '--id=customer:sync');

        self::assertSame(ConsoleKernel::SUCCESS, $status);
        self::assertStringContainsString('not due', $output);
        self::assertStringContainsString('--force', $output);
    }

    public function test_a_dry_run_runs_nothing(): void
    {
        $app = $this->app();

        [$status, $output] = $this->console(
            $app,
            'schedule:run',
            '--id=customer:sync',
            '--force',
            '--dry-run',
        );

        self::assertSame(ConsoleKernel::SUCCESS, $status);
        self::assertStringContainsString('would run customer:sync', $output);
        self::assertStringNotContainsString('Synced', $output);
    }

    /** A held lock is visible, releasable, and skips the task while it is held. */
    public function test_schedule_unlock_reports_and_releases(): void
    {
        $app = $this->app();
        $app->container()->get(ScheduleLock::class)->acquire('customer:sync', 600);

        [, $listed] = $this->console($app, 'schedule:unlock');

        self::assertStringContainsString('customer:sync', $listed);
        self::assertStringContainsString('Release one with', $listed);

        [, $skipped] = $this->console($app, 'schedule:run', '--id=customer:sync', '--force');

        self::assertStringContainsString('skipped', $skipped);

        [$status, $released] = $this->console($app, 'schedule:unlock', '--id=customer:sync');

        self::assertSame(ConsoleKernel::SUCCESS, $status);
        self::assertStringContainsString('released customer:sync', $released);
        self::assertSame([], $app->container()->get(ScheduleLock::class)->held());
    }

    /** @return array{int, string} */
    private function console(Application $app, string ...$arguments): array
    {
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        $status = (new ConsoleKernel(
            $app->container()->get(CommandRegistry::class),
            $app->container()->get(CommandDispatcher::class),
            $app->container()->get(HookEngine::class),
            $app->container()->get(ErrorHandler::class),
            new Output($stream),
        ))->handle(ExecutionContext::cli(\array_values(['laika', ...$arguments])));

        \rewind($stream);
        $output = (string) \stream_get_contents($stream);
        \fclose($stream);

        return [$status, $output];
    }
}
