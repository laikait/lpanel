<?php

declare(strict_types=1);

use App\Engine\Cli\CommandCollector;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\Scheduler\ScheduleCollector;
use App\Modules\Web\Commands\WebCommands;
use App\Modules\Web\DomainActions;
use App\Modules\Web\Domains;
use App\Modules\Web\Http\DomainApi;
use App\Modules\Web\Http\DomainPages;
use App\Modules\Web\WebListeners;
use App\Modules\Web\WebSync;

/**
 * Websites: each account's domains (main, addon, sub and alias), the folder
 * and PHP version each site uses, and keeping the web server in step with
 * them through the agent (web.apply).
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Web')
        ->version('0.1.0')
        ->description('Domains and websites: Apache sites and PHP-FPM pools for each account.');

    $module->requires('Panel', '^0.1')
        ->requires('Audit', '^0.1')
        ->requires('Identity', '^0.1')
        ->requires('Agent', '^0.1')
        ->requires('Packages', '^0.1')
        ->requires('Accounts', '^0.1')
        ->requires('Subusers', '^0.1');

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(Domains::class);
        $services->singleton(WebSync::class);
        $services->singleton(DomainActions::class);
        $services->singleton(WebListeners::class);
        $services->bind(DomainPages::class);
        $services->bind(DomainApi::class);
        $services->bind(WebCommands::class);
    });

    $module->routes(static function (RouteCollector $routes): void {
        $routes->group('/panel/domains', static function (RouteCollector $routes): void {
            $routes->get('', [DomainPages::class, 'index'])->name('');
            $routes->post('', [DomainPages::class, 'store'])->name('.store');
            $routes->post('/{id}', [DomainPages::class, 'update'])->name('.update')->where('id', '\d+');
            $routes->post('/{id}/delete', [DomainPages::class, 'delete'])->name('.delete')->where('id', '\d+');
        }, name: 'user.domains', meta: ['can' => 'feature.domains']);

        // Bearer tokens only, like the rest of the API. Changing an
        // account's domains is changing the account.
        $routes->group('/admin/api/v1/accounts/{username}/domains', static function (RouteCollector $routes): void {
            $user = '[a-z][a-z0-9]{1,15}';
            $domain = '[a-z0-9.-]{4,253}';
            $routes->get('', [DomainApi::class, 'index'])->name('')->where('username', $user);
            $routes->post('', [DomainApi::class, 'store'])->name('.store')->where('username', $user)->meta(['can' => 'accounts.modify']);
            $routes->put('/{domain}', [DomainApi::class, 'update'])->name('.update')->where('username', $user)->where('domain', $domain)->meta(['can' => 'accounts.modify']);
            $routes->delete('/{domain}', [DomainApi::class, 'destroy'])->name('.destroy')->where('username', $user)->where('domain', $domain)->meta(['can' => 'accounts.modify']);
        }, name: 'api.domains', meta: ['can' => 'accounts.manage', 'csrf' => false, 'rate_limit' => '120/1m']);
    });

    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('domains:list', [WebCommands::class, 'list'])
            ->describe('List an account\'s domains.')
            ->argument('username', 'The account.');
        $commands->add('domains:add', [WebCommands::class, 'add'])
            ->describe('Add a domain to an account.')
            ->argument('username', 'The account.')
            ->argument('domain', 'The domain name.')
            ->option('type', 'addon (default), sub or alias.')
            ->option('docroot', 'Its folder inside the home (default: public_html/<name>).')
            ->option('php', 'PHP version, such as 8.3 (default: the newest installed).')
            ->option('target', 'For an alias: the domain it points to.')
            ->flag('wildcard', 'Also answer every subdomain of it.');
        $commands->add('domains:set', [WebCommands::class, 'set'])
            ->describe('Change a domain\'s folder, PHP version or wildcard.')
            ->argument('username', 'The account.')
            ->argument('domain', 'The domain name.')
            ->option('docroot', 'Its folder inside the home.')
            ->option('php', 'PHP version, such as 8.3.')
            ->option('wildcard', 'yes or no.');
        $commands->add('domains:remove', [WebCommands::class, 'remove'])
            ->describe('Remove a domain from an account (not its main domain).')
            ->argument('username', 'The account.')
            ->argument('domain', 'The domain name.');
        $commands->add('web:info', [WebCommands::class, 'info'])
            ->describe('Show the web server and the PHP versions installed.');
        $commands->add('web:settle', [WebCommands::class, 'settle'])
            ->describe('Send site changes the agent has not answered yet.');
        $commands->add('web:sync', [WebCommands::class, 'sync'])
            ->describe('Send an account\'s sites to the server again, as the panel has them.')
            ->argument('username', 'The account.');
    });

    $module->schedules(static function (ScheduleCollector $schedules): void {
        $schedules->command('web:settle')->everyMinute()->withoutOverlapping(600);
    });

    $module->filter('panel.nav', static function (array $items): array {
        $items[] = ['area' => 'user', 'section' => 'Panel.section_hosting', 'key' => 'domains', 'label' => 'Web.domains', 'icon' => 'globe', 'route' => 'user.domains', 'can' => 'feature.domains', 'order' => 10];

        return $items;
    });

    $module->onBoot(static function (WebListeners $listeners, HookEngine $hooks, FilterEngine $filters): void {
        $hooks->add('accounts.changed', [$listeners, 'accountChanged'], 10, 'Web', 2);
        $filters->add('accounts.domain_taken', [$listeners, 'domainTaken'], 10, 'Web', 2);
        $filters->add('accounts.show', [$listeners, 'accountShow'], 10, 'Web', 3);
        $filters->add('panel.dashboard', [$listeners, 'dashboard'], 20, 'Web', 3);
    });
};
