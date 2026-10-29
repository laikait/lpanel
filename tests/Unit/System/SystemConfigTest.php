<?php

declare(strict_types=1);

namespace App\Tests\Unit\System;

use App\Engine\Bootstrap\Bootstrap;
use App\Engine\Config\Config;
use App\Engine\Config\ConfigurationException;
use App\Engine\System\Audit\AuditRecord;
use App\Engine\System\Audit\SystemAudit;
use App\Engine\System\Command\Command;
use App\Engine\System\Command\CommandExecutor;
use App\Engine\System\Command\CommandPolicyException;
use App\Engine\System\Command\ShellCommand;
use App\Engine\System\Cron\CronManager;
use App\Engine\System\Filesystem\SystemFilesystem;
use App\Engine\System\Process\ProcessManager;
use App\Engine\System\Security\SystemAuthorizer;
use App\Engine\System\Service\ServiceAction;
use App\Engine\System\Service\ServiceManager;
use App\Engine\System\SystemConfig;
use App\Engine\System\SystemDisabledException;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SystemConfigTest extends TestCase
{
    /** @param array<string, mixed> $system */
    private function config(array $system = []): SystemConfig
    {
        return SystemConfig::fromConfig(new Config(Bootstrap::defaults(['system' => $system])), '/srv/shop');
    }

    public function test_the_defaults_are_the_cautious_ones(): void
    {
        $system = $this->config();

        self::assertTrue($system->enabled);
        self::assertSame(CommandExecutor::DEFAULT_TIMEOUT, $system->timeout);
        self::assertSame(CommandExecutor::DEFAULT_MAX_OUTPUT, $system->maxOutput);
        self::assertFalse($system->shell, 'shell execution is off');
        self::assertNull($system->commandPolicy, 'no allowlist unless one is configured');
        self::assertSame([], $system->servicePolicy->allowed());
        self::assertSame([], $system->filesystemPolicy->roots());
        self::assertSame([], $system->owners);
        self::assertTrue($system->cron);
        self::assertSame(16, $system->concurrency?->slots());
    }

    public function test_the_concurrency_limit_can_be_changed_or_removed(): void
    {
        self::assertSame(4, $this->config(['execution' => ['max_concurrent' => 4]])->concurrency?->slots());
        self::assertNull($this->config(['execution' => ['max_concurrent' => null]])->concurrency);
    }

    public function test_configured_values_are_read(): void
    {
        $system = $this->config([
            'execution' => ['default_timeout' => 120, 'max_output' => 4096],
            'shell' => ['enabled' => true, 'binary' => '/usr/bin/bash'],
            'services' => ['nginx' => ['reload', 'restart']],
            'filesystem' => ['read' => ['/var/log/nginx'], 'write' => ['/srv/backups']],
            'permissions' => ['owners' => ['www-data'], 'groups' => ['www-data']],
            'cron' => ['owner' => 'shop'],
        ]);

        self::assertSame(120.0, $system->timeout);
        self::assertSame(4096, $system->maxOutput);
        self::assertTrue($system->shell);
        self::assertSame('/usr/bin/bash', $system->shellBinary);
        self::assertTrue($system->servicePolicy->permits('nginx', ServiceAction::Reload));
        self::assertFalse($system->servicePolicy->permits('nginx', ServiceAction::Stop));
        self::assertSame(['/var/log/nginx' => false, '/srv/backups' => true], $system->filesystemPolicy->roots());
        self::assertSame(['www-data'], $system->owners);
        self::assertSame('shop', $system->cronOwner);
    }

    /**
     * system.audit fires either way; the setting only decides whether the audit
     * log listens.
     */
    public function test_switching_the_audit_log_off_keeps_the_hook(): void
    {
        $container = $this->shippedApplication(['system' => ['audit' => ['enabled' => false]]])->container();
        $hooks = $container->get(\App\Engine\Hook\HookEngine::class);
        $seen = [];
        $hooks->add(SystemAudit::HOOK, static function (AuditRecord $record) use (&$seen): void {
            $seen[] = $record->event;
        });

        $writer = new \App\Tests\Fixtures\Logging\CollectingWriter();
        $container->get(\App\Engine\Logging\LogManager::class)->add($writer);

        try {
            $container->get(CommandExecutor::class)->run(ShellCommand::bash('/srv/app/run.sh'));
        } catch (CommandPolicyException) {
        }

        self::assertSame(['system.command.refused'], $seen);
        self::assertSame([], \array_filter($writer->records, static fn($r) => $r->channel === 'audit'));
    }

    public function test_an_empty_allowlist_is_an_allowlist(): void
    {
        $system = $this->config(['commands' => ['allowed' => []]]);

        self::assertNotNull($system->commandPolicy);

        $this->expectException(CommandPolicyException::class);

        (new CommandExecutor(policy: $system->commandPolicy))->run(new Command(\PHP_BINARY, ['-v']));
    }

    public function test_the_cron_owner_is_derived_from_the_application_directory(): void
    {
        $owner = SystemConfig::ownerFor('/srv/My Shop!');

        self::assertMatchesRegularExpression('/^my-shop-[0-9a-f]{8}$/', $owner);
        self::assertSame($owner, SystemConfig::ownerFor('/srv/My Shop!'), 'stable');
        self::assertNotSame($owner, SystemConfig::ownerFor('/var/www/My Shop!'), 'different checkouts differ');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function unusable(): iterable
    {
        yield 'zero timeout' => [['execution' => ['default_timeout' => 0]], 'system.execution'];
        yield 'no concurrency at all' => [['execution' => ['max_concurrent' => 0]], 'system.execution.max_concurrent'];
        yield 'timeout as text' => [['execution' => ['default_timeout' => '30']], 'system.execution.default_timeout'];
        yield 'a shell that is not bash' => [['shell' => ['binary' => '/bin/sh']], 'system.shell.binary'];
        yield 'a relative allowed executable' => [['commands' => ['allowed' => ['rsync']]], 'system.commands.allowed'];
        yield 'an action that does not exist' => [['services' => ['nginx' => ['purge']]], 'system.services.nginx'];
        yield 'a target as a service' => [['services' => ['poweroff.target' => ['start']]], 'system.services.poweroff.target'];
        yield 'the filesystem root' => [['filesystem' => ['write' => ['/']]], 'system.filesystem.write'];
        yield 'an owner that is not a string' => [['permissions' => ['owners' => [0]]], 'system.permissions.owners'];
        yield 'shell switch as text' => [['shell' => ['enabled' => 'yes']], 'system.shell.enabled'];
    }

    /** @param array<string, mixed> $system */
    #[DataProvider('unusable')]
    public function test_an_unusable_value_is_refused_with_its_key(array $system, string $key): void
    {
        try {
            $this->config($system);
            self::fail('an unusable configuration was accepted');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('"' . $key, $e->getMessage());
        }
    }

    // ---- the container ----------------------------------------------------------------------

    public function test_the_container_builds_every_manager_from_the_configuration(): void
    {
        $container = $this->shippedApplication([
            'system' => ['execution' => ['default_timeout' => 5], 'services' => ['nginx' => ['reload']]],
        ])->container();

        foreach ([CommandExecutor::class, ProcessManager::class, ServiceManager::class, CronManager::class, SystemFilesystem::class, SystemAuthorizer::class] as $class) {
            self::assertInstanceOf($class, $container->get($class));
        }

        self::assertSame(5.0, $container->get(SystemConfig::class)->timeout);
        self::assertSame($container->get(CommandExecutor::class), $container->get(CommandExecutor::class));
    }

    public function test_the_injected_executor_carries_the_shell_switch_and_the_audit(): void
    {
        $container = $this->shippedApplication()->container();
        $records = [];
        $container->get(\App\Engine\Hook\HookEngine::class)->add(SystemAudit::HOOK, static function (AuditRecord $record) use (&$records): void {
            $records[] = $record->event;
        });

        try {
            $container->get(CommandExecutor::class)->run(ShellCommand::bash('/srv/app/run.sh'));
            self::fail('shell execution ran with the default configuration');
        } catch (CommandPolicyException $e) {
            self::assertStringContainsString('Shell execution is switched off', $e->getMessage());
        }

        self::assertSame(['system.command.refused'], $records);
    }

    public function test_switched_off_means_no_manager_can_be_made(): void
    {
        $container = $this->shippedApplication(['system' => ['enabled' => false]])->container();

        $this->expectException(SystemDisabledException::class);

        $container->get(CommandExecutor::class);
    }

    public function test_cron_can_be_switched_off_on_its_own(): void
    {
        $container = $this->shippedApplication(['system' => ['cron' => ['enabled' => false]]])->container();

        self::assertInstanceOf(CommandExecutor::class, $container->get(CommandExecutor::class));

        $this->expectException(SystemDisabledException::class);
        $this->expectExceptionMessage('Cron management is switched off');

        $container->get(CronManager::class);
    }

    /** Nothing in system.* is read, nor anything built, until a module asks. */
    public function test_booting_builds_nothing(): void
    {
        $container = $this->shippedApplication(['system' => ['shell' => ['binary' => 'zsh']]])->container();

        self::assertFalse($container->resolved(SystemConfig::class));
        self::assertFalse($container->resolved(CommandExecutor::class));
    }
}
