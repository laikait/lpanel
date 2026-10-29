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
use App\Engine\Http\Request;
use App\Engine\Logging\LogManager;
use App\Engine\Queue\JobOutcome;
use App\Engine\Queue\Queue;
use App\Engine\Queue\QueuedJob;
use App\Engine\Queue\Stores\FileStore;
use App\Engine\Queue\Stores\MemoryStore;
use App\Engine\Queue\Stores\SyncStore;
use App\Engine\Queue\Worker;
use App\Engine\Queue\WorkerOptions;
use App\Engine\Security\Signer;
use App\Tests\Fixtures\Logging\CollectingWriter;
use App\Tests\Fixtures\Queue\FailingJob;
use App\Tests\Fixtures\Queue\RecordingJob;
use App\Tests\Fixtures\Showcase\Plugins\Example\Jobs\WelcomeCustomer;
use App\Tests\Support\TestCase;

/**
 * The queue through a real application.
 *
 * The claim being checked is the one that makes a queue worth having in a
 * framework rather than in an application: the line that dispatches work is the
 * same line whether or not there is a worker, and which of those is true is
 * configuration.
 */
final class QueueSliceTest extends TestCase
{
    protected function setUp(): void
    {
        RecordingJob::reset();
        FailingJob::reset();
    }

    protected function tearDown(): void
    {
        $directory = $this->basePath('system/Queue');

        if (\is_dir($directory)) {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($entries as $entry) {
                if ($entry instanceof \SplFileInfo) {
                    $entry->isDir() ? @\rmdir($entry->getPathname()) : @\unlink($entry->getPathname());
                }
            }

            @\rmdir($directory);
        }

        parent::tearDown();
    }

    /** @param array<string, mixed> $config */
    private function app(array $config = []): Application
    {
        return $this->application($config)->boot();
    }

    // ---- wiring --------------------------------------------------------------

    public function test_a_queue_is_injectable_and_synchronous_by_default(): void
    {
        $queue = $this->app()->container()->get(Queue::class);

        self::assertInstanceOf(SyncStore::class, $queue->store());
    }

    public function test_the_store_is_a_configuration_decision(): void
    {
        self::assertInstanceOf(
            FileStore::class,
            $this->app(['queue' => ['store' => 'file']])->container()->get(Queue::class)->store(),
        );

        self::assertInstanceOf(
            MemoryStore::class,
            $this->app(['queue' => ['store' => 'memory']])->container()->get(Queue::class)->store(),
        );
    }

    /** A typo in a deployment's configuration must not stop the application. */
    public function test_an_unknown_store_falls_back_to_running_the_work(): void
    {
        self::assertInstanceOf(
            SyncStore::class,
            $this->app(['queue' => ['store' => 'rabbit']])->container()->get(Queue::class)->store(),
        );
    }

    // ---- the same line, two behaviours -----------------------------------------

    /**
     * The default: no worker, no infrastructure, and the work still happens --
     * in the request that asked for it.
     */
    public function test_with_no_queue_configured_a_dispatched_job_runs_immediately(): void
    {
        $queue = $this->app()->container()->get(Queue::class);

        $queue->push(new RecordingJob('inline'));

        self::assertSame(['inline'], RecordingJob::$ran);
    }

    /**
     * And a job that throws breaks the caller, because there is nothing here to
     * retry from. Pretending otherwise would hide the failure.
     */
    public function test_a_synchronous_job_that_throws_reaches_the_caller(): void
    {
        $queue = $this->app()->container()->get(Queue::class);

        $this->expectException(\RuntimeException::class);

        $queue->push(new FailingJob('inline failure'));
    }

    public function test_with_a_queue_configured_the_same_line_defers_the_work(): void
    {
        $app = $this->app(['queue' => ['store' => 'file']]);
        $queue = $app->container()->get(Queue::class);

        $queue->push(new RecordingJob('deferred'));

        self::assertSame([], RecordingJob::$ran, 'nothing ran in this process');
        self::assertSame(1, $queue->pending());

        $app->container()->get(Worker::class)->run(WorkerOptions::drain());

        self::assertSame(['deferred'], RecordingJob::$ran);
        self::assertSame(0, $queue->pending());
    }

