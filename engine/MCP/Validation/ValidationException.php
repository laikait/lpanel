<?php

declare(strict_types=1);

namespace App\Engine\MCP\Validation;

use App\Engine\MCP\McpError;
use App\Engine\MCP\McpErrorCode;
use App\Engine\MCP\McpException;

/**
 * Arguments that do not match the declared schema.
 *
 * The client gets every problem found, up to a limit, each as a path and a
 * reason the framework wrote -- never the offending value, which the client
 * already has and a log should not.
 */
final class ValidationException extends McpException
{
    private function __construct(private readonly McpError $mcpError)
    {
        parent::__construct($mcpError->message);
    }

    public function error(): McpError
    {
        return $this->mcpError;
    }

    /** @param list<array{path: string, message: string}> $errors */
    public static function invalid(string $capability, array $errors): self
    {
        return new self(new McpError(McpErrorCode::InvalidParams, 'Invalid arguments.', ['capability' => $capability, 'errors' => $errors]));
    }

    public static function tooLarge(string $capability, int $limit): self
    {
        return new self(new McpError(McpErrorCode::InvalidParams, \sprintf('Arguments are at most %d bytes.', $limit), ['capability' => $capability]));
    }
}
