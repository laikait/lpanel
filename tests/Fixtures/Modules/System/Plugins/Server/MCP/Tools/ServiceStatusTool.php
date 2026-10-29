<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\System\Plugins\Server\MCP\Tools;

use App\Engine\MCP\McpContext;
use App\Engine\MCP\Tool\Tool;
use App\Engine\MCP\Tool\ToolResult;
use App\Tests\Fixtures\Modules\System\Plugins\Server\Services\ServerService;

/** server.service.status */
final class ServiceStatusTool implements Tool
{
    /**
     * The pattern systemd unit names follow. ServicePolicy checks again; this
     * is for the client, which learns the shape from the schema.
     */
    public const NAME = '^[A-Za-z0-9@:._-]{1,128}$';

    public function __construct(private readonly ServerService $server) {}

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'service' => ['type' => 'string', 'pattern' => self::NAME, 'description' => 'A systemd unit, such as "nginx".'],
            ],
            'required' => ['service'],
        ];
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        /** @var string $service */
        $service = $arguments['service'];

        return Answer::from(fn(): ToolResult => ToolResult::structured($this->server->serviceStatus($context->identity, $service)));
    }
}
