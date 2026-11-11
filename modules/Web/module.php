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
use App\Modules\Web\Bandwidth;
use App\Modules\Web\Commands\PhpCommands;
use App\Modules\Web\Commands\SiteCommands;
use App\Modules\Web\Commands\WebCommands;
use App\Modules\Web\DomainActions;
use App\Modules\Web\Domains;
use App\Modules\Web\Http\DomainApi;
use App\Modules\Web\Http\DomainPages;
use App\Modules\Web\Http\PhpVersionApi;
use App\Modules\Web\Http\PhpVersionPages;
use App\Modules\Web\Http\SiteApi;
use App\Modules\Web\Http\SitePages;
use App\Modules\Web\PhpSettings;
use App\Modules\Web\PhpVersions;
use App\Modules\Web\SiteFeatures;
use App\Modules\Web\WebListeners;
use App\Modules\Web\WebSettings;
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
        $services->singleton(SiteFeatures::class);
        $services->singleton(PhpSettings::class);
        $services->singleton(WebSettings::class);
        $services->singleton(WebSync::class);
        $services->singleton(PhpVersions::class);
        $services->singleton(Bandwidth::class);
        $services->singleton(DomainActions::class);
        $services->singleton(WebListeners::class);
        $services->bind(DomainPages::class);
        $services->bind(SitePages::class);
        $services->bind(SiteApi::class);
        $services->bind(DomainApi::class);
        $services->bind(WebCommands::class);
        $services->bind(SiteCommands::class);
        $services->bind(PhpVersionPages::class);
        $services->bind(PhpVersionApi::class);
        $services->bind(PhpCommands::class);
    });

    $module->access(static function (AccessCollector $access): void {
        $access->capability('server.php', 'Install and remove PHP versions and extensions, and choose the version new sites get.');
    });

    $module->routes(static function (RouteCollector $routes): void {
        $routes->group('/panel/domains', static function (RouteCollector $routes): void {
            $routes->get('', [DomainPages::class, 'index'])->name('');
            $routes->post('', [DomainPages::class, 'store'])->name('.store');
            $routes->post('/{id}/delete', [DomainPages::class, 'delete'])->name('.delete')->where('id', '\d+');
            $routes->get('/{id}', [SitePages::class, 'show'])->name('.show')->where('id', '\d+');
            $routes->post('/{id}', [SitePages::class, 'general'])->name('.update')->where('id', '\d+');
            $routes->post('/{id}/redirects', [SitePages::class, 'addRedirect'])->name('.redirects')->where('id', '\d+');
            $routes->post('/{id}/redirects/{redirect}/delete', [SitePages::class, 'removeRedirect'])->name('.redirects.delete')->where('id', '\d+')->where('redirect', '\d+');
            $routes->post('/{id}/errors', [SitePages::class, 'errorPages'])->name('.errors')->where('id', '\d+');
            $routes->post('/{id}/hotlink', [SitePages::class, 'hotlink'])->name('.hotlink')->where('id', '\d+');
            $routes->post('/{id}/protected', [SitePages::class, 'protect'])->name('.protected')->where('id', '\d+');
            $routes->post('/{id}/protected/{folder}/delete', [SitePages::class, 'unprotect'])->name('.protected.delete')->where('id', '\d+')->where('folder', '\d+');
            $routes->post('/{id}/protected/{folder}/logins', [SitePages::class, 'setLogin'])->name('.protected.logins')->where('id', '\d+')->where('folder', '\d+');
            $routes->post('/{id}/protected/{folder}/logins/{login}/delete', [SitePages::class, 'removeLogin'])->name('.protected.logins.delete')->where('id', '\d+')->where('folder', '\d+')->where('login', '[A-Za-z0-9._-]{1,32}');
        }, name: 'user.domains', meta: ['can' => 'feature.domains']);

        // The server's PHP versions (Admin panel).
        $routes->group('/admin/php', static function (RouteCollector $routes): void {
            $version = '[5-8][0-9]';
            $routes->get('', [PhpVersionPages::class, 'index'])->name('');
            $routes->post('/default', [PhpVersionPages::class, 'setDefault'])->name('.default');
            $routes->post('/{version}/install', [PhpVersionPages::class, 'install'])->name('.install')->where('version', $version);
            $routes->post('/{version}/remove', [PhpVersionPages::class, 'remove'])->name('.remove')->where('version', $version);
            $routes->post('/{version}/extensions', [PhpVersionPages::class, 'extensions'])->name('.extensions')->where('version', $version);
        }, name: 'admin.php', meta: ['can' => 'server.php']);

        $routes->group('/admin/api/v1/php', static function (RouteCollector $routes): void {
            $version = '[5-8]\.?[0-9]';
            $routes->get('', [PhpVersionApi::class, 'index'])->name('');
            $routes->put('/default', [PhpVersionApi::class, 'setDefault'])->name('.default');
            $routes->get('/jobs/{id}', [PhpVersionApi::class, 'job'])->name('.job')->where('id', '\d+');
            $routes->post('/{version}', [PhpVersionApi::class, 'install'])->name('.install')->where('version', $version);
            $routes->delete('/{version}', [PhpVersionApi::class, 'remove'])->name('.remove')->where('version', $version);
            $routes->put('/{version}/extensions', [PhpVersionApi::class, 'extensions'])->name('.extensions')->where('version', $version);
        }, name: 'api.php_versions', meta: ['can' => 'server.php', 'csrf' => false, 'rate_limit' => '60/1m']);

        $routes->get('/admin/api/v1/accounts/{username}/bandwidth', [PhpVersionApi::class, 'bandwidth'])->name('api.bandwidth')
            ->where('username', '[a-z][a-z0-9]{1,15}')->meta(['can' => 'accounts.manage', 'csrf' => false, 'rate_limit' => '120/1m']);

        $routes->group('/panel/php', static function (RouteCollector $routes): void {
            $routes->get('', [SitePages::class, 'php'])->name('');
            $routes->post('/{version}', [SitePages::class, 'savePhp'])->name('.save')->where('version', '[5-8][0-9]');
        }, name: 'user.php', meta: ['can' => 'feature.php_settings']);

        // Bearer tokens only, like the rest of the API. Changing an
        // account's domains is changing the account.
        $routes->group('/admin/api/v1/accounts/{username}/domains', static function (RouteCollector $routes): void {
            $user = '[a-z][a-z0-9]{1,15}';
            $domain = '[a-z0-9.-]{4,253}';
            $routes->get('', [DomainApi::class, 'index'])->name('')->where('username', $user);
            $routes->post('', [DomainApi::class, 'store'])->name('.store')->where('username', $user)->meta(['can' => 'accounts.modify']);
            $routes->put('/{domain}', [DomainApi::class, 'update'])->name('.update')->where('username', $user)->where('domain', $domain)->meta(['can' => 'accounts.modify']);
            $routes->delete('/{domain}', [DomainApi::class, 'destroy'])->name('.destroy')->where('username', $user)->where('domain', $domain)->meta(['can' => 'accounts.modify']);

            // Each site's features (SiteApi).
            $id = '\d+';
            $login = '[A-Za-z0-9._-]{1,32}';
            $modify = ['can' => 'accounts.modify'];
            $routes->get('/{domain}', [SiteApi::class, 'show'])->name('.show')->where('username', $user)->where('domain', $domain);
            $routes->put('/{domain}/error-pages', [SiteApi::class, 'errorPages'])->name('.error_pages')->where('username', $user)->where('domain', $domain)->meta($modify);
            $routes->put('/{domain}/hotlink', [SiteApi::class, 'hotlink'])->name('.hotlink')->where('username', $user)->where('domain', $domain)->meta($modify);
            $routes->delete('/{domain}/hotlink', [SiteApi::class, 'hotlinkOff'])->name('.hotlink_off')->where('username', $user)->where('domain', $domain)->meta($modify);
            $routes->post('/{domain}/redirects', [SiteApi::class, 'addRedirect'])->name('.redirects')->where('username', $user)->where('domain', $domain)->meta($modify);
            $routes->delete('/{domain}/redirects/{id}', [SiteApi::class, 'removeRedirect'])->name('.redirects.delete')->where('username', $user)->where('domain', $domain)->where('id', $id)->meta($modify);
            $routes->post('/{domain}/protected', [SiteApi::class, 'protect'])->name('.protected')->where('username', $user)->where('domain', $domain)->meta($modify);
            $routes->delete('/{domain}/protected/{id}', [SiteApi::class, 'unprotect'])->name('.protected.delete')->where('username', $user)->where('domain', $domain)->where('id', $id)->meta($modify);
            $routes->put('/{domain}/protected/{id}/logins/{login}', [SiteApi::class, 'setLogin'])->name('.protected.login')->where('username', $user)->where('domain', $domain)->where('id', $id)->where('login', $login)->meta($modify);
            $routes->delete('/{domain}/protected/{id}/logins/{login}', [SiteApi::class, 'removeLogin'])->name('.protected.logout')->where('username', $user)->where('domain', $domain)->where('id', $id)->where('login', $login)->meta($modify);
        }, name: 'api.domains', meta: ['can' => 'accounts.manage', 'csrf' => false, 'rate_limit' => '120/1m']);

        $routes->group('/admin/api/v1/accounts/{username}/php', static function (RouteCollector $routes): void {
            $user = '[a-z][a-z0-9]{1,15}';
            $routes->get('', [SiteApi::class, 'php'])->name('')->where('username', $user);
            $routes->put('/{version}', [SiteApi::class, 'savePhp'])->name('.save')->where('username', $user)->where('version', '[5-8][0-9]')->meta(['can' => 'accounts.modify']);
        }, name: 'api.php', meta: ['can' => 'accounts.manage', 'csrf' => false, 'rate_limit' => '120/1m']);
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
            ->describe('Change a domain\'s folder, PHP version, wildcard or www. choice.')
            ->argument('username', 'The account.')
            ->argument('domain', 'The domain name.')
            ->option('docroot', 'Its folder: public_html or a folder inside it.')
            ->option('php', 'PHP version, such as 8.3.')
            ->option('wildcard', 'yes or no.')
            ->option('canonical', 'www (send visitors to www.), bare (away from it) or either.');
        $commands->add('domains:show', [SiteCommands::class, 'show'])
            ->describe('Show a domain\'s settings, redirects, error pages and protected folders.')
            ->argument('username', 'The account.')
            ->argument('domain', 'The domain name.');
        $commands->add('domains:redirect', [SiteCommands::class, 'redirect'])
            ->describe('Redirect a path, and everything under it, to another address (permanent unless --temporary).')
            ->argument('username', 'The account.')
            ->argument('domain', 'The domain name.')
            ->argument('path', 'The path, such as /old; / for the whole site.')
            ->argument('to', 'The address, starting with http:// or https://.')
            ->flag('temporary', 'A temporary (302) redirect.');
        $commands->add('domains:unredirect', [SiteCommands::class, 'unredirect'])
            ->describe('Stop redirecting a path.')
            ->argument('username', 'The account.')
            ->argument('domain', 'The domain name.')
            ->argument('path', 'The path.');
        $commands->add('domains:error-page', [SiteCommands::class, 'errorPage'])
            ->describe('Show your own page for an error status, or the standard one when no page is given.')
            ->argument('username', 'The account.')
            ->argument('domain', 'The domain name.')
            ->argument('code', 'One of ' . implode(', ', SiteFeatures::ERROR_CODES) . '.')
            ->argument('page', 'A path on the site, such as /errors/404.html.', required: false);
        $commands->add('domains:hotlink', [SiteCommands::class, 'hotlink'])
            ->describe('Turn hotlink protection on (or off with --off).')
            ->argument('username', 'The account.')
            ->argument('domain', 'The domain name.')
            ->option('extensions', 'File kinds, comma-separated (default: ' . implode(',', SiteFeatures::HOTLINK_DEFAULT) . ').')
            ->option('allow', 'Other sites that may show them, comma-separated.')
            ->flag('off', 'Turn it off.');
        $commands->add('domains:protect', [SiteCommands::class, 'protect'])
            ->describe('Make a folder of the site ask for a password. With --login, its password is read from standard input.')
            ->argument('username', 'The account.')
            ->argument('domain', 'The domain name.')
            ->argument('folder', 'The folder inside the site; none for the whole site.', required: false)
            ->option('realm', 'What the password prompt says.')
            ->option('login', 'A first login.');
        $commands->add('domains:unprotect', [SiteCommands::class, 'unprotect'])
            ->describe('Remove the password from a folder.')
            ->argument('username', 'The account.')
            ->argument('domain', 'The domain name.')
            ->argument('folder', 'The folder; none for the whole site.', required: false);
        $commands->add('domains:protect-login', [SiteCommands::class, 'protectLogin'])
            ->describe('Add a login to a protected folder or change its password (read from standard input), or --remove it.')
            ->argument('username', 'The account.')
            ->argument('domain', 'The domain name.')
            ->argument('folder', 'The folder; / for the whole site.')
            ->argument('login', 'The login name.')
            ->flag('remove', 'Remove the login.');
        $commands->add('php:settings', [SiteCommands::class, 'phpSettings'])
            ->describe('Show an account\'s php.ini settings for a PHP version, or change them.')
            ->argument('username', 'The account.')
            ->argument('version', 'The PHP version, such as 8.3.')
            ->option('set', 'Comma-separated directive=value pairs; an empty value is PHP\'s default.')
            ->flag('clear', 'Put every setting back to PHP\'s default first.');
        $commands->add('domains:remove', [WebCommands::class, 'remove'])
            ->describe('Remove a domain from an account (not its main domain).')
            ->argument('username', 'The account.')
            ->argument('domain', 'The domain name.');
        $commands->add('php:list', [PhpCommands::class, 'list'])
            ->describe('List the PHP versions the panel offers, which are installed, and their extensions.');
        $commands->add('php:install', [PhpCommands::class, 'install'])
            ->describe('Install a PHP version with the usual extensions, and wait for it.')
            ->argument('version', 'Such as 8.4.')
            ->flag('no-wait', 'Start it and return; web:settle follows it.');
        $commands->add('php:remove', [PhpCommands::class, 'remove'])
            ->describe('Remove a PHP version no site uses.')
            ->argument('version', 'Such as 8.1.')
            ->flag('no-wait', 'Start it and return; web:settle follows it.');
        $commands->add('php:extensions', [PhpCommands::class, 'extensions'])
            ->describe('Add or remove extensions of an installed PHP version.')
            ->argument('version', 'Such as 8.3.')
            ->option('add', 'Comma-separated, such as redis,imagick.')
            ->option('remove', 'Comma-separated.')
            ->flag('no-wait', 'Start it and return; web:settle follows it.');
        $commands->add('php:default', [PhpCommands::class, 'setDefault'])
            ->describe('Choose the PHP version new sites get.')
            ->argument('version', 'An installed version, such as 8.3.');
        $commands->add('web:traffic', [PhpCommands::class, 'traffic'])
            ->describe('Count bandwidth from the access logs, and stop or restart sites as each package says.');
        $commands->add('web:bandwidth', [PhpCommands::class, 'bandwidth'])
            ->describe('Show an account\'s bandwidth this month.')
            ->argument('username', 'The account.');
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
        $schedules->command('web:traffic')->everyFifteenMinutes()->withoutOverlapping(900);
    });

    $module->filter('panel.nav', static function (array $items): array {
        $items[] = ['area' => 'admin', 'section' => 'Panel.section_server', 'key' => 'php_versions', 'label' => 'Web.php_versions', 'icon' => 'code', 'route' => 'admin.php', 'can' => 'server.php', 'order' => 30];
        $items[] = ['area' => 'user', 'section' => 'Panel.section_hosting', 'key' => 'domains', 'label' => 'Web.domains', 'icon' => 'globe', 'route' => 'user.domains', 'can' => 'feature.domains', 'order' => 10];
        $items[] = ['area' => 'user', 'section' => 'Panel.section_hosting', 'key' => 'php', 'label' => 'Web.php_settings', 'icon' => 'code', 'route' => 'user.php', 'can' => 'feature.php_settings', 'order' => 20];

        return $items;
    });

    $module->onBoot(static function (WebListeners $listeners, HookEngine $hooks, FilterEngine $filters): void {
        $hooks->add('accounts.changed', [$listeners, 'accountChanged'], 10, 'Web', 2);
        $hooks->add('packages.updated', [$listeners, 'packageUpdated'], 10, 'Web', 1);
        $filters->add('accounts.domain_taken', [$listeners, 'domainTaken'], 10, 'Web', 2);
        $filters->add('accounts.show', [$listeners, 'accountShow'], 10, 'Web', 3);
        $filters->add('panel.dashboard', [$listeners, 'dashboard'], 20, 'Web', 3);
    });
};
