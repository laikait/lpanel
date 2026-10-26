<?php

declare(strict_types=1);

namespace App\Engine\MCP\Prompt;

use App\Engine\MCP\McpError;
use App\Engine\MCP\McpErrorCode;
use App\Engine\MCP\McpException;

/** A prompt request that cannot be answered: no such prompt, or arguments that do not fit it. */
final class PromptException extends McpException
{
    private function __construct(private readonly McpError $mcpError)
    {
        parent::__construct($mcpError->message);
    }

    public function error(): McpError
    {
        return $this->mcpError;
    }

    public static function unknown(string $name): self
    {
        return new self(new McpError(McpErrorCode::InvalidParams, 'Unknown prompt.', ['prompt' => \mb_substr($name, 0, 128)]));
    }

    /** $argument is one the prompt declares, or one the client sent -- never a value. */
    public static function invalidArguments(string $name, string $why, ?string $argument = null): self
    {
        $data = ['prompt' => $name];

        if ($argument !== null) {
            $data['argument'] = $argument;
        }

        return new self(new McpError(McpErrorCode::InvalidParams, 'Invalid prompt arguments: ' . $why . '.', $data));
    }

    public static function failed(): self
    {
        return new self(McpError::internal());
    }
}
