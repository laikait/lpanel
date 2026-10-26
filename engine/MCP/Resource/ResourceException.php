<?php

declare(strict_types=1);

namespace App\Engine\MCP\Resource;

use App\Engine\MCP\McpError;
use App\Engine\MCP\McpErrorCode;
use App\Engine\MCP\McpException;

/** A resource read that cannot be answered: no such resource, or a URI that is not one. */
final class ResourceException extends McpException
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
     * For a URI no registered template matches, and for one whose handler says
     * the thing it names does not exist: the client cannot tell the two apart,
     * which is the point.
     */
    public static function notFound(string $uri): self
    {
        return new self(new McpError(McpErrorCode::ResourceNotFound, 'Resource not found.', ['uri' => $uri]));
    }

    public static function invalidUri(): self
    {
        return new self(new McpError(McpErrorCode::InvalidParams, 'The resource URI is not a valid absolute URI.'));
    }

    /** An unexpected failure, already reported. Says nothing about it. */
    public static function failed(): self
    {
        return new self(McpError::internal());
    }
}
