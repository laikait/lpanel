<?php

declare(strict_types=1);

use App\Engine\Auth\AccessCollector;
use App\Engine\Cli\CommandCollector;
use App\Engine\Config\Config;
use App\Engine\Config\Env;
use App\Engine\Container\Container;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Filter\FilterEngine;
use App\Engine\Module\ModuleContext;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentKey;
use App\Modules\Agent\AgentListeners;
use App\Modules\Agent\Commands\AgentCommands;
use App\Modules\Agent\Transport;
use App\Modules\Agent\UnixSocketTransport;

/**
 * The panel's side of lpa, the privileged agent (dist/lpa, ADR-0003).
 *
 * Other modules inject AgentClient and call operations by name. Nothing else
 * in the panel touches the system.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Agent')
        ->version('0.1.0')
        ->description('Signed requests to lpa, the privileged agent, and the live server card.');

    $module->requires('Panel', '^0.1');

    $module->config([
        'socket' => Env::string('LPANEL_AGENT_SOCKET', '/run/lpa/lpa.sock'),
        'key_file' => Env::string('LPANEL_AGENT_KEY_FILE', '/etc/lpanel/agent.key'),
    ]);

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(Transport::class, static fn(Container $c): Transport => new UnixSocketTransport((string) $c->get(Config::class)->get('Agent.socket')));
        // Read once per process, on the first call: pages that never ask the
        // agent never touch the key file.
        $services->singleton(AgentKey::class, static fn(Container $c): AgentKey => AgentKey::fromFile((string) $c->get(Config::class)->get('Agent.key_file')));
        $services->singleton(AgentClient::class, static fn(Container $c): AgentClient => new AgentClient(
            static fn(): Transport => $c->get(Transport::class),
            static fn(): AgentKey => $c->get(AgentKey::class),
        ));
        $services->singleton(AgentListeners::class);
        $services->bind(AgentCommands::class);
    });

    $module->access(static function (AccessCollector $access): void {
        $access->capability('system.view', 'See the server\'s status: load, memory, disks, services.');
    });

    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('agent:keygen', [AgentCommands::class, 'keygen'])
            ->describe('Make the panel\'s agent signing key and the public key lpa reads.')
            ->option('secret', 'Where the signing key goes (default Agent.key_file).')
            ->option('public', 'Where the public key goes (default /etc/lpa/panel.pub).')
            ->flag('force', 'Replace existing keys.');
        $commands->add('agent:ping', [AgentCommands::class, 'ping'])
            ->describe('Check that lpa answers and accepts the panel\'s signature.');
        $commands->add('agent:facts', [AgentCommands::class, 'facts'])
            ->describe('Print this server\'s facts as the agent reports them.');
    });

    $module->onBoot(static function (AgentListeners $listeners, FilterEngine $filters): void {
        $filters->add('panel.dashboard', [$listeners, 'dashboard'], 5, 'Agent', 3);
    });
};
