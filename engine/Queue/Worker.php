<?php

declare(strict_types=1);

namespace App\Engine\Queue;

use App\Engine\Hook\HookEngine;

/**
 * The loop that takes jobs off a queue and runs them.
 *
 * Reserve, run, acknowledge. When the job throws: put it back with a delay, or,
 * once it has had its attempts, record it as failed. That is the whole worker,
 * and everything interesting about it is in what happens when something goes
 * wrong.
 *
 * **A crashed worker loses nothing.** A reservation expires, and an expired
 * reservation is available again -- so a worker that is killed mid-job costs
 * one retry rather than one lost job. This is also why there is no signal
 * handling here: pcntl does not exist on Windows, a guarded call to it would be
 * code nobody in this project can run, and correctness does not need it. The
 * cost of a hard kill is that the job runs again, which is the same cost every
 * at-least-once queue charges.
 *
 * **A job that kills its worker still runs out of attempts.** The attempt count
 * is incremented when the job is reserved, not when it finishes, so a job that
 * segfaults PHP every time is recorded as failed after its tries instead of
 * cycling forever. Counting on success would be the obvious way round and the
 * wrong one.
 *
 * **Timeout is a reservation, not an interruption.** Stopping a job in the
 * middle of a network read needs pcntl_alarm or a tick handler, neither of
 * which is portable here. What the timeout actually does is bound how long a
 * job can hold its claim: after it, another worker may take the job, so a
 * hung process delays work rather than stopping it. set_time_limit() is asked
 * as well, which handles the runaway-loop case where it is supported, and the
 * two together are what this framework can honestly enforce.
 */
final class Worker
{
    private ?string $stoppedBy = null;

    public function __construct(
        private readonly Queue $queue,
        private readonly JobRunner $runner,
        private readonly HookEngine $hooks,
        private readonly Backoff $backoff = new Backoff(),
    ) {}

    /**
     * Take one job, if there is one.
     */
    public function runOnce(WorkerOptions $options): JobOutcome
    {
        $queue = $this->queue->name($options->queue);
        $job = $this->queue->store()->reserve($queue, $options->timeout);

        if ($job === null) {
            return JobOutcome::Idle;
        }

        $this->limitRuntime($options->timeout);

        try {
            $this->runner->run($job);
            $this->queue->store()->acknowledge($job);

            return JobOutcome::Completed;
        } catch (\Throwable $e) {
            return $this->handleFailure($job, $e, $options);
        } finally {
            $this->limitRuntime(0);
        }
    }

    /** Why the last run() ended: "12 jobs", "3600s", "memory (130M used)", "the queue is empty". */
    public function stoppedBy(): ?string
    {
        return $this->stoppedBy;
    }

    /**
     * Work until one of the limits says to stop.
     *
     * @return array<string, int> how many of each outcome
     */
    public function run(WorkerOptions $options): array
    {
        $started = \time();
        $summary = [];

        foreach (JobOutcome::cases() as $case) {
            $summary[$case->value] = 0;
        }

        $processed = 0;
        $this->stoppedBy = null;

        while (true) {
            $outcome = $this->runOnce($options);
            ++$summary[$outcome->value];

            if ($outcome === JobOutcome::Idle) {
                if ($options->stopWhenEmpty) {
                    $this->stoppedBy = 'the queue is empty';

                    break;
                }

                $this->rest($options->sleep);
            } else {
                ++$processed;
            }

            if ($options->maxJobs > 0 && $processed >= $options->maxJobs) {
                $this->stoppedBy = $processed . ' jobs';

                break;
            }

            if ($options->maxSeconds > 0 && (\time() - $started) >= $options->maxSeconds) {
                $this->stoppedBy = $options->maxSeconds . 's';

                break;
            }

            // Between jobs, so a job is never cut off by this. The process
            // exits and the supervisor starts a fresh one, which is the clean
            // alternative to PHP killing it mid-job at memory_limit.
            $used = \memory_get_usage(true);

            if ($options->maxMemory > 0 && $used >= $options->maxMemory) {
                $this->stoppedBy = \sprintf('memory (%dM used)', \intdiv($used, 1048576));

                break;
            }
        }

        return $summary;
    }

    /**
     * Retry, or give up.
     *
     * The decision is the attempt count, which already includes the attempt
     * that just failed. Nothing here inspects the exception: a worker that
     * tried to tell a transient failure from a permanent one would be guessing
     * on the application's behalf, and an application that knows the difference
     * can say so by catching its own exception and not throwing.
     */
    private function handleFailure(QueuedJob $job, \Throwable $e, WorkerOptions $options): JobOutcome
    {
        $willRetry = $job->attempts < \max(1, $options->tries);

        $this->hooks->do('job.failed', $e, $job, $willRetry);

        if ($willRetry) {
            $this->queue->store()->release($job->releasedAfter($this->backoff->after($job->attempts)));

            return JobOutcome::Released;
        }

        $this->queue->store()->fail($job->failedWith($e));

        return JobOutcome::Failed;
    }

    /**
     * Ask PHP to stop a job that will not stop itself.
     *
     * Best effort, and only under the CLI where a worker actually runs. It
     * catches an accidental infinite loop; it will not interrupt a socket read,
     * which is what the reservation is for.
     */
    private function limitRuntime(int $seconds): void
    {
        if (\PHP_SAPI === 'cli') {
            @\set_time_limit($seconds);
        }
    }

    /**
     * Wait before looking again.
     *
     * A polling queue that does not sleep is a queue that spends a core doing
     * nothing. One second is short enough that nobody notices and long enough
     * that the process is idle.
     */
    private function rest(int $seconds): void
    {
        if ($seconds > 0) {
            \sleep($seconds);
        }
    }
}
