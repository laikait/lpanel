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
use App\Engine\Http\Request;
use App\Engine\Module\ModuleException;
use App\Engine\Module\ModuleManager;
use App\Tests\Support\TestCase;

/**
 * Module dependencies through the real bootstrap.
 *
 * The unit tests pin the resolver's rules; these pin that they are actually
 * applied to the application -- that a dependency changes what registers
 * first in a way a module can observe, that each refusal stops boot rather
 * than a request, and that the demo application's own declarations hold.
 *
 * Fixture trees live under tests/Fixtures/Modules/Dependencies, one per
 * scenario, and replace only the plugins root; the real shared and gateway
 * modules load alongside them, which is itself part of the claim.
 */
final class ModuleDependencySliceTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['fixture.order']);

        parent::tearDown();
    }

    /** @param array<string, mixed> $config */
    private function boot(string $scenario = '', array $config = []): Application
    {
        if ($scenario !== '') {
            $config['modules']['paths'] = [self::SHOWCASE . '/Shared', 'tests/Fixtures/Modules/Dependencies/' . $scenario . '/Plugins'];
        }

        return $this->application($config)->boot();
    }

    // ---- ordering you can observe --------------------------------------------

    /**
     * Alpha comes before Zulu by name and requires Zulu. Both listen to the same
     * hook at the same priority, where registration order decides who runs
     * first -- so this is the dependency visible from inside a module, not just
     * in a listing.
     */
    public function test_a_dependency_changes_who_runs_first(): void
    {
        $app = $this->boot('Ordered');

        $_SERVER['fixture.order'] = [];
        $app->container()->get(HookEngine::class)->do('fixture.ping');

        self::assertSame(['Zulu', 'Alpha'], $_SERVER['fixture.order']);
    }

    public function test_shared_still_registers_first(): void
    {
        $ids = $this->boot('Ordered')->container()->get(ModuleManager::class)->registry()->ids();

        self::assertSame('Shared', $ids[0]);
        self::assertSame(['Zulu', 'Alpha'], \array_slice($ids, 1, 2));
    }

    // ---- the four refusals, at boot --------------------------------------------

    public function test_a_missing_dependency_stops_boot(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('"Payment" requires "Billing ^1.0", which is not installed');

        $this->boot('Missing');
    }

    public function test_a_version_conflict_stops_boot(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('the installed "Billing" is 1.4.0');

        $this->boot('Conflict');
    }

    public function test_a_circle_stops_boot(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Invoices -> Ledger -> Invoices');

        $this->boot('Circular');
    }

    public function test_a_disabled_dependency_stops_boot(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('"Zulu", which is installed but disabled');

        $this->boot('Ordered', ['modules' => ['disabled' => ['Zulu']]]);
    }

    /** Disabling the module that depends is always fine. */
    public function test_disabling_the_dependent_side_boots(): void
    {
        $registry = $this->boot('Ordered', ['modules' => ['disabled' => ['Alpha']]])
            ->container()->get(ModuleManager::class)->registry();

        self::assertFalse($registry->isEnabled('Alpha'));
        self::assertTrue($registry->isEnabled('Zulu'));
    }

    // ---- the demo application ----------------------------------------------------

    public function test_the_demo_modules_declare_what_they_use(): void
    {
        $registry = $this->boot()->container()->get(ModuleManager::class)->registry();

        $plugin = $registry->context('Example');
        $gateway = $registry->context('ExampleGateway');

        self::assertNotNull($plugin);
        self::assertNotNull($gateway);
        self::assertSame('Shared ^0.1', $plugin->declaredDependencies()[0]->describe());
        self::assertSame('Example ^0.1 (optional)', $gateway->declaredDependencies()[0]->describe());
    }

    /**
     * The gateway's dependency on the plugin is optional, and this is what
     * that buys: switch the plugin off and the application still starts, still
     * serves, and the gateway's listener simply has nothing to hear.
     */
    public function test_switching_off_an_optional_partner_leaves_a_working_application(): void
    {
        $app = $this->boot('', ['modules' => ['disabled' => ['Example']]]);

        $registry = $app->container()->get(ModuleManager::class)->registry();

        self::assertTrue($registry->isEnabled('ExampleGateway'));
        self::assertSame(404, $app->handle(Request::create('GET', '/customers.json'))->status());
        self::assertSame(401, $app->handle(Request::create('GET', '/me'))->status(), 'shared still serves');
    }

    // ---- the console ---------------------------------------------------------------

    public function test_module_list_shows_requirements_and_disabled_modules(): void
    {
        [$status, $output] = $this->console(
            $this->boot('Ordered', ['modules' => ['disabled' => ['Alpha']]]),
            'module:list',
        );

        self::assertSame(ConsoleKernel::SUCCESS, $status);
        self::assertStringContainsString('REQUIRES', $output);
        self::assertStringContainsString('Disabled (installed, switched off in modules.disabled): Alpha', $output);
    }

    public function test_module_list_marks_an_absent_optional_partner(): void
    {
        [, $output] = $this->console(
            $this->boot('', ['modules' => ['disabled' => ['Example']]]),
            'module:list',
        );

        self::assertStringContainsString('Example? ^0.1 (absent)', $output);
    }

    /** @return array{int, string} */
    private function console(Application $app, string ...$arguments): array
    {
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        $status = (new ConsoleKernel(
            $app->container()->get(CommandRegistry::class),
            $app->container()->get(CommandDispatcher::class),
            $app->container()->get(HookEngine::class),
            $app->container()->get(ErrorHandler::class),
            new Output($stream),
        ))->handle(ExecutionContext::cli(\array_values(['laika', ...$arguments])));

        \rewind($stream);
        $output = (string) \stream_get_contents($stream);
        \fclose($stream);

        return [$status, $output];
    }
}
