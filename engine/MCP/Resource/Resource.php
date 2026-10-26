<?php

declare(strict_types=1);

namespace App\Engine\MCP\Resource;

use App\Engine\MCP\McpContext;

/**
 * Read-only application context an MCP client may fetch by URI, implemented
 * by a module.
 *
 *     $mcp->resource('customer://{id}', CustomerResource::class);
 *
 *     final class CustomerResource implements Resource
 *     {
 *         public function read(string $uri, array $parameters, McpContext $context): ResourceContents
 *         {
 *             $customer = $this->customers->find((int) $parameters['id']);
 *
 *             return ResourceContents::json($uri, ['id' => $customer->id, 'name' => $customer->name]);
 *         }
 *     }
 *
 * **The parameters are strings the client chose.** The template decides where
 * they may appear -- one path segment each, never containing a slash -- and the
 * handler decides what they may be: an id is checked to be an id before it is
 * used as one. A resource reads; it does not change anything.
 */
interface Resource
{
    /**
     * @param string                $uri        the URI as the client sent it
     * @param array<string, string> $parameters the template's placeholders, decoded
     */
    public function read(string $uri, array $parameters, McpContext $context): ResourceContents;
}
