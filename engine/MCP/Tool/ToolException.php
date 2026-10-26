<?php

declare(strict_types=1);

namespace App\Engine\MCP\Tool;

use App\Engine\MCP\McpError;
use App\Engine\MCP\McpErrorCode;
use App\Engine\MCP\McpException;

/** A tool call a client made that cannot be made: no such tool, or arguments that are not an object. */
final class ToolException extends McpException
{
    private function __construct(private readonly McpError $mcpError)
    {
        parent::__construct($mcpError->message);
    }

    public function error(): McpError
    {
        return $this->mcpError;
    }

    /**
     * The name is echoed: the client sent it. Whether a tool exists but is
     * hidden from this caller is authorization's to decide, and it answers the
     * same way, so the two cannot be told apart.
     */
    public static function unknown(string $name): self
    {
        // The name is the client's own, echoed so it can tell which call failed,
        // and cut so that echoing it cannot be used to amplify a request.
        return new self(new McpError(McpErrorCode::InvalidParams, 'Unknown tool.', ['tool' => \mb_substr($name, 0, 128)]));
    }

    public static function argumentsNotAnObject(string $name): self
    {
        return new self(new McpError(McpErrorCode::InvalidParams, 'Tool arguments must be an object.', ['tool' => $name]));
    }
}
