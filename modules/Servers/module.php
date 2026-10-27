<?php

declare(strict_types=1);

use App\Engine\Auth\AccessCollector;
use App\Engine\Cli\CommandCollector;
use App\Engine\Config\Config;
use App\Engine\Container\Container;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\Scheduler\ScheduleCollector;
use App\Engine\Security\RateLimiter;
use App\Modules\Servers\Agent\AgentSettings;
use App\Modules\Servers\Agent\ClientCertificate;
use App\Modules\Servers\Agent\OperationKey;
use App\Modules\Servers\Agent\PanelEndpoint;
use App\Modules\Servers\Agent\RequestVerifier;
use App\Modules\Servers\Commands\KeyGen;
use App\Modules\Servers\Commands\ServerCommands;
use App\Modules\Servers\Commands\Sweep;
use App\Modules\Servers\Http\AgentApi;
use App\Modules\Servers\Http\ServersApi;
use App\Modules\Servers\Http\ServersPage;
use App\Modules\Servers\Store\ServerStore;
use App\Modules\Shared\Ui\Navigation;

/**
 * Managed servers and their LPA agents: pairing (enrollment), heartbeats,
 * key rotation and revocation (design/AGENT_PROTOCOL.md, milestone M3).
 *
 * The panel never contacts a server. Agents call /agent/v1, and every such
 * request is signed with the agent's key and made over TLS with its client
 * certificate, which panel Nginx hands on (ADR-0002, ADR-0003).
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Servers')
        ->version('0.1.0')
        ->description('Managed servers, agent enrollment, heartbeats, key rotation and revocation.')
        ->requires('Identity');

    $module->config([
        'agent_url' => '',
        'panel_pin' => '',
        'tls_certificate' => '',
        'operation_key' => '',
        // The FastCGI param panel Nginx puts the client certificate in.
        'client_cert_param' => 'SSL_CLIENT_ESCAPED_CERT',
        'offline_after' => 180,
        'enrollment_minutes' => 15,
        'rotation_overlap' => 86400,
        'poll' => ['idle' => 5, 'max' => 300],
        // Per agent, after its signature is checked (AGENT_PROTOCOL.md §3, check 7).
        'agent_rate_limit' => '120/1m',
    ]);

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(ServerStore::class);
        $services->singleton(AgentSettings::class);
        $services->singleton(ClientCertificate::class, static fn(Container $c): ClientCertificate => new ClientCertificate(
            (string) $c->get(Config::class)->string('Servers.client_cert_param', 'SSL_CLIENT_ESCAPED_CERT'),
        ));
        $services->singleton(OperationKey::class, static fn(Container $c): OperationKey => new OperationKey(
            (string) $c->get(Config::class)->string('Servers.operation_key', ''),
        ));
        $services->singleton(PanelEndpoint::class, static function (Container $c): PanelEndpoint {
            $config = $c->get(Config::class);

            return new PanelEndpoint(
                (string) $config->string('Servers.agent_url', ''),
                (string) $config->string('Servers.panel_pin', ''),
                (string) $config->string('Servers.tls_certificate', ''),
            );
        });
        $services->singleton(RequestVerifier::class, static fn(Container $c): RequestVerifier => new RequestVerifier(
            $c->get(ServerStore::class),
            $c->get(ClientCertificate::class),
            $c->get(RateLimiter::class),
            (string) $c->get(Config::class)->string('Servers.agent_rate_limit', '120/1m'),
        ));
    });

    $module->access(static function (AccessCollector $access): void {
        $access->capability('servers.read', 'See managed servers and their agents.');
        $access->capability('servers.manage', 'Add servers, pair, rotate and revoke their agents.');
    });

    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('servers:keygen', KeyGen::class)
            ->describe('Create the panel\'s operation-signing key (once).');
        $commands->add('servers:sweep', Sweep::class)
            ->describe('Forget expired agent request ids and retire rotated-out agent keys.');
        $commands->add('servers:add', [ServerCommands::class, 'add'])
            ->describe('Add a server and print the command that pairs its agent.')
            ->argument('name', 'What to call the server.')
            ->flag('token', 'Print the one-time token alone.');
        $commands->add('servers:list', [ServerCommands::class, 'list'])
            ->describe('List servers and whether their agents are online.')
            ->flag('json', 'As JSON, with each agent\'s keys.');
        $commands->add('servers:rotate', [ServerCommands::class, 'rotate'])
            ->describe('Ask a server\'s agent to replace its key at its next heartbeat.')
            ->argument('server', 'The server\'s id, or its name.');
        $commands->add('servers:revoke', [ServerCommands::class, 'revoke'])
            ->describe('Revoke a server\'s agent. Pairing it again needs a new token.')
            ->argument('server', 'The server\'s id, or its name.');
    });

    $module->schedules(static function (ScheduleCollector $schedules): void {
        $schedules->command('servers:sweep')->everyMinute();
    });

    $module->routes(static function (RouteCollector $routes): void {
        $uid = '[0-9A-HJKMNP-TV-Z]{26}';

        $routes->get('/servers', [ServersPage::class, 'index'])->name('servers.index')->meta(['can' => 'servers.read']);
        $routes->get('/servers/new', [ServersPage::class, 'create'])->name('servers.create')->meta(['can' => 'servers.manage']);
        $routes->post('/servers', [ServersPage::class, 'store'])->name('servers.store')->meta(['can' => 'servers.manage', 'rate_limit' => '20/1m']);
        $routes->get('/servers/{id}', [ServersPage::class, 'show'])->name('servers.show')->where('id', $uid)->meta(['can' => 'servers.read']);
        $routes->post('/servers/{id}/enroll', [ServersPage::class, 'enroll'])->name('servers.enroll')->where('id', $uid)->meta(['can' => 'servers.manage', 'rate_limit' => '20/1m']);
        $routes->post('/servers/{id}/rotate', [ServersPage::class, 'rotate'])->name('servers.rotate')->where('id', $uid)->meta(['can' => 'servers.manage']);
        $routes->post('/servers/{id}/revoke', [ServersPage::class, 'revoke'])->name('servers.revoke')->where('id', $uid)->meta(['can' => 'servers.manage']);

        $routes->get('/api/v1/servers', [ServersApi::class, 'index'])->name('api.v1.servers.index')->meta(['api' => true, 'can' => 'servers.read']);
        $routes->get('/api/v1/servers/{id}', [ServersApi::class, 'show'])->name('api.v1.servers.show')->where('id', $uid)->meta(['api' => true, 'can' => 'servers.read']);
        $routes->get('/api/token/v1/servers', [ServersApi::class, 'index'])->name('api.token.v1.servers.index')->meta(['api' => true, 'csrf' => false, 'can' => 'servers.read', 'rate_limit' => '120/1m']);
        $routes->get('/api/token/v1/servers/{id}', [ServersApi::class, 'show'])->name('api.token.v1.servers.show')->where('id', $uid)->meta(['api' => true, 'csrf' => false, 'can' => 'servers.read', 'rate_limit' => '120/1m']);

        // The agents' own API. CSRF off, so no cookie identifies anyone: a
        // signature and a client certificate are the only credentials here.
        $routes->group('/agent/v1', static function (RouteCollector $routes): void {
            // Per address: a token is 256 bits, the limit is against noise.
            $routes->post('/enroll', [AgentApi::class, 'enroll'])->name('enroll')->meta(['rate_limit' => '10/1h']);
            $routes->post('/heartbeat', [AgentApi::class, 'heartbeat'])->name('heartbeat');
            // /operations/* is the Operations module's.
            $routes->post('/keys/rotate', [AgentApi::class, 'rotate'])->name('rotate')->meta(['rate_limit' => '10/1h']);
        }, name: 'agent.v1.', meta: ['api' => true, 'csrf' => false]);
    });

    $module->onBoot(static function (Navigation $nav): void {
        $nav->add('admin', 'servers', 'Servers', 'servers.index', 'server', can: 'servers.read', order: 20);
    });
};
