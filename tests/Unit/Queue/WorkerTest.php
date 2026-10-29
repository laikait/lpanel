<?php

declare(strict_types=1);

namespace App\Tests\Unit\Queue;

use App\Engine\Container\Container;
use App\Engine\Hook\HookEngine;
use App\Engine\Observability\TraceKind;
use App\Engine\Observability\Tracer;
use App\Engine\Queue\Backoff;
use App\Engine\Queue\JobOutcome;
use App\Engine\Queue\JobRunner;
use App\Engine\Queue\Queue;
use App\Engine\Queue\QueuedJob;
use App\Engine\Queue\QueueException;
use App\Engine\Queue\Stores\MemoryStore;
use App\Engine\Queue\Worker;
use App\Engine\Queue\WorkerOptions;
use App\Tests\Fixtures\Queue\FailingJob;
use App\Tests\Fixtures\Queue\InjectedJob;
use App\Tests\Fixtures\Queue\RecordingJob;
use App\Tests\Fixtures\Queue\TracedJob;
use App\Tests\Fixtures\Queue\UnhandleableJob;
use App\Tests\Support\TestCase;

/**
 * Dispatching work, and the loop that does it.
 *
 * Everything interesting about a worker is in what happens when a job throws,
 * so most of this is about retries, attempt counting and the point at which it
 * gives up.
 */
final class WorkerTest extends TestCase
{
    private MemoryStore $store;

    private HookEngine $hooks;

    private Queue $queue;

    private Worker $worker;

    protected function setUp(): void
    {
        RecordingJob::reset();
        FailingJob::reset();
        InjectedJob::reset();

        $container = new Container();
        $container->instance(\App\Engine\Config\Config::class, new \App\Engine\Config\Config(['app' => ['env' => 'testing']]));

        $this->store = new MemoryStore();
        $this->hooks = new HookEngine();
        $runner = new JobRunner($container, $this->hooks);
        $this->queue = new Queue($this->store, $runner, $this->hooks);
        $this->worker = new Worker($this->queue, $runner, $this->hooks, Backoff::fixed(0));
    }

    private function options(int $tries = 3): WorkerOptions
    {
        return new WorkerOptions(tries: $tries, timeout: 60, sleep: 0, stopWhenEmpty: true);
    }

    // ---- dispatching ---------------------------------------------------------

    public function test_a_dispatched_job_waits_on_the_queue(): void
    {
        $this->queue->push(new RecordingJob('one'));

        self::assertSame(1, $this->queue->pending());
        self::assertSame([], RecordingJob::$ran, 'nothing runs until a worker runs it');
    }

    public function test_the_id_identifies_the_job(): void
    {
        $id = $this->queue->push(new RecordingJob('one'));

        self::assertNotSame('', $id);
        self::assertSame($id, $this->store->reserve('default', 60)?->id);
    }

    public function test_a_job_can_be_put_on_another_queue(): void
    {
        $this->queue->push(new RecordingJob('billing job'), 'billing');

        self::assertSame(0, $this->queue->pending());
        self::assertSame(1, $this->queue->pending('billing'));
    }

    public function test_a_queue_name_that_is_not_a_name_is_refused(): void
    {
        $this->expectException(QueueException::class);

        $this->queue->push(new RecordingJob(), 'Billing Reports');
    }

    /**
     * Caught while dispatching rather than in a worker an hour later: the
     * mistake is in the line being written.
     */
    public function test_a_job_that_cannot_do_anything_is_refused_at_dispatch(): void
    {
        $this->expectException(QueueException::class);
        $this->expectExceptionMessageMatches('/handle\(\)/');

        $this->queue->push(new UnhandleableJob());
    }

    public function test_a_job_that_cannot_be_serialised_is_refused_at_dispatch(): void
    {
        $job = new class implements \App\Engine\Queue\Job {
            public \Closure $callback;

            public function __construct()
            {
                $this->callback = static fn(): int => 1;
            }

            public function handle(): void {}
        };

        $this->expectException(QueueException::class);

        $this->queue->push($job);
    }

    // ---- running ---------------------------------------------------------------

    public function test_a_worker_runs_what_was_dispatched(): void
    {
        $this->queue->push(new RecordingJob('one'));

        self::assertSame(JobOutcome::Completed, $this->worker->runOnce($this->options()));
        self::assertSame(['one'], RecordingJob::$ran);
        self::assertSame(0, $this->queue->pending());
    }

    public function test_an_empty_queue_is_idle(): void
    {
        self::assertSame(JobOutcome::Idle, $this->worker->runOnce($this->options()));
    }

