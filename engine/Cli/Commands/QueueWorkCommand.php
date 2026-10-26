<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\ConsoleException;
use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Engine\Queue\JobOutcome;
use App\Engine\Queue\Queue;
use App\Engine\Queue\Worker;
use App\Engine\Queue\WorkerOptions;
use App\Engine\Security\RequestLimits;

/**
 * Run jobs off a queue.
 *
 * The process a deployment keeps alive, usually under systemd, supervisor or a
 * container restart policy. It is deliberately easy to stop: --max-jobs and
 * --max-time bound a run so that the supervisor starts a fresh process with the
 * current code, a fresh connection and a fresh memory footprint. A worker that
 * runs for a month is running last month's deployment.
 *
 * The exit code says what happened rather than only whether the process ended.
 * A run that recorded a failure exits 1, so a cron line that drains a queue can
 * be alerted on without parsing this output.
 */
final class QueueWorkCommand
{
    public function __construct(
        private readonly Worker $worker,
        private readonly Queue $queue,
        private readonly Config $config,
    ) {}

    public function __invoke(
        Output $output,
        ?string $queue = null,
        bool $once = false,
        bool $drain = false,
        int $maxJobs = 0,
        int $maxTime = 0,
        ?int $tries = null,
        ?int $timeout = null,
        ?int $sleep = null,
        ?string $memory = null,
    ): int {
        $name = $this->queue->name($queue);

        $options = new WorkerOptions(
            queue: $name,
            tries: $tries ?? $this->config->int('queue.tries', 3) ?? 3,
            timeout: $timeout ?? $this->config->int('queue.timeout', 60) ?? 60,
            sleep: $sleep ?? $this->config->int('queue.sleep', 1) ?? 1,
            maxJobs: $once ? 1 : \max(0, $maxJobs),
            maxSeconds: \max(0, $maxTime),
            stopWhenEmpty: $once || $drain,
            maxMemory: $this->maxMemory($memory),
        );

        $output->pairs([
            'Queue' => $name,
            'Store' => $this->queue->store()->describe(),
            'Waiting' => (string) $this->queue->pending($name),
            'Attempts' => (string) $options->tries,
            'Timeout' => $options->timeout . 's per job',
            'Until' => $options->describe(),
        ]);

        if ($this->queue->pending($name) === 0 && $options->stopWhenEmpty) {
            $output->line();
            $output->success('Nothing to do.');

            return 0;
        }

        $output->line();

        $summary = $this->worker->run($options);

        $output->line();
        $output->pairs([
            'Completed' => (string) $summary[JobOutcome::Completed->value],
            'Released' => (string) $summary[JobOutcome::Released->value],
            'Failed' => (string) $summary[JobOutcome::Failed->value],
        ]);

        if ($summary[JobOutcome::Failed->value] > 0) {
            $output->error('Some jobs were given up on. See: php laika queue:failed');

            return 1;
        }

        $output->success('Worker stopped' . ($this->worker->stoppedBy() === null ? '.' : ': ' . $this->worker->stoppedBy() . '.'));

        return 0;
    }

    /**
     * --memory, else queue.max_memory, else 80% of memory_limit.
     *
     * The last is the one that matters in practice: with a limit set and no
     * worker setting, a worker still exits between jobs well before PHP would
     * kill it in the middle of one. No limit at all (-1) means no default.
     */
    private function maxMemory(?string $option): int
    {
        $configured = $option ?? $this->config->string('queue.max_memory');

        if ($configured !== null && \trim($configured) !== '') {
            $bytes = RequestLimits::toBytes($configured);

            if ($bytes <= 0) {
                throw ConsoleException::valueRejected('queue:work', 'memory', 'a size such as 128M or 1G', $configured);
            }

            return $bytes;
        }

        $limit = RequestLimits::toBytes((string) \ini_get('memory_limit'));

        return $limit > 0 ? (int) ($limit * 0.8) : 0;
    }
}
