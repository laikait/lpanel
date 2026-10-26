<?php

declare(strict_types=1);

namespace App\Engine\MCP\Protocol;

use App\Engine\MCP\McpError;
use App\Engine\MCP\McpErrorCode;
use App\Engine\MCP\McpException;

/**
 * A message that is not a request this server can process.
 *
 * Carries the error the client gets and, when the message got far enough to
 * have one, the id to answer it with -- JSON-RPC answers a parse error with a
 * null id, and an invalid parameter with the request's own.
 */
final class ProtocolException extends McpException
{
    private function __construct(
        private readonly McpError $mcpError,
        public readonly string|int|null $requestId = null,
    ) {
        parent::__construct($mcpError->message);
    }

    public function error(): McpError
    {
        return $this->mcpError;
    }

    public static function parseError(): self
    {
        return new self(new McpError(McpErrorCode::ParseError, 'Parse error: the message is not valid JSON.'));
    }

    public static function tooLarge(int $limit): self
    {
        return new self(new McpError(McpErrorCode::InvalidRequest, \sprintf('Invalid request: a message is at most %d bytes.', $limit)));
    }

    public static function invalidRequest(string $why, string|int|null $id = null): self
    {
        return new self(new McpError(McpErrorCode::InvalidRequest, 'Invalid request: ' . $why . '.'), $id);
    }

    public static function invalidParams(string $why, string|int $id): self
    {
        return new self(new McpError(McpErrorCode::InvalidParams, 'Invalid params: ' . $why . '.'), $id);
    }

    public static function methodNotFound(string $method, string|int $id): self
    {
        return new self(new McpError(McpErrorCode::MethodNotFound, 'Method not found.', ['method' => $method]), $id);
    }
}
