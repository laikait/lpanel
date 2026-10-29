<?php

declare(strict_types=1);

namespace App\Tests\Unit\System\Cron;

use App\Engine\System\Command\CommandExecutor;
use App\Engine\System\Command\ShellCommand;
use App\Engine\System\Cron\CronChange;
use App\Engine\System\Cron\CronException;
use App\Engine\System\Cron\CronManager;
use App\Engine\System\Cron\ScheduleRunJob;
use App\Tests\Fixtures\System\MemoryCronTable;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;

final class ScheduleRunJobTest extends TestCase
{
    public function test_it_is_the_documented_line_built_as_a_job(): void
    {
        $job = ScheduleRunJob::forApplication('/srv/app', '/usr/bin/php8.3');

        self::assertSame('framework.schedule-run', $job->id());
        self::assertSame(
            "* * * * * cd '/srv/app' && '/usr/bin/php8.3' 'laika' 'schedule:run' > /dev/null 2>&1",
            $job->line(),
        );
    }

    public function test_output_can_be_appended_to_a_log(): void
    {
        $job = ScheduleRunJob::forApplication('/srv/app', '/usr/bin/php', '/srv/app/system/Logs/cron.log');

        self::assertStringEndsWith(">> '/srv/app/system/Logs/cron.log' 2>&1", $job->line());
    }

    public function test_from_the_console_the_php_binary_is_this_one(): void
    {
        self::assertSame(\PHP_BINARY, ScheduleRunJob::forApplication('/srv/app')->command()->executable());
    }

    public function test_a_relative_application_path_is_refused(): void
    {
        $this->expectException(CronException::class);
        $this->expectExceptionMessage('the application path must be absolute');

        ScheduleRunJob::forApplication('srv/app', '/usr/bin/php');
    }

    /** Every deployment can install it; only the first one writes. */
    public function test_installing_it_on_every_deployment_changes_the_crontab_once(): void
    {
        $table = new MemoryCronTable("MAILTO=\"\"\n");
        $cron = new CronManager($table, 'shop');

        self::assertSame(CronChange::Created, $cron->install(ScheduleRunJob::forApplication('/srv/app', '/usr/bin/php')));
        self::assertSame(CronChange::Unchanged, $cron->install(ScheduleRunJob::forApplication('/srv/app', '/usr/bin/php')));
        self::assertSame(1, $table->writes);
        self::assertSame([ScheduleRunJob::ID], \array_keys($cron->jobs()));
    }

    /**
     * The line, run as cron would run it, against this repository: it reaches
     * the real console and the real scheduler, which exits cleanly with
     * nothing due.
     */
    #[RequiresOperatingSystemFamily('Linux')]
    public function test_the_line_runs_the_real_scheduler(): void
    {
        $base = \dirname(__DIR__, 4);
        $job = ScheduleRunJob::forApplication($base, \PHP_BINARY, \sys_get_temp_dir() . '/lphp-schedule-run-' . \bin2hex(\random_bytes(4)) . '.log');
        $log = (string) $job->log();
        $script = \tempnam(\sys_get_temp_dir(), 'cronline');
        self::assertIsString($script);
        \file_put_contents($script, \str_replace('\\%', '%', \substr($job->line(), \strlen(ScheduleRunJob::SCHEDULE) + 1)) . "\n");

        try {
            $result = (new CommandExecutor(shell: true))->run(ShellCommand::bash($script, timeout: 30.0));

            self::assertSame(0, $result->exitCode(), (string) @\file_get_contents($log));
            self::assertFileExists($log);
        } finally {
            @\unlink($script);
            @\unlink($log);
        }
    }
}
