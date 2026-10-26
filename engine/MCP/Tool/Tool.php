<?php

declare(strict_types=1);

namespace App\Engine\MCP\Tool;

use App\Engine\MCP\McpContext;

/**
 * An operation an MCP client may invoke, implemented by a module.
 *
 *     final class GetCustomer implements Tool
 *     {
 *         public function __construct(private readonly CustomerService $customers) {}
 *
 *         public function inputSchema(): array
 *         {
 *             return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']];
 *         }
 *
 *         public function call(array $arguments, McpContext $context): ToolResult
 *         {
 *             $customer = $this->customers->find($context->identity, $arguments['id']);
 *
 *             return ToolResult::structured(['id' => $customer->id, 'name' => $customer->name]);
 *         }
 *     }
 *
 * **Thin, like a controller.** The operation lives in an application service
 * that HTTP and the console call too; a tool translates arguments in and a
 * result out. Its name and description are given where it is registered, so
 * they are declared once.
 *
 * Collaborators come through the constructor, from the container.
 */
interface Tool
{
    /**
     * The JSON Schema of the arguments: an object schema, as MCP requires.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * @param array<string, mixed> $arguments already checked against inputSchema()
     */
    public function call(array $arguments, McpContext $context): ToolResult;
}
