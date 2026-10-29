<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\MCP\Capability;
use App\Engine\MCP\CapabilityKind;
use App\Engine\MCP\McpRegistry;
use App\Engine\MCP\RegistryException;
use App\Engine\Module\ModuleContext;
use App\Tests\Support\TestCase;

/**
 * MCP capabilities declared by modules, through a real boot.
 */
final class McpModuleSliceTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function registry(string $plugins, array $config = []): McpRegistry
    {
        return $this->shippedApplication([
            'modules' => ['paths' => ['modules/Shared', $plugins], ...($config['modules'] ?? [])],
        ])->boot()->container()->get(McpRegistry::class);
    }

    /** @return list<string> "module: name" */
    private static function owned(McpRegistry $registry): array
    {
        return \array_map(static fn(Capability $c): string => $c->module . ': ' . $c->name, $registry->everything());
    }

    public function test_an_application_whose_modules_declare_nothing_offers_nothing(): void
    {
        self::assertSame([], $this->shippedApplication()->boot()->container()->get(McpRegistry::class)->everything());
    }

    public function test_every_module_registers_its_own_in_dependency_order(): void
    {
        $registry = $this->registry('tests/Fixtures/Modules/Mcp/Plugins', ['modules' => ['disabled' => ['Muted']]]);

        // Zulu before Alpha, although Alpha sorts first: Alpha requires Zulu.
        self::assertSame(
            ['Zulu: zulu.greet', 'Zulu: customer://{id}', 'Alpha: alpha.support'],
            self::owned($registry),
        );

        self::assertSame('Alpha', $registry->find(CapabilityKind::Prompt, 'alpha.support')?->module);
    }

    public function test_a_disabled_module_offers_nothing(): void
    {
        $withMuted = $this->registry('tests/Fixtures/Modules/Mcp/Plugins');
        $withoutMuted = $this->registry('tests/Fixtures/Modules/Mcp/Plugins', ['modules' => ['disabled' => ['Muted']]]);

        self::assertNotNull($withMuted->find(CapabilityKind::Tool, 'muted.greet'));
        self::assertNull($withoutMuted->find(CapabilityKind::Tool, 'muted.greet'));
    }

    public function test_two_modules_claiming_one_name_stop_boot_naming_both(): void
    {
        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('MCP tool "customer.greet" is registered by Crm and again by Customer');

        $this->registry('tests/Fixtures/Modules/McpDuplicate/Plugins');
    }

    /** Declaring is for module.php; a capability cannot be added once the module has loaded. */
    public function test_mcp_is_declared_while_loading_only(): void
    {
        $app = $this->shippedApplication()->boot();
        $context = $app->container()->get(\App\Engine\Module\ModuleRegistry::class)->contexts()[0] ?? null;

        self::assertInstanceOf(ModuleContext::class, $context);

        $this->expectException(\App\Engine\Module\ModuleException::class);

        $context->mcp(static function (): void {});
    }
}
