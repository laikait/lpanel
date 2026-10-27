<?php

declare(strict_types=1);

use App\Engine\Auth\Identity;
use App\Engine\Cli\CommandCollector;
use App\Engine\Container\Container;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\Scheduler\ScheduleCollector;
use App\Modules\Operations\Catalogue\Catalogue;
use App\Modules\Operations\Commands\OperationCommands;
use App\Modules\Operations\Commands\Sweep;
use App\Modules\Operations\Http\AgentOperationsApi;
use App\Modules\Operations\Http\OperationsApi;
use App\Modules\Operations\Http\OperationsPage;
use App\Modules\Operations\Http\OperationViews;
use App\Modules\Operations\Http\ServerPanels;
use App\Modules\Operations\OperationRequests;
use App\Modules\Operations\Store\OperationStore;
use App\Modules\Servers\Store\Server;
use App\Modules\Shared\Ui\Navigation;

/**
 * Work for the agents (ADR-0006, AGENT_PROTOCOL.md §6-7, milestone M4): the
 * operation catalogue, operations and their history, the agents' claim,
 * lease and result endpoints, the lease sweeper, and the screens.
 *
 * Its own state machine, not the LPHP queue: nothing here imports
 * App\Engine\Queue. Agents pull their work; the panel never calls them.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Operations')
        ->version('0.1.0')
        ->description('Signed operations for the agents: catalogue, claim, lease, result, sweeper and screens.')
        ->requires('Servers')
        ->requires('Audit');

    $module->config([
        // Seconds a queued operation waits for its agent before it expires.
        'ttl' => 600,
    ]);

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(Catalogue::class);
        $services->singleton(OperationStore::class);
        $services->singleton(OperationRequests::class);
        $services->singleton(OperationViews::class);
    });

    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('operations:sweep', Sweep::class)
            ->describe('Expire unclaimed operations; retry or settle those whose lease lapsed.');
        $commands->add('operations:run', [OperationCommands::class, 'run'])
            ->describe('Ask a server\'s agent for an operation, e.g. system.info.')
            ->argument('server', 'The server\'s id, or its name.')
            ->argument('op', 'The operation, e.g. system.metrics.')
            ->option('op-version', 'A version other than the newest.')
            ->option('timeout', 'With --wait, seconds to wait (default 60).')
            ->flag('wait', 'Wait until it finishes; exit 0 only if it succeeded.')
            ->flag('json', 'Print it as JSON.');
        $commands->add('operations:list', [OperationCommands::class, 'list'])
            ->describe('The 50 newest operations.')
            ->option('server', 'Only this server\'s (id or name).')
            ->flag('json', 'As JSON.');
        $commands->add('operations:show', [OperationCommands::class, 'show'])
            ->describe('One operation, with its result.')
            ->argument('id', 'The operation\'s id.')
            ->flag('json', 'As JSON.');
    });

    $module->schedules(static function (ScheduleCollector $schedules): void {
        $schedules->command('operations:sweep')->everyMinute();
    });

    $module->routes(static function (RouteCollector $routes): void {
        $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';
        $ulid = '[0-9A-HJKMNP-TV-Z]{26}';

        // Seeing operations needs servers.read; asking for or cancelling one
        // needs that operation's own capability, checked by OperationRequests.
        $routes->get('/operations', [OperationsPage::class, 'index'])->name('operations.index')->meta(['can' => 'servers.read']);
        $routes->get('/operations/{id}', [OperationsPage::class, 'show'])->name('operations.show')->where('id', $uuid)->meta(['can' => 'servers.read']);
        $routes->post('/operations/{id}/cancel', [OperationsPage::class, 'cancel'])->name('operations.cancel')->where('id', $uuid)->meta(['can' => 'servers.read', 'rate_limit' => '30/1m']);
        $routes->post('/servers/{id}/operations', [OperationsPage::class, 'store'])->name('operations.request')->where('id', $ulid)->meta(['can' => 'servers.read', 'rate_limit' => '30/1m']);

        $api = static function (RouteCollector $routes) use ($uuid, $ulid): void {
            $routes->get('/operations', [OperationsApi::class, 'index'])->name('operations.index')->meta(['can' => 'servers.read']);
            $routes->get('/operations/catalogue', [OperationsApi::class, 'catalogue'])->name('operations.catalogue')->meta(['can' => 'servers.read']);
            $routes->get('/operations/{id}', [OperationsApi::class, 'show'])->name('operations.show')->where('id', $uuid)->meta(['can' => 'servers.read']);
            $routes->post('/operations/{id}/cancel', [OperationsApi::class, 'cancel'])->name('operations.cancel')->where('id', $uuid)->meta(['can' => 'servers.read']);
            $routes->post('/servers/{id}/operations', [OperationsApi::class, 'store'])->name('operations.store')->where('id', $ulid)->meta(['can' => 'servers.read', 'rate_limit' => '30/1m']);
        };
        // Cookie-authenticated, so CSRF stays on (ARCHITECTURE.md §4).
        $routes->group('/api/v1', $api, name: 'api.v1.', meta: ['api' => true]);
        // Bearer tokens only; see Identity's /api/token/v1 group for why CSRF is off.
        $routes->group('/api/token/v1', $api, name: 'api.token.v1.', meta: ['api' => true, 'csrf' => false, 'auth' => true, 'rate_limit' => '120/1m']);

        // The agents' side. CSRF off: the signature and the client certificate are the only credentials.
        $routes->group('/agent/v1/operations', static function (RouteCollector $routes) use ($uuid): void {
            $routes->post('/claim', [AgentOperationsApi::class, 'claim'])->name('claim');
            $routes->post('/{id}/lease', [AgentOperationsApi::class, 'lease'])->name('lease')->where('id', $uuid);
            $routes->post('/{id}/result', [AgentOperationsApi::class, 'result'])->name('result')->where('id', $uuid);
        }, name: 'agent.v1.operations.', meta: ['api' => true, 'csrf' => false]);
    });

    $module->onBoot(static function (HookEngine $hooks, FilterEngine $filters, Navigation $nav, Container $container): void {
        // A revoked agent's work is cancelled in the same transaction (AGENT_PROTOCOL.md §5).
        $hooks->add('servers.revoked', static function (Server $server) use ($container): void {
            $container->get(OperationStore::class)->cancelForServer($server, 'The server\'s agent was revoked.');
        }, 10, 'Operations');

        $filters->add('servers.detail.panels', static fn(array $panels, Server $server, Identity $identity): array => $container->get(ServerPanels::class)($panels, $server, $identity), 10, 'Operations');

        $nav->add('admin', 'operations', 'Operations', 'operations.index', 'list-check', can: 'servers.read', order: 25);
    });
};
