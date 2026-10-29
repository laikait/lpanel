<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\MCP;

use App\Engine\MCP\McpContext;
use App\Engine\MCP\Tool\Tool;
use App\Engine\MCP\Tool\ToolException;
use App\Engine\MCP\Tool\ToolResult;

/**
 * A tool that fails in each of the ways a tool can: an exception carrying
 * something private, a protocol refusal, a result holding an object, and a
 * schema that is not an object schema.
 */
final class BrokenTool implements Tool
{
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['how' => ['type' => 'string', 'enum' => ['throw', 'refuse', 'object', 'own-error']]],
        ];
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        return match ($arguments['how'] ?? null) {
            'throw' => throw new \RuntimeException('SQLSTATE[28000] access denied for user app@10.0.0.5 using password S3cret'),
            'refuse' => throw ToolException::unknown('something-else'),
            'object' => ToolResult::structured(['customer' => new \ArrayObject(['password' => 'S3cret'])]),
            'own-error' => ToolResult::error('Invoice 42 is already paid.'),
            default => ToolResult::text('fine'),
        };
    }
}
