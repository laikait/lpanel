<?php

declare(strict_types=1);

namespace App\Engine\Queue;

/**
 * How a worker should behave on this run.
 *
 * A value object rather than eight parameters, because the answers come from
 * three places -- configuration, the command line, and a test -- and threading
 * eight arguments through all three is how two of them end up disagreeing about
 * the default.
 *
 * The limits deserve a word. A worker that runs forever is a worker whose
 * memory only goes up, whose code is whatever was deployed when it started, and
 * which nobody notices has been holding a stale database connection since
 * Tuesday. Bounded runs are the standard answer: the process does a decent
 * amount of work, exits cleanly, and whatever supervises it starts another with
 * the current code. --max-jobs, --max-time and --memory are how that is said
 * here.
 */
final class WorkerOptions
{
    // A readonly class would say this once. The properties carry it
    // individually instead, because composer.json declares PHP 8.1 and
    // readonly classes arrived in 8.2; readonly properties did not.
    public function __construct(
        /** Which queue to drain; null means the configured default. */
        public readonly ?string $queue = null,
        /** How many attempts a job gets before it is recorded as failed. */
        public readonly int $tries = 3,
        /** How long a job may hold its reservation, in seconds. */
        public readonly int $timeout = 60,
        /** Seconds to wait when the queue is empty, before looking again. */
        public readonly int $sleep = 1,
        /** Stop after this many jobs; 0 means no limit. */
        public readonly int $maxJobs = 0,
        /** Stop after this many seconds; 0 means no limit. */
        public readonly int $maxSeconds = 0,
        /** Stop as soon as the queue is empty rather than waiting for more. */
        public readonly bool $stopWhenEmpty = false,
        /**
         * Stop, between jobs, once the process uses this many bytes; 0 means
         * no limit. Checked after a job, never during one, so it should sit
         * below memory_limit by at least what one job needs.
         */
        public readonly int $maxMemory = 0,
    ) {}

    /** One job, or none, and then stop. */
    public static function once(?string $queue = null, int $tries = 3, int $timeout = 60): self
    {
        return new self(
            queue: $queue,
            tries: $tries,
            timeout: $timeout,
            sleep: 0,
            maxJobs: 1,
            stopWhenEmpty: true,
        );
    }

    /** Drain what is there and stop -- a deployment step, or a cron tick. */
    public static function drain(?string $queue = null): self
    {
        return new self(queue: $queue, sleep: 0, stopWhenEmpty: true);
    }

    public function describe(): string
    {
        $limits = [];

        if ($this->maxJobs > 0) {
            $limits[] = $this->maxJobs . ' jobs';
        }

        if ($this->maxSeconds > 0) {
            $limits[] = $this->maxSeconds . 's';
        }

        if ($this->maxMemory > 0) {
            $limits[] = \intdiv($this->maxMemory, 1048576) . 'M of memory';
        }

        if ($this->stopWhenEmpty) {
            $limits[] = 'until empty';
        }

        return $limits === [] ? 'until stopped' : \implode(', ', $limits);
    }
}