    /** The job that runs is rebuilt from the payload, not the object dispatched. */
    public function test_the_job_is_rebuilt_rather_than_kept(): void
    {
        $job = new RecordingJob('carried');
        $this->queue->push($job);

        $this->worker->runOnce($this->options());

        self::assertSame(['carried'], RecordingJob::$ran, 'its constructor data crossed the queue');
    }

    public function test_handle_receives_injected_collaborators(): void
    {
        $this->queue->push(new InjectedJob(7));

        $this->worker->runOnce($this->options());

        self::assertSame('testing:7', InjectedJob::$sawEnvironment);
    }

    public function test_a_run_drains_the_queue(): void
    {
        $this->queue->push(new RecordingJob('one'));
        $this->queue->push(new RecordingJob('two'));

        $summary = $this->worker->run($this->options());

        self::assertSame(['one', 'two'], RecordingJob::$ran);
        self::assertSame(2, $summary[JobOutcome::Completed->value]);
    }

    public function test_a_run_stops_between_jobs_once_memory_reaches_the_limit(): void
    {
        $this->queue->push(new RecordingJob('one'));
        $this->queue->push(new RecordingJob('two'));

        $this->worker->run(new WorkerOptions(sleep: 0, stopWhenEmpty: true, maxMemory: 1));

        self::assertSame(['one'], RecordingJob::$ran, 'the first job finished; the second waits for a fresh process');
        self::assertSame(1, $this->queue->pending());
        self::assertStringStartsWith('memory (', (string) $this->worker->stoppedBy());
    }

    public function test_no_memory_limit_means_the_queue_is_drained(): void
    {
        $this->queue->push(new RecordingJob('one'));
        $this->queue->push(new RecordingJob('two'));

        $this->worker->run(new WorkerOptions(sleep: 0, stopWhenEmpty: true, maxMemory: 0));

        self::assertSame(['one', 'two'], RecordingJob::$ran);
        self::assertSame('the queue is empty', $this->worker->stoppedBy());
    }

    public function test_the_memory_limit_is_described(): void
    {
        self::assertSame('128M of memory, until empty', (new WorkerOptions(maxMemory: 134217728, stopWhenEmpty: true))->describe());
    }

    public function test_a_run_stops_after_max_jobs(): void
    {
        $this->queue->push(new RecordingJob('one'));
        $this->queue->push(new RecordingJob('two'));

        $this->worker->run(new WorkerOptions(sleep: 0, maxJobs: 1, stopWhenEmpty: true));

        self::assertCount(1, RecordingJob::$ran);
        self::assertSame(1, $this->queue->pending(), 'the other one is still waiting');
    }

    // ---- failing ------------------------------------------------------------------

    public function test_a_failed_job_goes_back_on_the_queue(): void
    {
        $this->queue->push(new FailingJob());

        self::assertSame(JobOutcome::Released, $this->worker->runOnce($this->options()));
        self::assertSame(1, $this->queue->pending(), 'released, to be tried again');
        self::assertSame([], $this->queue->failed());
    }

    public function test_it_gives_up_after_the_configured_attempts(): void
    {
        $this->queue->push(new FailingJob());

        $summary = $this->worker->run($this->options(tries: 3));

        self::assertSame(3, FailingJob::$attempts);
        self::assertSame(2, $summary[JobOutcome::Released->value]);
        self::assertSame(1, $summary[JobOutcome::Failed->value]);
        self::assertSame(0, $this->queue->pending());
        self::assertCount(1, $this->queue->failed());
    }

    public function test_a_failure_keeps_what_went_wrong(): void
    {
        $this->queue->push(new FailingJob('the gateway said no'));

        $this->worker->run($this->options(tries: 1));

        $failed = $this->queue->failed();

        self::assertCount(1, $failed);
        self::assertStringContainsString('the gateway said no', $failed[0]->error ?? '');
        self::assertSame(1, $failed[0]->attempts);
    }

    public function test_one_attempt_means_one_attempt(): void
    {
        $this->queue->push(new FailingJob());

        self::assertSame(JobOutcome::Failed, $this->worker->runOnce($this->options(tries: 1)));
        self::assertSame(1, FailingJob::$attempts);
    }

    /** A worker announces a failure whether or not it is going to try again. */
    public function test_a_failure_is_announced_with_whether_it_will_be_retried(): void
    {
        $seen = [];

        $this->hooks->add('job.failed', static function (\Throwable $e, QueuedJob $job, bool $willRetry) use (&$seen): void {
            $seen[] = $willRetry;
        });

        $this->queue->push(new FailingJob());
        $this->worker->run($this->options(tries: 2));

        self::assertSame([true, false], $seen);
    }

