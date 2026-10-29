<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Engine\Hook\HookEngine;
use App\Engine\Observability\Profiler;
use App\Engine\System\Audit\SystemAudit;
use App\Engine\System\Command\Command;
use App\Engine\System\Command\CommandExecutor;
use App\Engine\System\Command\CommandPolicyException;
use App\Engine\System\Service\ServiceException;
use App\Engine\System\Service\ServiceManager;
use App\Tests\Support\TestCase;

/**
 * System operations in the profiler, through the audit stream they already emit.
 */
final class SystemObservabilityTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function app(array $config): Application
    {
        return $this->shippedApplication([
            'system' => ['execution' => ['max_concurrent' => null]],
            ...$config,
        ])->boot();
    }

    public function test_with_profiling_on_operations_are_counted_and_timed(): void
    {
        $container = $this->app(['observability' => ['profile' => true]])->container();
        $executor = $container->get(CommandExecutor::class);

        $executor->run(new Command(\PHP_BINARY, ['-n', '-r', 'usleep(50000);']));
        $executor->run(new Command(\PHP_BINARY, ['-n', '-r', 'exit(1);']));
        $executor->run(new Command(\PHP_BINARY, ['-n', '-r', 'sleep(5);'], timeout: 0.2));

        try {
            $container->get(ServiceManager::class)->stop('ssh');
        } catch (ServiceException) {
        }

        $summary = $container->get(Profiler::class)->summary(10);
        $system = [];

        foreach ($summary['slowest']['system'] ?? [] as $entry) {
            $system[$entry['name']] = $entry;
        }

        self::assertSame(1, $system['system.command.completed']['count'] ?? null);
        self::assertSame(1, $system['system.command.failed']['count'] ?? null);
        self::assertSame(1, $system['system.command.timeout']['count'] ?? null);
        self::assertSame(1, $system['system.service.refused']['count'] ?? null);
        self::assertGreaterThanOrEqual(50.0, $system['system.command.completed']['ms'], 'the command\'s own duration');
        self::assertSame(4, $summary['categories']['system']['count'], 'a start is not counted, its end is');
    }

    public function test_with_profiling_off_nothing_is_attached(): void
    {
        $container = $this->app(['observability' => ['profile' => false]])->container();

        // Only the audit log listens.
        self::assertCount(1, $container->get(HookEngine::class)->listeners(SystemAudit::HOOK));

        try {
            $container->get(CommandExecutor::class)->run(\App\Engine\System\Command\ShellCommand::bash('/srv/app/run.sh'));
        } catch (CommandPolicyException) {
        }

        self::assertSame([], $container->get(Profiler::class)->summary()['categories']);
    }
}
