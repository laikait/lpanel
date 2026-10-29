<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\System\Plugins\Server\MCP\Tools;

use App\Engine\MCP\McpContext;
use App\Engine\MCP\Tool\Tool;
use App\Engine\MCP\Tool\ToolResult;
use App\Tests\Fixtures\Modules\System\Plugins\Server\Services\ServerService;

/**
 * server.service.restart -- the one tool here that changes the machine.
 *
 * It needs `confirm: true` as well as the service. That is not security (the
 * permission and the service policy are); it is the deliberate intent the
 * module asked for: a model has to say, in the call itself, that restarting is
 * what it means to do, and a client that shows tool calls shows that.
 */
final class ServiceRestartTool implements Tool
{
    public function __construct(private readonly ServerService $server) {}

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'service' => ['type' => 'string', 'pattern' => ServiceStatusTool::NAME, 'description' => 'A systemd unit the application allows restarting.'],
                'confirm' => ['type' => 'boolean', 'const' => true, 'description' => 'Must be true: restarting interrupts the service.'],
            ],
            'required' => ['service', 'confirm'],
        ];
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        /** @var string $service */
        $service = $arguments['service'];

        return Answer::from(function () use ($service, $context): ToolResult {
            $this->server->restartService($context->identity, $service);

            return ToolResult::text(\sprintf('Restarted %s.', $service));
        });
    }
}
