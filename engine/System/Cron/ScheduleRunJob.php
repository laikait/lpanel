<?php

declare(strict_types=1);

namespace App\Engine\System\Cron;

use App\Engine\Support\Path;
use App\Engine\System\Command\Command;

/**
 * The one crontab line an application needs: `schedule:run`, every minute.
 *
 *     $cron->install(ScheduleRunJob::forApplication('/srv/app'));
 *
 * This is where operating-system cron and the framework's scheduler meet, and
 * it is the whole of the meeting. Cron is the trigger; everything a schedule
 * means -- which tasks exist, whether one is due, locking, running, output --
 * stays in the Scheduler, and a module that adds a schedule changes no crontab.
 * So there is no way to install a crontab line per task here, deliberately:
 * that would be a second scheduler, living in a file nobody reviews.
 *
 * It is the line docs/operations/running.md tells an operator to add by hand,
 * built as a CronJob so that installing it is repeatable and owned. The same
 * one-host rule applies: install it on exactly one machine.
 *
 * **The PHP binary is the one running now, but only from the console.** Under
 * PHP-FPM, PHP_BINARY names php-fpm, which cannot run a script; asked from
 * there without an explicit binary, this refuses rather than install a line
 * that fails every minute in silence.
 */
final class ScheduleRunJob
{
    public const ID = 'framework.schedule-run';

    public const SCHEDULE = '* * * * *';

    /**
     * @param string  $basePath the application root, where the laika console script is
     * @param ?string $php      absolute path to the PHP CLI; null for this one, from the console only
     * @param ?string $log      where output is appended; null discards it, as each task's output is already logged
     */
    public static function forApplication(string $basePath, ?string $php = null, ?string $log = null): CronJob
    {
        if (!Path::isAbsolute($basePath)) {
            throw CronException::unsupportedCommand(self::ID, 'the application path must be absolute');
        }

        if ($php === null) {
            if (\PHP_SAPI !== 'cli') {
                throw CronException::unsupportedCommand(
                    self::ID,
                    \sprintf('PHP_BINARY is not a PHP CLI under the %s SAPI; pass the path of the php binary', \PHP_SAPI),
                );
            }

            $php = \PHP_BINARY;
        }

        return new CronJob(
            self::ID,
            self::SCHEDULE,
            new Command($php, ['laika', 'schedule:run'], workingDirectory: $basePath),
            $log,
        );
    }
}
