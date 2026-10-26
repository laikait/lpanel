<?php

declare(strict_types=1);

namespace App\Engine\MCP;

/**
 * The JSON-RPC 2.0 error codes MCP answers with.
 *
 * MCP is JSON-RPC underneath, so a client already knows these five and nothing
 * else needs inventing. The one MCP-specific code is RESOURCE_NOT_FOUND, which the
 * protocol reserves in the server-error range.
 */
enum McpErrorCode: int
{
    /** The bytes were not JSON. */
    case ParseError = -32700;

    /** JSON, but not a JSON-RPC request. */
    case InvalidRequest = -32600;

    /** No such method -- or no such tool, resource or prompt the caller may see. */
    case MethodNotFound = -32601;

    /** The method exists and its arguments are wrong: a schema violation, an unknown name. */
    case InvalidParams = -32602;

    /** The server failed. The message says so and nothing more. */
    case InternalError = -32603;

    /** A resource URI that matches nothing registered. */
    case ResourceNotFound = -32002;
}
