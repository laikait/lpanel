<?php

declare(strict_types=1);

use App\Engine\Auth\AccessCollector;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\MCP\Capability;
use App\Engine\MCP\McpCollector;
use App\Engine\Module\ModuleContext;
use App\Tests\Fixtures\Modules\McpDemo\Plugins\McpDemo\MCP\Prompts\Diagnostics;
use App\Tests\Fixtures\Modules\McpDemo\Plugins\McpDemo\MCP\Resources\StatusResource;
use App\Tests\Fixtures\Modules\McpDemo\Plugins\McpDemo\MCP\Tools\EchoTool;
use App\Tests\Fixtures\Modules\McpDemo\Plugins\McpDemo\MCP\Tools\GetStatus;
use App\Tests\Fixtures\Modules\McpDemo\Plugins\McpDemo\Services\StatusService;

/**
 * The MCP demonstration module: one of each capability, a permission, a hook
 * and a filter, and nothing an MCP client could use to reach beyond them.
 *
 *     demo.echo          tool      any signed-in user
 *     demo.status        tool      mcpdemo.status.read
 *     status://app       resource  mcpdemo.status.read
 *     demo.diagnostics   prompt    any signed-in user
 *
 * The MCP classes are thin: each asks StatusService, which is where the module's
 * logic lives, and which a route or a console command would call just the same.
 */
return static function (ModuleContext $module): void {
    $module->name('McpDemo')->version('1.0.0');

    $module->access(static function (AccessCollector $access): void {
        $access->capability('mcpdemo.status.read', 'See the application\'s status over MCP.');
        $access->role('mcp-demo-viewer', ['mcpdemo.status.read'], description: 'Read the demo\'s status tool and resource.');
    });

    $module->services(static function (ServiceRegistrar $services): void {
        // One instance, so the call count survives from one request to the next
        // in a long-running STDIO session.
        $services->singleton(StatusService::class);
    });

    $module->mcp(static function (McpCollector $mcp): void {
        $mcp->tool('demo.echo', EchoTool::class, 'Repeat a short text back.')
            ->tool('demo.status', GetStatus::class, 'The application\'s version, environment and module count.', permission: 'mcpdemo.status.read')
            ->resource('status://app', StatusResource::class, 'The same status, as a JSON resource.', permission: 'mcpdemo.status.read')
            ->prompt('demo.diagnostics', Diagnostics::class, 'Start a conversation about something that is going wrong.');
    });

    // A hook and a filter, the way any module extends MCP: after a tool of
    // this module succeeds, count it; before demo.echo is validated, trim what
    // it was given. Neither can reach a call that authorization refused.
    $module->onBoot(static function (HookEngine $hooks, FilterEngine $filters, StatusService $status): void {
        $hooks->add('mcp.tool.after', static function (Capability $tool) use ($status): void {
            if ($tool->module === 'McpDemo') {
                $status->recordCall();
            }
        }, 10, 'McpDemo');

        $filters->add('mcp.tool.input', static function (array $arguments, Capability $tool): array {
            if ($tool->name === 'demo.echo' && is_string($arguments['text'] ?? null)) {
                $arguments['text'] = trim($arguments['text']);
            }

            return $arguments;
        }, 10, 'McpDemo');
    });
};
