<?php

declare(strict_types=1);

namespace App\Engine\Queue;

use App\Engine\Container\Container;
use App\Engine\Hook\HookEngine;
use App\Engine\Observability\TraceKind;
use App\Engine\Observability\Tracer;
use App\Engine\Support\Sealer;

/**
 * Runs one job, and announces that it did.
 *
 * Small on purpose, and shared by the two things that run jobs: a Worker
 * draining a queue, and the sync store running one where it was dispatched.
 * Neither gets its own idea of what "running a job" means, so a hook fires
 * identically whether or not an application has a worker.
 *
 * The job's handle() is called through the container, which is where its
 * arguments come from. That is the same mechanism a route handler, a console
 * command and a module's onBoot use -- there is one way to be given your
 * collaborators in this framework, and this is it.
 *
 * Exceptions are not caught here. What to do about a failure is a policy
 * question with completely different answers for a worker (retry, back off,
 * eventually record it) and for a synchronous dispatch (there is no later; the
 * caller gets the exception). This does the part they agree on.
 */
final class JobRunner
{
    public const METHOD = 'handle';

    public function __construct(
        private readonly Container $container,
        private readonly HookEngine $hooks,
        private readonly ?Tracer $tracer = null,
        /** Opens what Queue sealed; see Support\Sealer. */
        private readonly Sealer $sealer = new Sealer(),
    ) {}

    /**
     * Run it, inside a trace of its own.
     *
     * The job gets its own id and the correlation it was queued with, so every
     * line it logs can be found both as "this job" and as "what that request
     * caused". The trace ends however the job does -- a failing job run
     * synchronously must not leave its id on the rest of the request's log.
     *
     * @throws \Throwable whatever the job threw
     */
    public function run(QueuedJob $queued): void
    {
        if ($this->tracer === null) {
            $this->execute($queued);

            return;
        }

        $this->tracer->within(
            $this->tracer->begin(TraceKind::Job, $queued->correlationId),
            fn() => $this->execute($queued),
        );
    }

    private function execute(QueuedJob $queued): void
    {
        $job = $queued->job($this->sealer);

        $this->assertHandleable($job);

        $this->hooks->do('job.started', $queued, $job);

        $this->container->call([$job, self::METHOD]);

        $this->hooks->do('job.finished', $queued, $job);
    }

    /**
     * A job has to be able to do something.
     *
     * Checked when a job is dispatched as well as when it runs, which is the
     * point: the mistake is made while writing the dispatch, and finding out
     * then costs a failing test rather than a job that sits in a failed list
     * until somebody reads it.
     */
    public function assertHandleable(Job $job): void
    {
        if (!\method_exists($job, self::METHOD)) {
            throw QueueException::notHandleable($job::class);
        }
    }
}
