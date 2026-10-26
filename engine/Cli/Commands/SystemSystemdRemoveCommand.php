<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\System\SystemConfig;
use App\Engine\System\Systemd\SystemdManager;
use App\Engine\System\Systemd\SystemdUnits;

/**
 * Stop and disable this application's worker and scheduler units, and delete them.
 *
 * Only this application's: the units are found by the prefix it installed them
 * under, so another application's units on the same machine are left alone.
 */
final class SystemSystemdRemoveCommand
{
    public function __construct(
        private readonly Application $application,
        private readonly SystemConfig $system,
        private readonly SystemdManager $systemd,
    ) {}

    public function __invoke(Output $output): int
    {
        // The account and binary do not matter for finding files by name.
        $units = SystemdUnits::forApplication($this->application->basePath(), $this->system->cronOwner, 'nobody', php: '/usr/bin/php');
        $result = $this->systemd->remove($units);

        if ($result['disabled'] === [] && $result['deleted'] === []) {
            $output->line('This application has no systemd units installed.');

            return 0;
        }

        if ($result['disabled'] !== []) {
            $output->line('Stopped and disabled: ' . \implode(', ', $result['disabled']));
        }

        foreach ($result['deleted'] as $name) {
            $output->line('Deleted ' . $this->systemd->directory() . '/' . $name);
        }

        $output->success('Removed. Install the schedule:run cron line again if the scheduler is still wanted.');

        return 0;
    }
}
