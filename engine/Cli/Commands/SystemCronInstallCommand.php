<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\System\Cron\CronChange;
use App\Engine\System\Cron\CronManager;
use App\Engine\System\Cron\ScheduleRunJob;

/**
 * Install the one crontab line this application needs: schedule:run, every minute.
 *
 * Safe to run on every deployment -- an unchanged line is left untouched --
 * and on exactly one host, for the reason docs/operations/running.md gives.
 * There is no way to install an arbitrary line from here: what runs, and when,
 * is declared by modules and decided by the scheduler.
 */
final class SystemCronInstallCommand
{
    public function __construct(
        private readonly CronManager $cron,
        private readonly Application $application,
    ) {}

    public function __invoke(Output $output, ?string $php = null, ?string $log = null): int
    {
        $job = ScheduleRunJob::forApplication($this->application->basePath(), $php, $log);
        $change = $this->cron->install($job);

        match ($change) {
            CronChange::Created => $output->success('Installed the schedule:run line.'),
            CronChange::Updated => $output->success('Updated the schedule:run line.'),
            CronChange::Unchanged => $output->line('The schedule:run line is already installed.'),
        };

        $output->line($job->line());

        return 0;
    }
}
