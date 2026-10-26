<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Scheduler\Scheduler;

/**
 * Release a schedule's lock by hand.
 *
 * Locks expire on their own, so this is never strictly necessary -- it is the
 * command for not waiting out the remainder after a machine was rebooted
 * mid-run. That is the whole of its job, and the warnings below are most of
 * its value: a lock that looks stuck is usually a task that is still going, and
 * releasing it starts a second copy of exactly the thing the lock existed to
 * keep single.
 */
final class ScheduleUnlockCommand
{
    public function __construct(private readonly Scheduler $scheduler) {}

    public function __invoke(Output $output, ?string $id = null, bool $all = false): int
    {
        $lock = $this->scheduler->lock();
        $held = $lock->held();

        $output->pairs([
            'Locks' => $lock->describe(),
            'Held' => (string) \count($held),
        ]);
        $output->line();

        if ($held === []) {
            $output->success('Nothing is locked.');

            return 0;
        }

        if ($id === null && !$all) {
            foreach ($held as $key) {
                $until = $lock->heldUntil($key);

                $output->line(\sprintf(
                    '  %-32s until %s',
                    $key,
                    $until === null ? 'unknown' : \date('Y-m-d H:i:s', $until),
                ));
            }

            $output->line();
            $output->warning('Release one with --id=<schedule>, or all of them with --all.');
            $output->line('Check first that the process holding it has really gone: a released lock');
            $output->line('is a second copy of the task starting the next time the schedule comes up.');

            return 0;
        }

        $released = 0;

        foreach ($held as $key) {
            if (($id === null || $key === $id) && $lock->release($key)) {
                ++$released;
                $output->line('  released ' . $key);
            }
        }

        $output->line();

        if ($released === 0) {
            $output->warning($id === null ? 'Nothing was released.' : \sprintf('"%s" is not locked.', $id));

            return 0;
        }

        $output->success(\sprintf('%d lock(s) released.', $released));

        return 0;
    }
}
