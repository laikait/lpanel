<?php

declare(strict_types=1);

use App\Engine\Auth\AccessCollector;
use App\Engine\Cli\CommandCollector;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\Scheduler\ScheduleCollector;
use App\Engine\Hook\HookEngine;
use App\Modules\Security\BruteForce\BruteForce;
use App\Modules\Security\BruteForce\Listeners;
use App\Modules\Security\Commands\BruteForceCommands;
use App\Modules\Security\Commands\FirewallCommands;
use App\Modules\Security\Firewall\Entries;
use App\Modules\Security\Firewall\Firewall;
use App\Modules\Security\Http\BruteForceApi;
use App\Modules\Security\Http\BruteForcePages;
use App\Modules\Security\Http\FirewallApi;
use App\Modules\Security\Http\FirewallPages;

/**
 * Security: the firewall (ports, allow and deny lists, blocked countries) now;
 * brute-force blocking, ModSecurity and account isolation follow.
 *
 * Root only: a firewall mistake shuts everyone out, so no other role holds
 * `server.firewall`.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Security')
        ->version('0.1.0')
        ->description('The firewall: ports, address lists and blocked countries.');

    $module->requires('Panel', '^0.1')
        ->requires('Audit', '^0.1')
        ->requires('Identity', '^0.1')
        ->requires('Agent', '^0.1');

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(Entries::class);
        $services->singleton(Firewall::class);
        $services->bind(FirewallPages::class);
        $services->bind(FirewallApi::class);
        $services->bind(FirewallCommands::class);
        $services->singleton(BruteForce::class);
        $services->singleton(Listeners::class);
        $services->bind(BruteForcePages::class);
        $services->bind(BruteForceApi::class);
        $services->bind(BruteForceCommands::class);
    });

    $module->access(static function (AccessCollector $access): void {
        $access->capability('server.firewall', 'See and change the server\'s firewall: open and close ports, the allow and deny lists, blocked countries.');
    });

    $module->routes(static function (RouteCollector $routes): void {
        $id = '\d+';
        $proto = 'tcp|udp';
        $port = '\d{1,5}(?:-\d{1,5})?';

        $routes->group('/admin/security/firewall', static function (RouteCollector $routes) use ($id): void {
            $routes->get('', [FirewallPages::class, 'index'])->name('');
            $routes->post('/ports', [FirewallPages::class, 'openPort'])->name('.ports');
            $routes->post('/ports/close', [FirewallPages::class, 'closePort'])->name('.ports.close');
            $routes->post('/entries', [FirewallPages::class, 'addEntry'])->name('.entries');
            $routes->post('/entries/{id}/delete', [FirewallPages::class, 'removeEntry'])->name('.entries.delete')->where('id', $id);
            $routes->post('/countries', [FirewallPages::class, 'countries'])->name('.countries');
        }, name: 'admin.firewall', meta: ['can' => 'server.firewall']);

        $routes->group('/admin/security/bruteforce', static function (RouteCollector $routes): void {
            $routes->get('', [BruteForcePages::class, 'index'])->name('');
            $routes->post('', [BruteForcePages::class, 'save'])->name('.save');
        }, name: 'admin.bruteforce', meta: ['can' => 'server.firewall']);

        $routes->group('/admin/api/v1/bruteforce', static function (RouteCollector $routes): void {
            $routes->get('', [BruteForceApi::class, 'show'])->name('');
            $routes->put('', [BruteForceApi::class, 'update'])->name('.update');
        }, name: 'api.bruteforce', meta: ['can' => 'server.firewall', 'csrf' => false, 'rate_limit' => '60/1m']);

        $routes->group('/admin/api/v1/firewall', static function (RouteCollector $routes) use ($id, $proto, $port): void {
            $routes->get('', [FirewallApi::class, 'show'])->name('');
            $routes->post('/ports', [FirewallApi::class, 'openPort'])->name('.ports');
            $routes->delete('/ports/{proto}/{port}', [FirewallApi::class, 'closePort'])->name('.ports.close')->where('proto', $proto)->where('port', $port);
            $routes->post('/entries', [FirewallApi::class, 'addEntry'])->name('.entries');
            $routes->delete('/entries/{id}', [FirewallApi::class, 'removeEntry'])->name('.entries.delete')->where('id', $id);
            $routes->put('/countries', [FirewallApi::class, 'countries'])->name('.countries');
        }, name: 'api.firewall', meta: ['can' => 'server.firewall', 'csrf' => false, 'rate_limit' => '60/1m']);
    });

    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('firewall:status', [FirewallCommands::class, 'status'])
            ->describe('Show the firewall: open ports, the allow and deny lists, blocked countries.');
        $commands->add('firewall:open', [FirewallCommands::class, 'open'])
            ->describe('Open a port to everyone, or only to some addresses.')
            ->argument('port', 'A port, or a range such as 30000-30100.')
            ->option('proto', 'tcp (default) or udp.')
            ->option('only', 'Addresses or networks (comma-separated) that may reach it; without it, everyone.');
        $commands->add('firewall:close', [FirewallCommands::class, 'close'])
            ->describe('Close a port (the ones the panel and SSH use cannot be closed).')
            ->argument('port', 'A port, or a range.')
            ->option('proto', 'tcp (default) or udp.');
        $commands->add('firewall:allow', [FirewallCommands::class, 'allow'])
            ->describe('Always let an address or network in, whatever else blocks it.')
            ->argument('address', 'An IPv4 or IPv6 address, or a network (203.0.113.0/24).')
            ->option('note', 'What it is.');
        $commands->add('firewall:deny', [FirewallCommands::class, 'deny'])
            ->describe('Block an address or network on every port.')
            ->argument('address', 'An IPv4 or IPv6 address, or a network.')
            ->option('note', 'Why.')
            ->option('for', '1h, 1d, 7d, 30d or never (default).');
        $commands->add('firewall:remove', [FirewallCommands::class, 'remove'])
            ->describe('Take an address out of the allow and deny lists.')
            ->argument('address', 'The address as it was added, or its id from firewall:status.');
        $commands->add('firewall:countries', [FirewallCommands::class, 'countries'])
            ->describe('Show the countries blocked, or set them (their address lists are fetched from ipdeny.com).')
            ->option('set', 'Two-letter codes, comma-separated: these and no others are blocked.')
            ->flag('none', 'Block no country.')
            ->flag('refresh', 'Fetch the lists again.');
        $commands->add('firewall:sync', [FirewallCommands::class, 'sync'])
            ->describe('Send the lists and countries to the server again, as the panel has them.');
        $commands->add('firewall:expire', [FirewallCommands::class, 'expire'])
            ->describe('End the blocked addresses whose time has passed.');
        $commands->add('bruteforce:status', [BruteForceCommands::class, 'status'])
            ->describe('Show brute-force blocking: its settings, the busiest addresses, the latest blocks.');
        $commands->add('bruteforce:set', [BruteForceCommands::class, 'set'])
            ->describe('Change brute-force blocking.')
            ->flag('on', 'Turn blocking on.')
            ->flag('off', 'Turn blocking off.')
            ->option('threshold', 'Failed sign-ins that block an address (3 to 1000).')
            ->option('window', 'Minutes they are counted within (1 to 1440).')
            ->option('for', 'How long the first block lasts: 1h, 1d, 7d or 30d.');
        $commands->add('bruteforce:scan', [BruteForceCommands::class, 'scan'])
            ->describe('Read the logs of the services, count the failed sign-ins and block what is over the limit.');
    });

    $module->schedules(static function (ScheduleCollector $schedules): void {
        $schedules->command('firewall:expire')->everyMinute()->withoutOverlapping(600);
        $schedules->command('bruteforce:scan')->everyMinute()->withoutOverlapping(600);
    });

    // The panel's own failed sign-ins count towards a block like the services'.
    $module->onBoot(static function (Listeners $listeners, HookEngine $hooks): void {
        $hooks->add('auth.failed', [$listeners, 'signInFailed'], 20, 'Security');
    });

    $module->filter('panel.nav', static function (array $items): array {
        $items[] = ['area' => 'admin', 'section' => 'Panel.section_security', 'key' => 'firewall', 'label' => 'Security.fw_title', 'icon' => 'shield', 'route' => 'admin.firewall', 'can' => 'server.firewall', 'order' => 80];
        $items[] = ['area' => 'admin', 'section' => 'Panel.section_security', 'key' => 'bruteforce', 'label' => 'Security.bf_title', 'icon' => 'lock', 'route' => 'admin.bruteforce', 'can' => 'server.firewall', 'order' => 82];

        return $items;
    });
};
