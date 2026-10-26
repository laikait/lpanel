<?php

declare(strict_types=1);

namespace App\Engine\MCP;

/**
 * What a module registers MCP capabilities through.
 *
 *     $module->mcp(static function (McpCollector $mcp): void {
 *         $mcp->tool('customer.get', GetCustomer::class, 'Retrieve a customer by id.', permission: 'customer.view');
 *         $mcp->resource('customer://{id}', CustomerResource::class, permission: 'customer.view');
 *         $mcp->prompt('customer.support', CustomerSupport::class);
 *     });
 *
 * The same shape as the route, command and access collectors: the module says
 * what it provides, and the collector records that the module provided it.
 *
 * A permission is an ordinary capability, declared by some module with
 * $module->access(); an undeclared one stops boot, as it does on a route.
 */
final class McpCollector
{
    public function __construct(
        private readonly McpRegistry $registry,
        private readonly string $module = '',
    ) {}

    /** @param class-string $handler */
    public function tool(string $name, string $handler, string $description = '', ?string $permission = null): self
    {
        return $this->add(CapabilityKind::Tool, $name, $handler, $description, $permission);
    }

    /** @param class-string $handler */
    public function resource(string $uriTemplate, string $handler, string $description = '', ?string $permission = null): self
    {
        return $this->add(CapabilityKind::Resource, $uriTemplate, $handler, $description, $permission);
    }

    /** @param class-string $handler */
    public function prompt(string $name, string $handler, string $description = '', ?string $permission = null): self
    {
        return $this->add(CapabilityKind::Prompt, $name, $handler, $description, $permission);
    }

    private function add(CapabilityKind $kind, string $name, string $handler, string $description, ?string $permission): self
    {
        $this->registry->register(new Capability($kind, $name, $handler, $this->module, $description, $permission));

        return $this;
    }
}
