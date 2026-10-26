<?php

declare(strict_types=1);

namespace App\Engine\System\Cron;

/**
 * Where a crontab's text is read from and written back to.
 *
 * Two implementations, and both are needed: UserCrontab, the real one, which
 * goes through the `crontab` program; and the in-memory one the tests use, so
 * that a test run never touches the crontab of whoever runs it. CronManager
 * does everything else on plain text.
 */
interface CronTable
{
    /** The whole crontab, or an empty string when there is none. */
    public function read(): string;

    /** Replace the whole crontab. */
    public function write(string $contents): void;
}
