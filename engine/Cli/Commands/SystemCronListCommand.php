<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\System\Cron\CronManager;

/**
 * The jobs this application owns in the crontab, and nothing else in it.
 *
 * A person's own crontab lines are not listed: they are not this
 * application's, and printing them would be reading somebody's file.
 */
final class SystemCronListCommand
{
    public function __construct(private readonly CronManager $cron) {}

    public function __invoke(Output $output): int
    {
        $jobs = $this->cron->jobs();

        if ($jobs === []) {
            $output->warning('This application has no jobs in the crontab.');
            $output->line('php laika system:cron:install adds the one that runs schedule:run.');

            return 0;
        }

        $rows = [];

        foreach ($jobs as $job) {
            $rows[] = [$job->id(), $job->schedule(), \basename($job->command()->executable()), $job->log() ?? 'discarded'];
        }

        $output->table(['Id', 'Schedule', 'Program', 'Output'], $rows);

        return 0;
    }
}