    // ---- a module's job ----------------------------------------------------------

    /**
     * A job lives in the module that owns the work and is not registered
     * anywhere: the class name is the registration.
     */
    public function test_a_request_dispatches_a_module_owned_job(): void
    {
        $app = $this->app(['queue' => ['store' => 'file']]);

        $response = $app->handle(Request::create('POST', '/api/v1/customers', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"name":"Ada Queue","email":"ada.queue@example.test"}',
        ]));

        self::assertSame(201, $response->status());

        $queue = $app->container()->get(Queue::class);

        self::assertSame(1, $queue->pending(), 'the response did not wait for the follow-up work');

        $waiting = $queue->store()->reserve($queue->defaultQueue(), 60);

        self::assertNotNull($waiting);
        self::assertSame(WelcomeCustomer::class, $waiting->class);
        self::assertInstanceOf(WelcomeCustomer::class, $waiting->job());
    }

    /**
     * The job runs in a process that knows nothing about the request that
     * queued it, and gets its collaborators from that process's container.
     */
    public function test_the_worker_runs_it_with_its_own_dependencies(): void
    {
        $app = $this->app(['queue' => ['store' => 'file']]);

        $app->handle(Request::create('POST', '/api/v1/customers', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"name":"Grace Queue","email":"grace.queue@example.test"}',
        ]));

        // A second application, over the same queue directory: as close to
        // another process as a test can get.
        $worker = $this->app(['queue' => ['store' => 'file']]);
        $writer = new CollectingWriter();
        $worker->container()->get(LogManager::class)->add($writer);

        $outcome = $worker->container()->get(Worker::class)->runOnce(WorkerOptions::once());

        self::assertSame(JobOutcome::Completed, $outcome);
        self::assertCount(1, $writer->records);
        self::assertSame('Welcoming a customer', $writer->records[0]->message);
        // Three, not four: this application has its own in-memory data source,
        // seeded with the demo rows, and knows nothing about the customer the
        // other one registered. That is exactly what a worker in another
        // process looks like, and it is why the job carries an id and reads the
        // rest itself rather than carrying a model across.
        self::assertSame(3, $writer->records[0]->context['customers'], 'read where it ran, not where it was queued');
    }

    // ---- failure, end to end -------------------------------------------------------

    public function test_a_job_that_keeps_failing_ends_up_in_the_failed_list(): void
    {
        // No backoff, so the retries happen inside one drain. With the default
        // five seconds the second attempt would not be due yet and the drain
        // would stop with the job still waiting -- which is the right behaviour
        // and a slow test.
        $app = $this->app(['queue' => ['store' => 'file', 'backoff' => ['base' => 0]]]);
        $queue = $app->container()->get(Queue::class);

        $queue->push(new FailingJob('nope'));

        $app->container()->get(Worker::class)->run(new WorkerOptions(tries: 2, sleep: 0, stopWhenEmpty: true));

        self::assertSame(0, $queue->pending());
        self::assertCount(1, $queue->failed());
        self::assertSame(2, $queue->failed()[0]->attempts);
    }

    /**
     * With an APP_KEY every payload is sealed when it is queued and checked
     * before the worker unserializes it -- so the queue and the runner were
     * handed the same key -- and a job written into the store by anybody else
     * is refused rather than run.
     */
    public function test_with_a_key_only_jobs_queued_here_are_run(): void
    {
        $app = $this->app(['queue' => ['store' => 'memory'], 'security' => ['key' => Signer::generate()]]);
        $queue = $app->container()->get(Queue::class);

        $queue->push(new RecordingJob('sealed'));
        $queue->store()->push(new QueuedJob(
            id: '0000000000forged',
            queue: $queue->defaultQueue(),
            class: RecordingJob::class,
            payload: \serialize(new RecordingJob('forged')),
        ));

        $app->container()->get(Worker::class)->run(new WorkerOptions(tries: 1, sleep: 0, stopWhenEmpty: true));

        self::assertSame(['sealed'], RecordingJob::$ran);
        self::assertCount(1, $queue->store()->failed());
    }

    /** A failure is announced, which is how the log hears about it without the queue knowing. */
    public function test_a_failure_is_announced_on_a_hook(): void
    {
        $app = $this->app(['queue' => ['store' => 'memory']]);
        $seen = 0;

        $app->container()->get(HookEngine::class)->add(
            'job.failed',
            static function (\Throwable $e, QueuedJob $job, bool $willRetry) use (&$seen): void {
                ++$seen;
            },
        );

        $app->container()->get(Queue::class)->push(new FailingJob());
        $app->container()->get(Worker::class)->run(new WorkerOptions(tries: 1, sleep: 0, stopWhenEmpty: true));

        self::assertSame(1, $seen);
    }

    // ---- the console -----------------------------------------------------------------

    public function test_queue_status_reports_what_is_waiting(): void
    {
        $app = $this->app(['queue' => ['store' => 'file']]);
        $app->container()->get(Queue::class)->push(new RecordingJob('waiting'));

        [$status, $output] = $this->console($app, 'queue:status');

        self::assertSame(ConsoleKernel::SUCCESS, $status);
        self::assertStringContainsString('default', $output);
        self::assertStringContainsString('file', $output);
    }

    public function test_queue_status_says_when_nothing_is_meant_to_wait(): void
    {
        [, $output] = $this->console($this->app(), 'queue:status');

        self::assertStringContainsString('run where they are dispatched', $output);
    }

    public function test_queue_work_drains_the_queue(): void
    {
        $app = $this->app(['queue' => ['store' => 'file']]);
        $app->container()->get(Queue::class)->push(new RecordingJob('worked'));

        [$status, $output] = $this->console($app, 'queue:work', '--drain');

        self::assertSame(ConsoleKernel::SUCCESS, $status);
        self::assertStringContainsString('Completed', $output);
        self::assertSame(['worked'], RecordingJob::$ran);
    }

    public function test_queue_work_stops_between_jobs_at_its_memory_limit(): void
    {
        $app = $this->app(['queue' => ['store' => 'file']]);
        $queue = $app->container()->get(Queue::class);
        $queue->push(new RecordingJob('first'));
        $queue->push(new RecordingJob('second'));

        [$status, $output] = $this->console($app, 'queue:work', '--drain', '--memory=1K');

        self::assertSame(ConsoleKernel::SUCCESS, $status);
        self::assertCount(1, RecordingJob::$ran, 'one job ran; the other waits for a fresh worker');
        self::assertSame(1, $queue->pending());
        self::assertStringContainsString('Worker stopped: memory (', $output);
    }

    public function test_queue_work_refuses_a_memory_limit_that_is_not_a_size(): void
    {
        $app = $this->app(['queue' => ['store' => 'file']]);
        $app->container()->get(Queue::class)->push(new RecordingJob('never'));

        [$status, $output] = $this->console($app, 'queue:work', '--drain', '--memory=lots');

        self::assertNotSame(ConsoleKernel::SUCCESS, $status);
        self::assertStringContainsString('"lots" is not a size such as 128M or 1G', $output);
        self::assertSame([], RecordingJob::$ran);
    }

    public function test_queue_work_reports_a_failure_in_its_exit_code(): void
    {
        $app = $this->app(['queue' => ['store' => 'file']]);
        $app->container()->get(Queue::class)->push(new FailingJob());

        [$status] = $this->console($app, 'queue:work', '--drain', '--tries=1');

        self::assertSame(ConsoleKernel::FAILURE, $status, 'a cron line can be alerted on this');
    }

    public function test_queue_failed_lists_and_retries(): void
    {
        $app = $this->app(['queue' => ['store' => 'file', 'backoff' => ['base' => 0]]]);
        $queue = $app->container()->get(Queue::class);
        $queue->push(new FailingJob());

        $app->container()->get(Worker::class)->run(new WorkerOptions(tries: 1, sleep: 0, stopWhenEmpty: true));

        [$status, $output] = $this->console($app, 'queue:failed');

        self::assertSame(ConsoleKernel::FAILURE, $status);
        self::assertStringContainsString('FailingJob', $output);

        [$retried] = $this->console($app, 'queue:failed', '--retry-all');

        self::assertSame(ConsoleKernel::SUCCESS, $retried);
        self::assertSame(1, $queue->pending());
        self::assertSame([], $queue->failed());
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
