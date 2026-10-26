<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Queue\Queue;
use App\Engine\Queue\QueuedJob;

/**
 * The jobs that gave up, and what to do about them.
 *
 * A failed list only earns its keep if somebody can act on it, so this lists,
 * retries and discards rather than only listing. The three belong in one
 * command because they are one conversation: read the error, decide whether the
 * cause is gone, then retry or forget.
 *
 * Retrying resets the attempt count. Somebody has looked and decided the reason
 * has passed -- the gateway is back, the bad row is fixed -- and a retry that
 * immediately exhausted the attempts it had already spent would answer a
 * question nobody asked.
 */
final class QueueFailedCommand
{
    public function __construct(private readonly Queue $queue) {}

    public function __invoke(
        Output $output,
        ?string $retry = null,
        ?string $forget = null,
        bool $retryAll = false,
    ): int {
        $failed = $this->queue->failed();

        if ($retry !== null || $retryAll) {
            return $this->retry($output, $failed, $retryAll ? null : $retry);
        }

        if ($forget !== null) {
            return $this->forget($output, $forget);
        }

        if ($failed === []) {
            $output->success('No jobs have failed.');

            return 0;
        }

        $output->table(
            ['ID', 'QUEUE', 'JOB', 'TRIES', 'ERROR'],
            \array_map(static fn(QueuedJob $job): array => [
                $job->id,
                $job->queue,
                self::shortName($job->class),
                (string) $job->attempts,
                self::firstLine($job->error ?? ''),
            ], $failed),
        );

        $output->line();
        $output->line('Retry one with --retry=<id>, every one with --retry-all, discard one with --forget=<id>.');

        return 1;
    }

    /** @param list<QueuedJob> $failed */
    private function retry(Output $output, array $failed, ?string $id): int
    {
        if ($id !== null) {
            if (!$this->queue->retry($id)) {
                $output->error('No failed job has the id ' . $id . '.');

                return 1;
            }

            $output->success('Queued again: ' . $id);

            return 0;
        }

        $count = 0;

        foreach ($failed as $job) {
            $count += (int) $this->queue->retry($job->id);
        }

        $output->success(\sprintf('Queued %d job%s again.', $count, $count === 1 ? '' : 's'));

        return 0;
    }

    private function forget(Output $output, string $id): int
    {
        $this->queue->forget($id);

        $output->success('Discarded ' . $id . '.');

        return 0;
    }

    private static function shortName(string $class): string
    {
        $position = \strrpos($class, '\\');

        return $position === false ? $class : \substr($class, $position + 1);
    }

    private static function firstLine(string $error): string
    {
        $line = \strtok($error, "\n");

        return $line === false ? '' : (\strlen($line) > 60 ? \substr($line, 0, 57) . '...' : $line);
    }
}
