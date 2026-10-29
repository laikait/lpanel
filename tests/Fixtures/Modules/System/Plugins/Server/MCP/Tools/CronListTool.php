<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\System\Plugins\Server\MCP\Tools;

use App\Engine\MCP\McpContext;
use App\Engine\MCP\Tool\Tool;
use App\Engine\MCP\Tool\ToolResult;
use App\Tests\Fixtures\Modules\System\Plugins\Server\Services\ServerService;

/** server.cron.list: the application's own cron jobs, not the whole crontab. */
final class CronListTool implements Tool
{
    public function __construct(private readonly ServerService $server) {}

    public function inputSchema(): array
    {
        return ['type' => 'object'];
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        return Answer::from(fn(): ToolResult => ToolResult::structured(['jobs' => $this->server->cronJobs($context->identity)]));
    }
}
