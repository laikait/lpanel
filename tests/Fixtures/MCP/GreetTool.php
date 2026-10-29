<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\MCP;

use App\Engine\MCP\McpContext;
use App\Engine\MCP\Tool\Tool;
use App\Engine\MCP\Tool\ToolResult;

/** A tool that answers with structured content. */
final class GreetTool implements Tool
{
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ];
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        $name = \is_string($arguments['name'] ?? null) ? $arguments['name'] : 'nobody';

        return ToolResult::structured(['greeting' => 'Hello, ' . $name, 'length' => \strlen($name), 'asked_by' => $context->identity->id, 'request' => $context->requestId]);
    }
}