    public function test_a_failed_job_can_be_queued_again(): void
    {
        $this->queue->push(new FailingJob());
        $this->worker->run($this->options(tries: 1));

        $failed = $this->queue->failed();
        self::assertCount(1, $failed);

        self::assertTrue($this->queue->retry($failed[0]->id));
        self::assertSame(1, $this->queue->pending());
        self::assertSame([], $this->queue->failed());
    }

    /**
     * Reset rather than continued: somebody has looked at the failure and
     * decided the reason is gone.
     */
    public function test_a_retried_job_starts_its_attempts_again(): void
    {
        $this->queue->push(new FailingJob());
        $this->worker->run($this->options(tries: 2));

        $this->queue->retry($this->queue->failed()[0]->id);
        FailingJob::reset();

        $this->worker->run($this->options(tries: 2));

        self::assertSame(2, FailingJob::$attempts, 'it got its full allowance again');
    }

    public function test_retrying_something_that_did_not_fail_says_so(): void
    {
        self::assertFalse($this->queue->retry('no-such-id'));
    }

    // ---- hooks ------------------------------------------------------------------------

    public function test_the_lifecycle_is_announced(): void
    {
        $seen = [];

        foreach (['job.queued', 'job.started', 'job.finished'] as $hook) {
            $this->hooks->add($hook, static function () use ($hook, &$seen): void {
                $seen[] = $hook;
            });
        }

        $this->queue->push(new RecordingJob('one'));
        $this->worker->runOnce($this->options());

        self::assertSame(['job.queued', 'job.started', 'job.finished'], $seen);
    }

    public function test_a_job_that_throws_does_not_announce_that_it_finished(): void
    {
        $finished = 0;

        $this->hooks->add('job.finished', static function () use (&$finished): void {
            ++$finished;
        });

        $this->queue->push(new FailingJob());
        $this->worker->runOnce($this->options());

        self::assertSame(0, $finished);
    }

    // ---- correlation ---------------------------------------------------------

    /**
     * The chain survives the queue: queued inside a request, run later as a job
     * with an id of its own and the request's correlation.
     */
    public function test_a_job_carries_the_correlation_of_the_work_that_queued_it(): void
    {
        [$queue, $worker, $tracer] = $this->traced();
        TracedJob::reset();

        $request = $tracer->beginRequest(\App\Engine\Http\Request::create('GET', '/invoices/run'));
        $queue->push(new TracedJob());
        $tracer->end($request);

        $worker->runOnce($this->options());

        $ranAs = TracedJob::$ranAs;
        self::assertNotNull($ranAs);
        self::assertSame(TraceKind::Job, $ranAs->kind);
        self::assertSame($request->id, $ranAs->correlationId);
        self::assertNotSame($request->id, $ranAs->id);
        self::assertSame([], $tracer->stack(), 'the job trace ended');
    }

    public function test_a_failing_job_ends_its_trace_too(): void
    {
        [$queue, $worker, $tracer] = $this->traced();

        $queue->push(new TracedJob(fail: true));
        $worker->runOnce($this->options());

        self::assertSame([], $tracer->stack());
    }

    /** A retried failure is still part of the chain that caused it. */
    public function test_retrying_a_failed_job_keeps_its_correlation(): void
    {
        [$queue, $worker, $tracer] = $this->traced();

        $request = $tracer->beginRequest(\App\Engine\Http\Request::create('GET', '/'));
        $id = $queue->push(new TracedJob(fail: true));
        $tracer->end($request);

        $worker->runOnce($this->options(tries: 1));
        self::assertTrue($queue->retry($id));

        self::assertSame($request->id, $queue->store()->reserve(Queue::DEFAULT, 60)?->correlationId);
    }

    /** A job queued by an older version has no correlation, and simply starts its own chain. */
    public function test_an_envelope_without_a_correlation_is_still_read(): void
    {
        $queued = QueuedJob::fromArray(['id' => 'a', 'queue' => 'default', 'class' => 'X', 'payload' => 'x']);

        self::assertNotNull($queued);
        self::assertNull($queued->correlationId);
        self::assertSame('chain-0000001', QueuedJob::fromArray([...$queued->toArray(), 'correlationId' => 'chain-0000001'])?->correlationId);
    }

    /** @return array{Queue, Worker, Tracer} */
    private function traced(): array
    {
        $tracer = new Tracer();
        $container = new Container();
        $container->instance(Tracer::class, $tracer);

        $runner = new JobRunner($container, $this->hooks, $tracer);
        $queue = new Queue(new MemoryStore(), $runner, $this->hooks, Queue::DEFAULT, $tracer);

        return [$queue, new Worker($queue, $runner, $this->hooks, Backoff::fixed(0)), $tracer];
    }
}
