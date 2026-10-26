<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Container\Container;
use App\Engine\Core\Application;
use App\Engine\System\Cron\CronManager;
use App\Engine\System\Cron\ScheduleRunJob;
use App\Engine\System\SystemConfig;
use App\Engine\System\Systemd\SystemdManager;
use App\Engine\System\Systemd\SystemdUnits;

/**
 * Install the worker and scheduler units, reload systemd, and start them.
 *
 * Root only. Safe on every deployment: unchanged files are left alone and an
 * enabled unit stays enabled. It refuses to start the timer while this
 * application's schedule:run cron line is installed -- both would run every
 * task -- unless told to take the cron line out first.
 */
final class SystemSystemdInstallCommand
{
    public function __construct(
        private readonly Application $application,
        private readonly SystemConfig $system,
        private readonly SystemdManager $systemd,
        private readonly Container $container,
    ) {}

    public function __invoke(
        Output $output,
        ?string $user = null,
        ?string $group = null,
        ?string $php = null,
        ?string $queue = null,
        bool $replaceCron = false,
        bool $noScheduler = false,
    ): int {
        $units = SystemdUnits::forDeployment($this->application->basePath(), $this->system->cronOwner, $user, $group, $php);
        $cron = $this->cronManager();

        if (!$noScheduler && $cron?->job(ScheduleRunJob::ID) !== null) {
            if (!$replaceCron) {
                $output->error('The schedule:run cron line is installed, so every task would run twice.');
                $output->line('Pass --replace-cron to remove it and use the timer, or --no-scheduler to install the workers only.');

                return 1;
            }

            $cron->remove(ScheduleRunJob::ID);
            $output->line('Removed the schedule:run cron line.');
        }

        $result = $this->systemd->install($units, SystemSystemdGenerateCommand::queues($queue), !$noScheduler);

        foreach ($result['written'] as $name) {
            $output->line('Wrote ' . $this->systemd->directory() . '/' . $name);
        }

        foreach ($result['unchanged'] as $name) {
            $output->line('Unchanged ' . $name);
        }

        $output->success('Enabled and started: ' . \implode(', ', $result['enabled']));
        $output->line('Check them with: systemctl list-units \'' . $units->prefix() . '-*\'');

        return 0;
    }

    /** Null when cron management is switched off: then there is no line to clash with. */
    private function cronManager(): ?CronManager
    {
        return $this->system->cron ? $this->container->get(CronManager::class) : null;
    }
}
