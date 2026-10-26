<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\System\Cron\CronManager;
use App\Engine\System\Cron\ScheduleRunJob;

/**
 * Remove this application's crontab lines: one by id, or all of them.
 *
 * Only this application's block is touched, whichever is asked for.
 */
final class SystemCronRemoveCommand
{
    public function __construct(private readonly CronManager $cron) {}

    public function __invoke(Output $output, string $id = ScheduleRunJob::ID, bool $all = false): int
    {
        if ($all) {
            $output->success(\sprintf('Removed %d job(s) this application owned.', $this->cron->uninstall()));

            return 0;
        }

        if (!$this->cron->remove($id)) {
            $output->warning(\sprintf('This application has no job "%s" in the crontab.', $id));

            return 1;
        }

        $output->success(\sprintf('Removed %s.', $id));

        return 0;
    }
}
