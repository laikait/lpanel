<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\McpDemo\Plugins\McpDemo\MCP\Tools;

use App\Engine\MCP\McpContext;
use App\Engine\MCP\Tool\Tool;
use App\Engine\MCP\Tool\ToolResult;
use App\Tests\Fixtures\Modules\McpDemo\Plugins\McpDemo\Services\StatusService;

/** demo.status: the application's status, from the module's service. */
final class GetStatus implements Tool
{
    public function __construct(private readonly StatusService $status) {}

    public function inputSchema(): array
    {
        // No arguments, and so none accepted: an unlisted property is refused.
        return ['type' => 'object'];
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        return ToolResult::structured($this->status->status());
    }
}
