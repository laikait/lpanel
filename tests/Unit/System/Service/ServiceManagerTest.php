<?php

declare(strict_types=1);

namespace App\Tests\Unit\System\Service;

use App\Engine\System\Command\CommandExecutor;
use App\Engine\System\Service\ServiceAction;
use App\Engine\System\Service\ServiceException;
use App\Engine\System\Service\ServiceManager;
use App\Engine\System\Service\ServicePolicy;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;

/**
 * Against a stand-in systemctl that records what it was asked, never the real
 * one -- except for reading a status, which changes nothing and is checked
 * against real systemd where it runs.
 */
final class ServiceManagerTest extends TestCase
{
    private string $directory = '';

    protected function tearDown(): void
    {
        $this->cleanUp();

        parent::tearDown();
    }

    private function cleanUp(): void
    {
        if ($this->directory === '') {
            return;
        }

        foreach (\glob($this->directory . '/*') ?: [] as $file) {
            \unlink($file);
        }

        \rmdir($this->directory);
    }

    /** A systemctl that logs its arguments as JSON lines and exits with $exitCode. */
    private function fakeSystemctl(int $exitCode = 0, string $show = "LoadState=loaded\nActiveState=active\nSubState=running\nUnitFileState=enabled\n"): string
    {
        $this->directory = \sys_get_temp_dir() . '/lphp-systemctl-' . \bin2hex(\random_bytes(4));
        \mkdir($this->directory);
        $program = $this->directory . '/systemctl';

        \file_put_contents($program, '#!' . \PHP_BINARY . " -n\n<?php\n"
            . 'file_put_contents(' . \var_export($this->directory . '/calls', true) . ', json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND);' . "\n"
            . "if ({$exitCode} !== 0) { fwrite(STDERR, \"Failed: Access denied as requested by polkit for /org/freedesktop\\n\"); exit({$exitCode}); }\n"
            . 'if (in_array("show", $argv, true)) { echo ' . \var_export($show, true) . "; }\n");
        \chmod($program, 0o755);

        return $program;
    }

    /** @return list<list<string>> */
    private function calls(): array
    {
        $calls = [];

        foreach (\file($this->directory . '/calls', \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $call = \json_decode($line, true);
            self::assertIsArray($call);
            $calls[] = \array_values(\array_map('strval', $call));
        }

        return $calls;
    }

    // ---- refused before anything runs -----------------------------------------------

    /** On every platform: the policy answers before systemd is ever asked. */
    public function test_an_action_the_policy_does_not_allow_is_refused_without_running_anything(): void
    {
        $services = new ServiceManager(new CommandExecutor(), ServicePolicy::none()->allow('nginx', ServiceAction::Restart), '/nonexistent/systemctl');

        foreach ([
            'stop ssh.service' => static fn() => $services->stop('ssh'),
            'stop nginx.service' => static fn() => $services->stop('nginx'),
            'disable nginx.service' => static fn() => $services->disable('nginx.service'),
        ] as $what => $attempt) {
            try {
                $attempt();
                self::fail($what . ' was not refused');
            } catch (ServiceException $e) {
                self::assertStringContainsString(\sprintf('Nothing permits "%s"', $what), $e->getMessage());
            }
        }
    }

    public function test_a_name_that_is_not_a_service_is_refused_even_for_status(): void
    {
        $services = new ServiceManager(new CommandExecutor(), ServicePolicy::none(), '/nonexistent/systemctl');

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('The service name is invalid');

        $services->status('poweroff.target');
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function test_windows_is_refused(): void
    {
        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('It is Linux-only');

        (new ServiceManager(new CommandExecutor(), ServicePolicy::none()))->status('nginx');
    }

    // ---- what systemctl is asked --------------------------------------------------------

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_each_allowed_action_is_one_systemctl_call_with_the_unit_after_double_dash(): void
    {
        $policy = ServicePolicy::none();

        foreach (ServiceAction::cases() as $action) {
            $policy = $policy->allow('php8.3-fpm', $action);
        }

        $services = new ServiceManager(new CommandExecutor(), $policy, $this->fakeSystemctl());

        $services->start('php8.3-fpm');
        $services->stop('php8.3-fpm');
        $services->restart('php8.3-fpm.service');
        $services->reload('php8.3-fpm');
        $services->enable('php8.3-fpm');
        $services->disable('php8.3-fpm');

        $expected = [];

        foreach (['start', 'stop', 'restart', 'reload', 'enable', 'disable'] as $verb) {
            $expected[] = ['--no-pager', '--no-ask-password', $verb, '--', 'php8.3-fpm.service'];
        }

        self::assertSame($expected, $this->calls());
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_status_reads_the_four_states(): void
    {
        $services = new ServiceManager(new CommandExecutor(), ServicePolicy::none(), $this->fakeSystemctl());

        $status = $services->status('nginx');

        self::assertTrue($status->isActive());
        self::assertTrue($status->isEnabled());
        self::assertSame(
            [['--no-pager', '--no-ask-password', 'show', '--property=LoadState,ActiveState,SubState,UnitFileState', '--', 'nginx.service']],
            $this->calls(),
        );
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_a_refusal_from_systemd_names_the_exit_code_not_its_output(): void
    {
        $services = new ServiceManager(new CommandExecutor(), ServicePolicy::none()->allow('nginx', ServiceAction::Restart), $this->fakeSystemctl(exitCode: 4));

        try {
            $services->restart('nginx');
            self::fail('no exception');
        } catch (ServiceException $e) {
            self::assertStringContainsString('systemctl restart nginx.service exited with 4', $e->getMessage());
            self::assertStringContainsString('journalctl -u nginx.service', $e->getMessage());
            self::assertStringNotContainsString('polkit', $e->getMessage());
        }
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_status_without_systemd_is_unavailable(): void
    {
        $services = new ServiceManager(new CommandExecutor(), ServicePolicy::none(), $this->fakeSystemctl(exitCode: 1));

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('systemd could not be asked (systemctl exited with 1)');

        $services->status('nginx');
    }

    // ---- real systemd, read only ----------------------------------------------------------

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_real_systemd_reports_its_own_journal_as_running(): void
    {
        if (!\is_dir('/run/systemd/system')) {
            self::markTestSkipped('this machine is not running systemd');
        }

        $services = new ServiceManager(new CommandExecutor(), ServicePolicy::none());

        $journal = $services->status('systemd-journald');
        $missing = $services->status('lphp-no-such-service-' . \bin2hex(\random_bytes(3)));

        self::assertTrue($journal->exists());
        self::assertTrue($journal->isActive(), $journal->activeState);
        self::assertFalse($missing->exists());
    }
}
