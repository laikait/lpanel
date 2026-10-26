<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Queue\Queue;
use App\Engine\Queue\Stores\SyncStore;

/**
 * What is waiting, and where.
 *
 * The counterpart to log:status, and it exists for the same reason: a queue
 * that is not being drained looks exactly like a queue with nothing on it from
 * inside the application. The number that matters is not how many jobs there
 * are, it is whether that number is going down.
 *
 * A sync store says so plainly, because "no jobs are waiting" means something
 * completely different when nothing is ever meant to wait.
 */
final class QueueStatusCommand
{
    public function __construct(private readonly Queue $queue) {}

    public function __invoke(Output $output): int
    {
        $store = $this->queue->store();
        $failed = $this->queue->failed();

        $output->pairs([
            'Store' => $store->describe(),
            'Default queue' => $this->queue->defaultQueue(),
            'Failed' => $failed === [] ? 'none' : (string) \count($failed),
        ]);

        $queues = $this->queue->queues();

        if ($queues !== []) {
            $output->line();
            $output->table(
                ['QUEUE', 'WAITING'],
                \array_map(
                    fn(string $queue): array => [$queue, (string) $this->queue->pending($queue)],
                    $queues,
                ),
            );
        }

        $output->line();

        if ($store instanceof SyncStore) {
            $output->line('Nothing waits: jobs run where they are dispatched.');
            $output->line('Set queue.store to "file" and run a worker to defer them.');

            return 0;
        }

        if ($queues === []) {
            $output->line('Nothing is waiting.');
        } else {
            $output->line('Run a worker with: php laika queue:work');
        }

        if ($failed !== []) {
            $output->warning(\sprintf(
                '%d job%s gave up. See: php laika queue:failed',
                \count($failed),
                \count($failed) === 1 ? '' : 's',
            ));

            return 1;
        }

        return 0;
    }
}
