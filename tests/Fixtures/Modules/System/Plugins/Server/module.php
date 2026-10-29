<?php

declare(strict_types=1);

use App\Engine\Auth\AccessCollector;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\MCP\McpCollector;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\System\Security\SystemCapability;
use App\Tests\Fixtures\Modules\System\Plugins\Server\Http\ServerEndpoints;
use App\Tests\Fixtures\Modules\System\Plugins\Server\MCP\Tools\CronListTool;
use App\Tests\Fixtures\Modules\System\Plugins\Server\MCP\Tools\ServerInfoTool;
use App\Tests\Fixtures\Modules\System\Plugins\Server\MCP\Tools\ServiceRestartTool;
use App\Tests\Fixtures\Modules\System\Plugins\Server\MCP\Tools\ServiceStatusTool;

/**
 * The demonstration server module: server.info, server.service.status,
 * server.service.restart and server.cron.list, over engine/System.
 *
 * What it shows is the division of labour. The module decides the operations
 * and who may ask for them; engine/System decides how, within the policies the
 * application configured. It offers no way to run a command or a script, and
 * its HTTP surface is read-only -- restarting is a service method, for an
 * interface that can carry an operator's deliberate intent, such as the console
 * or an MCP tool with confirmation.
 *
 * The MCP tools are the same four operations: each names the system capability
 * it needs, so an MCP client sees only what its user may do, and each calls
 * ServerService, which authorizes the exact target and lets engine/System's
 * policies decide. There is no server.execute and no server.shell.
 */
return static function (ModuleContext $module): void {
    $module->name('Server');

    $module->access(static function (AccessCollector $access): void {
        SystemCapability::declare(
            $access,
            SystemCapability::InfoRead,
            SystemCapability::ServiceRead,
            SystemCapability::ServiceRestart,
            SystemCapability::CronRead,
        );

        $access->role('server-viewer', [
            SystemCapability::InfoRead->value,
            SystemCapability::ServiceRead->value,
            SystemCapability::CronRead->value,
        ], description: 'See the server, its services and the application\'s cron jobs.');

        $access->role('server-operator', [SystemCapability::ServiceRestart->value], ['server-viewer'], 'Restart the services the application allows.');
    });

    $module->services(static function (ServiceRegistrar $services): void {
        $services->bind(ServerEndpoints::class);
    });

    $module->mcp(static function (McpCollector $mcp): void {
        $mcp->tool('server.info', ServerInfoTool::class, 'The server\'s OS, PHP version, CPUs, memory, load and uptime.', permission: SystemCapability::InfoRead->value)
            ->tool('server.service.status', ServiceStatusTool::class, 'Whether a systemd service exists, is running, and starts at boot.', permission: SystemCapability::ServiceRead->value)
            ->tool('server.service.restart', ServiceRestartTool::class, 'Restart a service the application allows restarting. Needs confirm: true.', permission: SystemCapability::ServiceRestart->value)
            ->tool('server.cron.list', CronListTool::class, 'The application\'s own cron jobs.', permission: SystemCapability::CronRead->value);
    });

    $module->routes(static function (RouteCollector $routes): void {
        $routes->get('/server/info', [ServerEndpoints::class, 'info']);
        $routes->get('/server/services/{name}', [ServerEndpoints::class, 'service']);
        $routes->get('/server/cron', [ServerEndpoints::class, 'cron']);
    });
};
