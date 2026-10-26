<?php

declare(strict_types=1);

namespace App\Engine\System\Cron;

use App\Engine\System\Command\Command;
use App\Engine\System\Command\CommandExecutor;

/**
 * The crontab of the user this PHP runs as, through the `crontab` program.
 *
 * Read with `crontab -l` and written with `crontab -`, the same two commands a
 * person would use, run through the executor like any other command. Editing
 * the spool file directly would skip crontab's own syntax check and its
 * notification of the cron daemon, and needs root besides.
 *
 * "no crontab for <user>" is how crontab -l says the crontab is empty, and is
 * read as an empty string. Anything else that fails is an exception whose
 * message names only the exit code: crontab's stderr can name the user and the
 * spool path, and is not repeated.
 *
 * Linux only. On Windows every call refuses before running anything.
 */
final class UserCrontab implements CronTable
{
    public function __construct(
        private readonly CommandExecutor $executor,
        private readonly string $crontab = 'crontab',
    ) {}

    public function read(): string
    {
        $result = $this->executor->run(new Command($this->program(), ['-l']));

        if ($result->successful()) {
            return $result->stdout();
        }

        if ($result->exitCode() !== null && \preg_match('/no crontab for/i', $result->stderr()) === 1) {
            return '';
        }

        throw CronException::unreadable($result->exitCode());
    }

    public function write(string $contents): void
    {
        $result = $this->executor->run(new Command($this->program(), ['-'], stdin: $contents));

        if (!$result->successful()) {
            throw CronException::unwritable($result->exitCode());
        }
    }

    private function program(): string
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            throw CronException::unsupportedPlatform();
        }

        return $this->crontab;
    }
}
