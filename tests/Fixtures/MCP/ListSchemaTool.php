<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\MCP;

use App\Engine\MCP\McpContext;
use App\Engine\MCP\Tool\Tool;
use App\Engine\MCP\Tool\ToolResult;

/** A tool whose input schema is not an object schema. */
final class ListSchemaTool implements Tool
{
    public function inputSchema(): array
    {
        return ['type' => 'array'];
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        return ToolResult::text('unreachable');
    }
}
