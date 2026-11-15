<?php

declare(strict_types=1);

use App\Engine\Auth\AccessCollector;
use App\Engine\Cli\CommandCollector;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\Scheduler\ScheduleCollector;
use App\Modules\Dns\Commands\DnsCommands;
use App\Modules\Dns\DnsActions;
use App\Modules\Dns\DnsChecks;
use App\Modules\Dns\DnsListeners;
use App\Modules\Dns\DnsSync;
use App\Modules\Dns\DnsZones;
use App\Modules\Dns\Http\DnsApi;
use App\Modules\Dns\Http\DnsPages;

/**
 * DNS: a zone in PowerDNS for every domain, made from a template; the zone
 * editor; the server's and each reseller's own nameservers; and the checks
 * that let AutoSSL get wildcard certificates.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Dns')
        ->version('0.1.0')
        ->description('DNS zones for every domain, the zone editor, nameservers and zone templates.');

    $module->requires('Panel', '^0.1')
        ->requires('Audit', '^0.1')
        ->requires('Identity', '^0.1')
        ->requires('Agent', '^0.1')
        ->requires('Accounts', '^0.1')
        ->requires('Subusers', '^0.1')
        ->requires('Web', '^0.1')
        ->requires('Resellers', '^0.1');

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(DnsZones::class);
        $services->singleton(DnsSync::class);
        $services->singleton(DnsActions::class);
        $services->singleton(DnsChecks::class);
        $services->singleton(DnsListeners::class);
        $services->bind(DnsPages::class);
        $services->bind(DnsApi::class);
        $services->bind(DnsCommands::class);
    });

    $module->access(static function (AccessCollector $access): void {
        $access->capability('server.dns', 'Set the server\'s nameservers, addresses and zone template, edit any zone, and make zones of the server\'s own.');
        $access->capability('dns.own', 'Set your own nameservers and the template of your accounts\' zones.');
    });

    $module->routes(static function (RouteCollector $routes): void {
        $id = '\d+';

        $editor = static function (RouteCollector $routes) use ($id): void {
            $routes->get('/{id}', [DnsPages::class, 'zone'])->name('.zone')->where('id', $id);
            $routes->post('/{id}/records', [DnsPages::class, 'addRecord'])->name('.records')->where('id', $id);
            $routes->post('/{id}/records/{record}', [DnsPages::class, 'updateRecord'])->name('.records.update')->where('id', $id)->where('record', $id);
            $routes->post('/{id}/records/{record}/delete', [DnsPages::class, 'deleteRecord'])->name('.records.delete')->where('id', $id)->where('record', $id);
            $routes->post('/{id}/reset', [DnsPages::class, 'reset'])->name('.reset')->where('id', $id);
        };

        $routes->group('/panel/dns', static function (RouteCollector $routes) use ($editor): void {
            $routes->get('', [DnsPages::class, 'index'])->name('');
            $editor($routes);
        }, name: 'user.dns', meta: ['can' => 'feature.dns']);

        // Administrators get the server's page, resellers their own.
        $routes->group('/admin/dns', static function (RouteCollector $routes): void {
            $routes->get('', [DnsPages::class, 'adminIndex'])->name('');
            $routes->post('/profile', [DnsPages::class, 'saveProfile'])->name('.profile');
        }, name: 'admin.dns', meta: ['can' => 'dns.own']);

        $routes->group('/admin/dns', static function (RouteCollector $routes) use ($id): void {
            $routes->post('/addresses', [DnsPages::class, 'saveAddresses'])->name('.addresses');
            $routes->post('/zones', [DnsPages::class, 'createZone'])->name('.zones');
            $routes->post('/zones/{id}/remove', [DnsPages::class, 'removeZone'])->name('.zones.remove')->where('id', $id);
        }, name: 'admin.dns', meta: ['can' => 'server.dns']);

        $routes->group('/admin/dns/zones', $editor, name: 'admin.dns', meta: ['can' => 'server.dns']);

        $zone = '[a-z0-9.-]{3,253}';

        $routes->group('/admin/api/v1/dns', static function (RouteCollector $routes) use ($id, $zone): void {
            $routes->get('', [DnsApi::class, 'settings'])->name('');
            $routes->put('', [DnsApi::class, 'saveSettings'])->name('.settings');
            $routes->get('/zones', [DnsApi::class, 'zones'])->name('.zones');
            $routes->post('/zones', [DnsApi::class, 'createZone'])->name('.zones.create');
            $routes->get('/zones/{zone}', [DnsApi::class, 'zone'])->name('.zone')->where('zone', $zone);
            $routes->delete('/zones/{zone}', [DnsApi::class, 'removeZone'])->name('.zones.remove')->where('zone', $zone);
            $routes->post('/zones/{zone}/records', [DnsApi::class, 'addRecord'])->name('.records')->where('zone', $zone);
            $routes->put('/zones/{zone}/records/{id}', [DnsApi::class, 'updateRecord'])->name('.records.update')->where('zone', $zone)->where('id', $id);
            $routes->delete('/zones/{zone}/records/{id}', [DnsApi::class, 'deleteRecord'])->name('.records.delete')->where('zone', $zone)->where('id', $id);
            $routes->post('/zones/{zone}/reset', [DnsApi::class, 'reset'])->name('.reset')->where('zone', $zone);
        }, name: 'api.dns', meta: ['can' => 'server.dns', 'csrf' => false, 'rate_limit' => '120/1m']);

        $routes->group('/admin/api/v1/dns/nameservers', static function (RouteCollector $routes): void {
            $routes->get('', [DnsApi::class, 'own'])->name('');
            $routes->put('', [DnsApi::class, 'saveOwn'])->name('.save');
        }, name: 'api.dns_own', meta: ['can' => 'dns.own', 'csrf' => false, 'rate_limit' => '60/1m']);

        // Changing an account's records is changing the account.
        $routes->group('/admin/api/v1/accounts/{username}/dns', static function (RouteCollector $routes) use ($id, $zone): void {
            $user = '[a-z][a-z0-9]{1,15}';
            $modify = ['can' => 'accounts.modify'];
            $routes->get('', [DnsApi::class, 'accountZones'])->name('')->where('username', $user);
            $routes->get('/{zone}', [DnsApi::class, 'accountZone'])->name('.zone')->where('username', $user)->where('zone', $zone);
            $routes->post('/{zone}/records', [DnsApi::class, 'accountAddRecord'])->name('.records')->where('username', $user)->where('zone', $zone)->meta($modify);
            $routes->put('/{zone}/records/{id}', [DnsApi::class, 'accountUpdateRecord'])->name('.records.update')->where('username', $user)->where('zone', $zone)->where('id', $id)->meta($modify);
            $routes->delete('/{zone}/records/{id}', [DnsApi::class, 'accountDeleteRecord'])->name('.records.delete')->where('username', $user)->where('zone', $zone)->where('id', $id)->meta($modify);
            $routes->post('/{zone}/reset', [DnsApi::class, 'accountReset'])->name('.reset')->where('username', $user)->where('zone', $zone)->meta($modify);
        }, name: 'api.account_dns', meta: ['can' => 'accounts.manage', 'csrf' => false, 'rate_limit' => '120/1m']);
    });

    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('dns:zones', [DnsCommands::class, 'zones'])
            ->describe('List the zones of an account, or every zone on the server.')
            ->argument('username', 'The account.', required: false);
        $commands->add('dns:records', [DnsCommands::class, 'records'])
            ->describe('List a zone\'s records, its nameservers and where it is delegated.')
            ->argument('zone', 'The zone, such as example.com.');
        $commands->add('dns:add', [DnsCommands::class, 'add'])
            ->describe('Add a record to a zone.')
            ->argument('zone', 'The zone.')
            ->argument('name', 'The record\'s name: @ for the zone itself, www, _dmarc…')
            ->argument('type', 'A, AAAA, CNAME, MX, TXT, SRV, CAA, NS or PTR.')
            ->argument('content', 'Its value, such as 203.0.113.5, or "10 mail.example.com" for MX.')
            ->option('ttl', 'Seconds resolvers may keep it (default 3600).');
        $commands->add('dns:set', [DnsCommands::class, 'set'])
            ->describe('Change a record, by its id in dns:records.')
            ->argument('zone', 'The zone.')
            ->argument('id', 'The record\'s id.')
            ->option('content', 'Its new value.')
            ->option('ttl', 'Its new TTL.')
            ->option('name', 'Its new name.');
        $commands->add('dns:remove', [DnsCommands::class, 'remove'])
            ->describe('Remove a record, by its id in dns:records.')
            ->argument('zone', 'The zone.')
            ->argument('id', 'The record\'s id.');
        $commands->add('dns:reset', [DnsCommands::class, 'reset'])
            ->describe('Put a zone back to its template: every record is replaced.')
            ->argument('zone', 'The zone.')
            ->flag('yes', 'Do not ask.');
        $commands->add('dns:zone-create', [DnsCommands::class, 'zoneCreate'])
            ->describe('Make a zone of the server\'s own: its hostname\'s domain, or a reverse zone.')
            ->argument('zone', 'Such as example.net, or 113.0.203.in-addr.arpa.')
            ->flag('empty', 'Without the template\'s records.');
        $commands->add('dns:zone-remove', [DnsCommands::class, 'zoneRemove'])
            ->describe('Remove a zone of the server\'s own.')
            ->argument('zone', 'The zone.');
        $commands->add('dns:settings', [DnsCommands::class, 'settings'])
            ->describe('Show or change the server\'s addresses and nameservers.')
            ->option('ip', 'The IPv4 address new records point at.')
            ->option('ip6', 'The IPv6 address; none to have none.')
            ->option('ns', 'The server\'s nameservers, comma-separated, such as ns1.example.net,ns2.example.net.');
        $commands->add('dns:nameservers', [DnsCommands::class, 'nameservers'])
            ->describe('Show or set a reseller\'s own nameservers.')
            ->argument('reseller', 'The reseller\'s login.')
            ->option('ns', 'Comma-separated; none to use the server\'s.');
        $commands->add('dns:template', [DnsCommands::class, 'template'])
            ->describe('Show the template new zones are made from; --set reads a new one from standard input.')
            ->option('reseller', 'A reseller\'s own template instead of the server\'s.')
            ->flag('set', 'Read the template from standard input: one record a line, "name ttl type content".')
            ->flag('default', 'Go back to the one above (the server\'s, or the built-in one).');
        $commands->add('dns:info', [DnsCommands::class, 'info'])
            ->describe('Show PowerDNS\'s state.');
        $commands->add('dns:settle', [DnsCommands::class, 'settle'])
            ->describe('Send zone changes and removals the agent has not answered yet.');
        $commands->add('dns:sync', [DnsCommands::class, 'sync'])
            ->describe('Make every account\'s zones match its domains and send every zone to the server again.')
            ->flag('all', 'Send zones that did not change too.');
    });

    $module->schedules(static function (ScheduleCollector $schedules): void {
        $schedules->command('dns:settle')->everyMinute()->withoutOverlapping(600);
    });

    $module->filter('panel.nav', static function (array $items): array {
        $items[] = ['area' => 'admin', 'section' => 'Panel.section_server', 'key' => 'dns', 'label' => 'Dns.title', 'icon' => 'globe', 'route' => 'admin.dns', 'can' => 'dns.own', 'order' => 45];
        $items[] = ['area' => 'user', 'section' => 'Panel.section_hosting', 'key' => 'dns', 'label' => 'Dns.title', 'icon' => 'server', 'route' => 'user.dns', 'can' => 'feature.dns', 'order' => 12];

        return $items;
    });

    $module->onBoot(static function (DnsListeners $listeners, HookEngine $hooks, FilterEngine $filters): void {
        $hooks->add('accounts.changed', [$listeners, 'accountChanged'], 30, 'Dns', 2);
        $hooks->add('web.changed', [$listeners, 'webChanged'], 10, 'Dns', 1);
        $filters->add('ssl.wildcard', [$listeners, 'wildcard'], 10, 'Dns', 3);
        $filters->add('accounts.show', [$listeners, 'accountShow'], 30, 'Dns', 3);
    });
};
