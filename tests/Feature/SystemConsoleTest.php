<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Cli\CommandDispatcher;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\ConsoleKernel;
use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Engine\Error\ErrorHandler;
use App\Engine\Hook\HookEngine;
use App\Engine\System\Command\CommandExecutor;
use App\Engine\System\Cron\CronManager;
use App\Engine\System\Cron\ScheduleRunJob;
use App\Engine\System\Service\ServiceManager;
use App\Engine\System\Service\ServicePolicy;
use App\Engine\System\SystemConfig;
use App\Engine\System\Systemd\SystemdManager;
use App\Tests\Fixtures\System\MemoryCronTable;
use App\Tests\Support\TestCase;

/**
 * The system:* commands through the real console kernel.
 *
 * The cron commands run against an in-memory crontab bound in place of the
 * real one: a test run must never edit the crontab of whoever runs it.
 */
final class SystemConsoleTest extends TestCase
{
    private MemoryCronTable $crontab;

    private Application $app;

    /** Where the systemd commands write, in place of /etc/systemd/system. */
    private string $units = '';

    protected function setUp(): void
    {
        $this->crontab = new MemoryCronTable("MAILTO=\"\"\n");
        $this->app = $this->shippedApplication()->boot();
        $this->app->container()->instance(CronManager::class, new CronManager($this->crontab, 'console-test'));
    }

    protected function tearDown(): void
    {
        if ($this->units !== '') {
            foreach (\glob($this->units . '/*') ?: [] as $file) {
                \unlink($file);
            }

            \rmdir($this->units);
        }

        parent::tearDown();
    }

    /** The systemd commands against a temporary directory and a systemctl that only succeeds. */
    private function fakeSystemd(): void
    {
        $this->units = \sys_get_temp_dir() . '/lphp-console-systemd-' . \bin2hex(\random_bytes(4));
        \mkdir($this->units);
        \file_put_contents($this->units . '/systemctl', '#!' . \PHP_BINARY . " -n\n<?php exit(0);\n");
        \chmod($this->units . '/systemctl', 0o755);

        $this->app->container()->instance(
            SystemdManager::class,
            new SystemdManager(new ServiceManager(new CommandExecutor(), ServicePolicy::none(), $this->units . '/systemctl'), $this->units, runtimeDirectory: null, privileged: true),
        );
    }

    /** @return array{int, string} */
    private function console(string ...$arguments): array
    {
        $container = $this->app->container();
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        $kernel = new ConsoleKernel(
            $container->get(CommandRegistry::class),
            $container->get(CommandDispatcher::class),
            $container->get(HookEngine::class),
            $container->get(ErrorHandler::class),
            new Output($stream),
        );

        $status = $kernel->handle(ExecutionContext::cli(\array_values(['laika', ...$arguments])));

        \rewind($stream);
        $output = (string) \stream_get_contents($stream);
        \fclose($stream);

        return [$status, $output];
    }

    public function test_system_info_describes_this_machine(): void
    {
        [$status, $output] = $this->console('system:info');

        self::assertSame(0, $status);
        self::assertStringContainsString(\PHP_VERSION, $output);
        self::assertStringContainsString(\php_uname('m'), $output);
    }

    /** The console asks for no identity, and still cannot get past the application's policy. */
    public function test_restarting_a_service_the_configuration_does_not_allow_is_refused(): void
    {
        [$status, $output] = $this->console('system:service:restart', 'ssh');

        self::assertSame(1, $status);
        self::assertStringContainsString('Nothing permits "restart ssh.service"', $output);
    }

    public function test_a_name_that_is_not_a_service_is_refused(): void
    {
        [$status, $output] = $this->console('system:service:status', 'poweroff.target');

        self::assertSame(1, $status);
        self::assertStringContainsString('The service name is invalid', $output);
    }

