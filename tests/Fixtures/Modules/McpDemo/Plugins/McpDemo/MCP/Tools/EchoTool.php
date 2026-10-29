<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\McpDemo\Plugins\McpDemo\MCP\Tools;

use App\Engine\MCP\McpContext;
use App\Engine\MCP\Tool\Tool;
use App\Engine\MCP\Tool\ToolResult;

/**
 * demo.echo -- the plan's Echo.php. `echo` is a reserved word in PHP, so the
 * class is EchoTool.
 *
 * The smallest useful tool: a schema that bounds its input, and a result that
 * says who asked.
 */
final class EchoTool implements Tool
{
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1000, 'description' => 'What to repeat.'],
            ],
            'required' => ['text'],
        ];
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        /** @var string $text the schema says so */
        $text = $arguments['text'];

        return ToolResult::structured(['text' => $text, 'for' => $context->identity->id], $text);
    }
}
