<?php

declare(strict_types=1);

namespace App\Engine\MCP;

use App\Engine\Error\FrameworkException;

/**
 * The MCP layer refused something.
 *
 * Abstract: a type to catch. Each part of the layer throws its own subclass.
 * Every one of them knows the McpError a client should receive for it, so the
 * server never has to decide what to tell a client by looking at an exception's
 * message -- which is exactly how internal detail leaks into a protocol.
 */
abstract class McpException extends FrameworkException
{
    /** What the client is told. */
    abstract public function error(): McpError;
}
