<?php

declare(strict_types=1);

namespace App\Engine\System\Cron;

use App\Engine\Scheduler\CronExpression;
use App\Engine\Scheduler\SchedulerException;
use App\Engine\Security\Secret;
use App\Engine\Support\Path;
use App\Engine\System\Command\Command;

/**
 * One line in the operating system's crontab, described rather than written.
 *
 *     new CronJob(
 *         'app.schedule',
 *         '* * * * *',
 *         new Command('/usr/bin/php', ['laika', 'schedule:run'], workingDirectory: '/srv/app'),
 *         log: '/srv/app/system/Logs/cron.log',
 *     );
 *
 * **The line is built, never taken.** cron hands every command line to /bin/sh,
 * so a crontab is shell source whether anyone meant it to be or not. The job
 * holds a Command -- executable and arguments apart -- and line() quotes each
 * part for sh and escapes the `%` that cron itself would otherwise turn into a
 * line break. There is no constructor taking a line.
 *
 * **Only what cron can honour.** A Command's environment, input, timeout and
 * output limit have no crontab equivalent that means the same thing, so a
 * command that sets one is refused rather than silently run without it. The
 * executable must be an absolute path: cron's PATH is /usr/bin:/bin, not the
 * one a developer tested with. A Secret argument is refused, because a crontab
 * is a plain text file that `crontab -l` prints.
 *
 * **The schedule is the scheduler's grammar.** It is validated by
 * Scheduler\CronExpression, so an expression means the same thing in a crontab
 * and in a module's schedule.
 *
 * This is the operating-system trigger. What the application runs, and when, is
 * the Scheduler's; the usual job is the one line that runs `schedule:run`.
 */
final class CronJob
{
    private const ID = '/^[a-z0-9][a-z0-9._:-]{0,99}$/D';

    private readonly string $schedule;

    public function __construct(
        private readonly string $id,
        string $schedule,
        private readonly Command $command,
        private readonly ?string $log = null,
    ) {
        if (\preg_match(self::ID, $id) !== 1) {
            throw CronException::invalidId($id);
        }

        // One space between fields: a line break inside a schedule would be a
        // second crontab line.
        $this->schedule = (string) \preg_replace('/\s+/', ' ', \trim($schedule));

        try {
            new CronExpression($this->schedule);
        } catch (SchedulerException $e) {
            throw CronException::invalidSchedule($id, $e);
        }

        self::checkCommand($id, $command);

        if ($log !== null && (!Path::isAbsolute($log) || \preg_match('/[\r\n\0]|(^|[\/\\\\])\.\.([\/\\\\]|$)/', $log) === 1)) {
            throw CronException::invalidLog($id);
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function schedule(): string
    {
        return $this->schedule;
    }

    public function command(): Command
    {
        return $this->command;
    }

    /** Where stdout and stderr are appended; null sends them to /dev/null. */
    public function log(): ?string
    {
        return $this->log;
    }

    /** The crontab line: schedule, then a command line safe for sh and for cron. */
    public function line(): string
    {
        $parts = [];
        $directory = $this->command->workingDirectory();

        if ($directory !== null) {
            $parts[] = 'cd ' . self::quote($directory) . ' &&';
        }

        $parts[] = self::quote($this->command->executable());

        foreach ($this->command->arguments() as $argument) {
            // checkCommand() refused every Secret.
            $parts[] = self::quote(\is_string($argument) ? $argument : '');
        }

        $parts[] = $this->log === null ? '> /dev/null 2>&1' : '>> ' . self::quote($this->log) . ' 2>&1';

        return $this->schedule . ' ' . \implode(' ', $parts);
    }

    /**
     * Single quotes for sh, where nothing inside is special except the quote
     * itself; then cron's own escape for %, which it reads before sh does.
     */
    public static function quote(string $value): string
    {
        return \str_replace('%', '\\%', "'" . \str_replace("'", "'\\''", $value) . "'");
    }

    private static function checkCommand(string $id, Command $command): void
    {
        $refusals = [
            'environment' => $command->environment() !== null,
            'stdin' => $command->stdin() !== null,
            'timeout' => $command->timeout() !== null,
            'maxOutput' => $command->maxOutput() !== null,
        ];

        foreach ($refusals as $option => $set) {
            if ($set) {
                throw CronException::unsupportedCommand($id, \sprintf('cron has no equivalent of the command\'s %s', $option));
            }
        }

        if (!Path::isAbsolute($command->executable())) {
            throw CronException::unsupportedCommand($id, 'the executable must be an absolute path, because cron runs with its own short PATH');
        }

        $values = [$command->executable(), $command->workingDirectory() ?? '', ...$command->arguments()];

        foreach ($values as $value) {
            if ($value instanceof Secret) {
                throw CronException::unsupportedCommand($id, 'a Secret argument would be written into a plain-text crontab');
            }

            if (\preg_match('/[\r\n]/', $value) === 1) {
                throw CronException::unsupportedCommand($id, 'a crontab line cannot contain a line break');
            }

            if (!\mb_check_encoding($value, 'UTF-8')) {
                throw CronException::unsupportedCommand($id, 'a crontab is text, and an argument is not valid UTF-8');
            }
        }
    }
}
