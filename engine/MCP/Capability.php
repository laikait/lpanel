<?php

declare(strict_types=1);

namespace App\Engine\MCP;

/**
 * One registered tool, resource or prompt: what it is called, which class
 * handles it, which module owns it.
 *
 * The name of a resource is its URI template, `customer://{id}`.
 *
 * The handler is a class name, kept as a string: registering a capability
 * loads nothing, so an application with a hundred tools pays for the one a
 * client calls. What the handler must look like is the business of the tool,
 * resource and prompt phases, which add the metadata each kind needs.
 */
final class Capability
{
    /**
     * @param class-string|string $handler
     * @param ?string             $permission an auth capability the caller must hold; null asks only for an authenticated caller
     */
    public function __construct(
        public readonly CapabilityKind $kind,
        public readonly string $name,
        public readonly string $handler,
        public readonly string $module = '',
        public readonly string $description = '',
        public readonly ?string $permission = null,
    ) {}
}
