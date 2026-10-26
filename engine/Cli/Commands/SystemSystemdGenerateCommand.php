<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Support\Path;
use App\Engine\System\SystemConfig;
use App\Engine\System\Systemd\SystemdException;
use App\Engine\System\Systemd\SystemdManager;
use App\Engine\System\Systemd\SystemdUnits;

/**
 * Print the systemd units for the queue worker and the scheduler, or write them to a directory.
 *
 * Changes nothing on this machine, so it needs no privilege and works where
 * systemd does not run -- a build step can generate the files and a
 * configuration-management tool can put them in place. system:systemd:install
 * does both steps as root.
 */
final class SystemSystemdGenerateCommand
{
    public function __construct(
        private readonly Application $application,
        private readonly SystemConfig $system,
    ) {}

    public function __invoke(
        Output $output,
        ?string $user = null,
        ?string $group = null,
        ?string $php = null,
        ?string $queue = null,
        ?string $write = null,
    ): int {
        $units = SystemdUnits::forDeployment($this->application->basePath(), $this->system->cronOwner, $user, $group, $php);
        $workers = \array_map($units->worker(...), self::queues($queue));

        if ($write !== null && $write !== '') {
            if (!\is_dir($write) && !@\mkdir($write, 0o755, true) && !\is_dir($write)) {
                throw SystemdException::unwritable($write);
            }

            foreach ($units->files() as $file) {
                $path = Path::join($write, $file->name);

                if (@\file_put_contents($path, $file->contents) === false) {
                    throw SystemdException::unwritable($path);
                }

                $output->line('Wrote ' . $path);
            }

            $source = \rtrim($write, '/') . '/' . $units->prefix() . '-*';
        } else {
            foreach ($units->files() as $file) {
                $output->heading('# ' . SystemdManager::DIRECTORY . '/' . $file->name);
                $output->write($file->contents);
                $output->line();
            }

            $source = null;
        }

        $output->line('To install them, as root:');
        $output->line();

        $output->line($source !== null
            ? '  cp ' . $source . ' ' . SystemdManager::DIRECTORY . '/'
            : '  save each file above under ' . SystemdManager::DIRECTORY . '/ (or run again with --write=DIR)');

        $output->line('  systemctl daemon-reload');
        $output->line('  systemctl enable --now ' . \implode(' ', [$units->schedulerTimer(), ...$workers]));
        $output->line();
        $output->line('or run php laika system:systemd:install, which does all of it.');
        $output->line('The timer replaces the schedule:run cron line: keep one of the two, on one machine.');

        return 0;
    }

    /** @return list<string> "default", or the comma-separated --queue list */
    public static function queues(?string $option): array
    {
        $queues = \array_filter(\array_map('trim', \explode(',', $option ?? '')), static fn(string $q): bool => $q !== '');

        return $queues === [] ? ['default'] : \array_values(\array_unique($queues));
    }
}
