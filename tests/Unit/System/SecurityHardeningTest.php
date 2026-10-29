<?php

declare(strict_types=1);

namespace App\Tests\Unit\System;

use App\Engine\System\Command\Command;
use App\Engine\System\Command\CommandExecutor;
use App\Engine\System\Cron\CronException;
use App\Engine\System\Cron\CronJob;
use App\Engine\System\Process\ProcessManager;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The abuse cases the rest of the suite does not already hold.
 *
 * Where each of the plan's security items is tested:
 *
 *   command injection        CommandTest (shell syntax refused), CommandExecutorTest (arguments byte for byte)
 *   shell injection          ShellExecutionTest (arguments as $1..), CronJobTest (a crontab line through sh)
 *   path traversal           SystemFilesystemTest, PermissionManagerTest, and cron logs below
 *   symlink escape           SystemFilesystemTest, CommandPolicyTest (links judged by target)
 *   unauthorized command     CommandPolicyTest, SystemSliceTest
 *   unauthorized service     ServicePolicyTest, ServiceManagerTest, SystemConsoleTest
 *   unauthorized filesystem  SystemFilesystemTest, SystemSliceTest
 *   unauthorized permissions PermissionManagerTest
 *   excessive output         CommandExecutorTest (truncation), and memory below
 *   timeout abuse            CommandExecutorTest (SIGTERM ignored), LimitFiltersTest, and a lingering child below
 *   environment leakage      CommandExecutorTest (default names), and runtime secrets below
 */
final class SecurityHardeningTest extends TestCase
{
    /**
     * Output is dropped as it is read, not collected and then cut: a process
     * that writes far more than the limit costs the parent the limit, not the
     * flood.
     */
    public function test_a_flood_of_output_does_not_become_memory(): void
    {
        $before = \memory_get_usage();

        $result = (new CommandExecutor())->run(new Command(
            \PHP_BINARY,
            ['-n', '-r', '$chunk = str_repeat("x", 65536); for ($i = 0; $i < 800; $i++) { echo $chunk; }'],
            maxOutput: 4096,
        ));

        self::assertTrue($result->truncated());
        self::assertSame(4096, \strlen($result->stdout()));
        self::assertLessThan(8 * 1024 * 1024, \memory_get_usage() - $before, 'about 50 MiB of output reached memory');
    }

    /**
     * A secret this PHP process holds at runtime -- set by a .env loader, a
     * vault client, or a test -- does not travel to a child that names no
     * environment.
     */
    public function test_a_secret_the_parent_holds_at_runtime_does_not_reach_the_child(): void
    {
        $name = 'LPHP_HARDENING_SECRET';
        \putenv($name . '=S3cret-from-the-parent');
        $_ENV[$name] = 'S3cret-from-the-parent';
        $_SERVER[$name] = 'S3cret-from-the-parent';

        try {
            $result = (new CommandExecutor())->run(new Command(\PHP_BINARY, ['-n', '-r', 'echo json_encode(getenv());']));
            self::assertStringNotContainsString('S3cret-from-the-parent', $result->stdout());

            $started = (new ProcessManager())->start(new Command(\PHP_BINARY, ['-n', '-r', 'echo json_encode(getenv());']))->wait();
            self::assertStringNotContainsString('S3cret-from-the-parent', $started->stdout());
        } finally {
            \putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    /**
     * A command that leaves a child of its own holding the output open. The
     * executor answers when the command ends, not when that child does --
     * otherwise any command could stretch its caller's wait past its timeout.
     */
    public function test_a_child_left_holding_the_output_does_not_hold_the_caller(): void
    {
        $result = (new CommandExecutor())->run(new Command(
            \PHP_BINARY,
            [
                '-n',
                '-r',
                'proc_open([PHP_BINARY, "-n", "-r", "sleep(4);"], [1 => STDOUT, 2 => STDERR], $pipes); echo "parent done";',
            ],
            timeout: 30.0,
        ));

        self::assertSame(0, $result->exitCode());
        self::assertStringContainsString('parent done', $result->stdout());
        self::assertLessThan(3.0, $result->duration(), 'the executor waited for the lingering child');
    }

    /** @return iterable<string, array{string}> */
    public static function logTraversals(): iterable
    {
        yield 'unix' => ['/srv/app/system/Logs/../../../etc/cron.d/evil'];
        yield 'windows' => ['C:\\app\\..\\Windows\\evil.log'];
        yield 'at the end' => ['/srv/app/..'];
    }

    #[DataProvider('logTraversals')]
    public function test_a_cron_log_path_cannot_climb_out_of_where_it_says(string $log): void
    {
        $this->expectException(CronException::class);
        $this->expectExceptionMessage('no ".." segment');

        new CronJob('app.schedule', '* * * * *', new Command('/usr/bin/php', ['laika', 'schedule:run']), $log);
    }

    public function test_a_log_name_that_merely_contains_dots_is_fine(): void
    {
        $job = new CronJob('app.schedule', '* * * * *', new Command('/usr/bin/php'), '/srv/app/system/Logs/cron..old.log');

        self::assertSame('/srv/app/system/Logs/cron..old.log', $job->log());
    }
}