    public function test_the_schedule_run_line_is_installed_listed_and_removed(): void
    {
        [$status, $output] = $this->console('system:cron:list');
        self::assertSame(0, $status);
        self::assertStringContainsString('no jobs in the crontab', $output);

        [$status, $output] = $this->console('system:cron:install');
        self::assertSame(0, $status);
        self::assertStringContainsString('Installed the schedule:run line', $output);
        self::assertStringContainsString("'laika' 'schedule:run'", $output);

        [, $output] = $this->console('system:cron:install');
        self::assertStringContainsString('already installed', $output);

        [$status, $output] = $this->console('system:cron:list');
        self::assertSame(0, $status);
        self::assertStringContainsString(ScheduleRunJob::ID, $output);

        [$status, $output] = $this->console('system:cron:remove');
        self::assertSame(0, $status);
        self::assertStringContainsString('Removed ' . ScheduleRunJob::ID, $output);

        [$status] = $this->console('system:cron:remove');
        self::assertSame(1, $status, 'removing what is not there says so');

        self::assertSame("MAILTO=\"\"\n", $this->crontab->contents, 'the rest of the crontab was touched');
    }

    public function test_remove_all_takes_only_this_applications_jobs(): void
    {
        $this->console('system:cron:install');

        [$status, $output] = $this->console('system:cron:remove', '--all');

        self::assertSame(0, $status);
        self::assertStringContainsString('Removed 1 job(s)', $output);
        self::assertSame("MAILTO=\"\"\n", $this->crontab->contents);
    }

    /** The unit prefix: the configured cron owner, here derived from the directory. */
    private function prefix(): string
    {
        return $this->app->container()->get(SystemConfig::class)->cronOwner;
    }

    public function test_systemd_units_are_printed_with_the_steps_to_install_them(): void
    {
        [$status, $output] = $this->console('system:systemd:generate', '--user=www-data', '--queue=default,billing');

        self::assertSame(0, $status);
        self::assertStringContainsString($this->prefix() . '-worker@.service', $output);
        self::assertStringContainsString('ExecStart=' . \PHP_BINARY, $output);
        self::assertStringContainsString('queue:work --queue=%i', $output);
        self::assertStringContainsString('OnCalendar=*-*-* *:*:00', $output);
        self::assertStringContainsString(
            'systemctl enable --now ' . $this->prefix() . '-scheduler.timer ' . $this->prefix() . '-worker@default.service ' . $this->prefix() . '-worker@billing.service',
            $output,
        );
    }

    public function test_systemd_install_will_not_run_the_scheduler_twice(): void
    {
        $this->fakeSystemd();
        $this->console('system:cron:install');

        [$status, $output] = $this->console('system:systemd:install', '--user=www-data');

        self::assertSame(1, $status);
        self::assertStringContainsString('every task would run twice', $output);
        self::assertFileDoesNotExist($this->units . '/' . $this->prefix() . '-scheduler.timer');

        [$status, $output] = $this->console('system:systemd:install', '--user=www-data', '--replace-cron');

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Removed the schedule:run cron line', $output);
        self::assertStringContainsString($this->prefix() . '-scheduler.timer, ' . $this->prefix() . '-worker@default.service', $output);
        self::assertFileExists($this->units . '/' . $this->prefix() . '-scheduler.timer');
        self::assertSame("MAILTO=\"\"\n", $this->crontab->contents);
    }

    public function test_systemd_install_can_leave_the_scheduler_to_cron(): void
    {
        $this->fakeSystemd();
        $this->console('system:cron:install');

        [$status, $output] = $this->console('system:systemd:install', '--user=www-data', '--no-scheduler');

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Enabled and started: ' . $this->prefix() . '-worker@default.service', $output);
        self::assertNotNull((new CronManager($this->crontab, 'console-test'))->job(ScheduleRunJob::ID), 'the cron line stays');
    }

    public function test_systemd_units_are_removed(): void
    {
        $this->fakeSystemd();
        $this->console('system:systemd:install', '--user=www-data');

        [$status, $output] = $this->console('system:systemd:remove');

        self::assertSame(0, $status);
        self::assertStringContainsString('Deleted', $output);
        self::assertFileDoesNotExist($this->units . '/' . $this->prefix() . '-worker@.service');

        [, $output] = $this->console('system:systemd:remove');
        self::assertStringContainsString('no systemd units installed', $output);
    }

    /** The plan's warning, pinned: no command runs whatever it is given. */
    public function test_there_is_no_arbitrary_execution_command(): void
    {
        foreach (['system:exec', 'system:shell', 'system:run'] as $name) {
            [$status, $output] = $this->console($name, 'id');

            self::assertNotSame(0, $status, $name . ' exists');
            self::assertStringContainsString('help', $output);
        }
    }
}
