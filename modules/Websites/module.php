<?php

declare(strict_types=1);

use App\Engine\Auth\AccessCollector;
use App\Engine\Auth\Identity;
use App\Engine\Cli\CommandCollector;
use App\Engine\Container\Container;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\Scheduler\ScheduleCollector;
use App\Modules\Hosting\Accounts\Account;
use App\Modules\Operations\Store\Operation;
use App\Modules\Servers\Store\Server;
use App\Modules\Shared\Ui\Navigation;
use App\Modules\Websites\Commands\WebsiteCommands;
use App\Modules\Websites\Http\WebsitePanels;
use App\Modules\Websites\Http\WebsitesApi;
use App\Modules\Websites\Http\WebsitesPage;
use App\Modules\Websites\Http\WebsiteViews;
use App\Modules\Websites\Sites\CertificateStore;
use App\Modules\Websites\Sites\WebsiteAccess;
use App\Modules\Websites\Sites\WebsiteActions;
use App\Modules\Websites\Sites\WebsiteStore;

/**
 * Websites (ADR-0014, milestone M6b): a hosting account's websites, served
 * by the server's own Nginx from managed settings: domains, a folder in the
 * account's web/, PHP on or off, index files, redirects and headers. The
 * agent's privileged half renders, tests (nginx -t), applies, probes and
 * rolls back; it never overwrites a hand edit unless an administrator
 * restores LPanel's version.
 *
 * Every website id a person sends goes through WebsiteAccess (THREAT_MODEL.md test 1).
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Websites')
        ->version('0.1.0')
        ->description('Websites of hosting accounts, served through the server\'s Nginx from managed settings.')
        ->requires('Identity')
        ->requires('Servers')
        ->requires('Operations')
        ->requires('Hosting')
        ->requires('Audit');

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(WebsiteStore::class);
        $services->singleton(CertificateStore::class);
        $services->singleton(WebsiteAccess::class);
        $services->singleton(WebsiteActions::class);
        $services->singleton(WebsiteViews::class);
        $services->singleton(WebsitePanels::class);
    });

    $module->access(static function (AccessCollector $access): void {
        $access->capability('websites.own', 'See the websites of the hosting accounts you own.');
        $access->capability('websites.configure', 'Change the managed settings (PHP, index files, redirects, headers) of your own websites.');
        $access->capability('websites.read', 'See every website.');
        $access->capability('websites.manage', 'Create, suspend, delete and restore websites, and change any website\'s settings.');
    });

    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('websites:create', [WebsiteCommands::class, 'create'])
            ->describe('Create a website in a hosting account.')
            ->argument('account', 'The account\'s id, or its system user.')
            ->argument('domains', 'Its domain names, the primary one first, separated by commas.')
            ->option('root', 'Its folder under web/ (default: the primary domain).')
            ->option('timeout', 'With --wait, seconds to wait (default 120).')
            ->flag('static', 'PHP off: static files only.')
            ->flag('wait', 'Wait until it is served; exit 0 only if it became active.');
        $commands->add('websites:list', [WebsiteCommands::class, 'list'])
            ->describe('List websites.')
            ->option('status', 'current (default), active, suspended, busy, failed or deleted.')
            ->flag('json', 'As JSON.');
        $commands->add('websites:suspend', [WebsiteCommands::class, 'suspend'])
            ->describe('Suspend a website: every request is answered 503. Its files stay.')
            ->argument('website', 'The website\'s id, or one of its domains.')
            ->option('timeout', 'With --wait, seconds to wait (default 120).')
            ->flag('wait', 'Wait until it is suspended.');
        $commands->add('websites:unsuspend', [WebsiteCommands::class, 'unsuspend'])
            ->describe('Unsuspend a website.')
            ->argument('website', 'The website\'s id, or one of its domains.')
            ->option('timeout', 'With --wait, seconds to wait (default 120).')
            ->flag('wait', 'Wait until it is served.');
        $commands->add('websites:restore', [WebsiteCommands::class, 'restore'])
            ->describe('Replace a hand-edited Nginx file with LPanel\'s version of the website.')
            ->argument('website', 'The website\'s id, or one of its domains.')
            ->option('timeout', 'With --wait, seconds to wait (default 120).')
            ->flag('wait', 'Wait until it is done.');
        $commands->add('websites:delete', [WebsiteCommands::class, 'delete'])
            ->describe('Stop serving a website. Its files stay in the account\'s web folder.')
            ->argument('website', 'The website\'s id, or one of its domains.')
            ->option('confirm', 'The website\'s primary domain, typed again.')
            ->option('timeout', 'With --wait, seconds to wait (default 120).')
            ->flag('wait', 'Wait until it is deleted.');
        $commands->add('websites:certificate', [WebsiteCommands::class, 'certificate'])
            ->describe('Get a website a certificate now (or renew it), or with --remove serve it over HTTP only again.')
            ->argument('website', 'The website\'s id, or one of its domains.')
            ->option('timeout', 'With --wait, seconds to wait (default 300).')
            ->flag('remove', 'Remove its certificate; LPanel stops asking for one on its own.')
            ->flag('wait', 'Wait until it is done; exit 0 only if it worked.');
        $commands->add('certificates:renew', [WebsiteCommands::class, 'renew'])
            ->describe('Ask for the certificates that are due: first ones, renewals and retries (the scheduler runs it every five minutes).')
            ->option('limit', 'At most this many in one run (default 20).');
        $commands->add('websites:check', [WebsiteCommands::class, 'check'])
            ->describe('Ask servers to compare their websites\' Nginx files with what LPanel wrote.')
            ->argument('server', 'One server\'s id or name (default: every server with websites).', required: false);
    });

    $module->schedules(static function (ScheduleCollector $schedules): void {
        // Finds hand edits (ADR-0014): reported, reloaded if nginx -t passes, never overwritten.
        $schedules->command('websites:check')->hourly();
        // First certificates within minutes, renewals when due, retries after their wait (ADR-0015).
        $schedules->command('certificates:renew')->everyFiveMinutes();
    });

    $module->routes(static function (RouteCollector $routes): void {
        $ulid = '[0-9A-HJKMNP-TV-Z]{26}';

        // websites.own is the floor; WebsiteAccess decides which website, and
        // WebsiteActions checks websites.manage or websites.configure itself,
        // auditing every refusal.
        $routes->get('/websites', [WebsitesPage::class, 'index'])->name('websites.index')->meta(['can' => 'websites.own']);
        $routes->get('/websites/new', [WebsitesPage::class, 'create'])->name('websites.create')->meta(['can' => 'websites.manage']);
        $routes->post('/websites', [WebsitesPage::class, 'store'])->name('websites.store')->meta(['can' => 'websites.manage', 'rate_limit' => '30/1m']);
        $routes->post('/websites/check', [WebsitesPage::class, 'check'])->name('websites.check')->meta(['can' => 'websites.manage', 'rate_limit' => '30/1m']);
        $routes->get('/websites/{id}', [WebsitesPage::class, 'show'])->name('websites.show')->where('id', $ulid)->meta(['can' => 'websites.own']);
        foreach (['settings', 'suspend', 'unsuspend', 'delete', 'retry', 'restore'] as $action) {
            $routes->post('/websites/{id}/' . $action, [WebsitesPage::class, $action])->name('websites.' . $action)->where('id', $ulid)->meta(['can' => 'websites.own', 'rate_limit' => '30/1m']);
        }
        // Certificates (ADR-0015): an authority limits how often it issues, so these are slower.
        $routes->post('/websites/{id}/certificate', [WebsitesPage::class, 'certify'])->name('websites.certify')->where('id', $ulid)->meta(['can' => 'websites.own', 'rate_limit' => '10/10m']);
        $routes->post('/websites/{id}/certificate/remove', [WebsitesPage::class, 'uncertify'])->name('websites.uncertify')->where('id', $ulid)->meta(['can' => 'websites.own', 'rate_limit' => '10/10m']);

        $api = static function (RouteCollector $routes) use ($ulid): void {
            $routes->get('/websites', [WebsitesApi::class, 'index'])->name('websites.index')->meta(['can' => 'websites.own']);
            $routes->post('/websites', [WebsitesApi::class, 'store'])->name('websites.store')->meta(['can' => 'websites.own', 'rate_limit' => '30/1m']);
            $routes->get('/websites/{id}', [WebsitesApi::class, 'show'])->name('websites.show')->where('id', $ulid)->meta(['can' => 'websites.own']);
            $routes->put('/websites/{id}/settings', [WebsitesApi::class, 'settings'])->name('websites.settings')->where('id', $ulid)->meta(['can' => 'websites.own', 'rate_limit' => '30/1m']);
            $routes->delete('/websites/{id}', [WebsitesApi::class, 'destroy'])->name('websites.destroy')->where('id', $ulid)->meta(['can' => 'websites.own', 'rate_limit' => '30/1m']);
            foreach (['suspend', 'unsuspend', 'retry', 'restore'] as $action) {
                $routes->post('/websites/{id}/' . $action, [WebsitesApi::class, $action])->name('websites.' . $action)->where('id', $ulid)->meta(['can' => 'websites.own', 'rate_limit' => '30/1m']);
            }
            $routes->post('/websites/{id}/certificate', [WebsitesApi::class, 'certify'])->name('websites.certify')->where('id', $ulid)->meta(['can' => 'websites.own', 'rate_limit' => '10/10m']);
            $routes->delete('/websites/{id}/certificate', [WebsitesApi::class, 'uncertify'])->name('websites.uncertify')->where('id', $ulid)->meta(['can' => 'websites.own', 'rate_limit' => '10/10m']);
        };
        // Cookie-authenticated, so CSRF stays on (ARCHITECTURE.md §4).
        $routes->group('/api/v1', $api, name: 'api.v1.', meta: ['api' => true]);
        $routes->group('/api/token/v1', $api, name: 'api.token.v1.', meta: ['api' => true, 'csrf' => false, 'auth' => true, 'rate_limit' => '120/1m']);
    });

    $module->onBoot(static function (HookEngine $hooks, FilterEngine $filters, Navigation $nav, Container $container): void {
        // A website's operation, or a server's check, settles in the operation's own transaction.
        $hooks->add('operations.finished', static function (Operation $op) use ($container): void {
            $container->get(WebsiteActions::class)->settle($op);
        }, 10, 'Websites');

        // An account is deleted only once its websites are; it is suspended
        // or unsuspended only while LPanel may rewrite every website's file.
        $filters->add('hosting.account.blockers', static function (array $blockers, Account $account, string $action) use ($container): array {
            $websites = $container->get(WebsiteStore::class);

            if ($action === 'delete') {
                $n = $websites->countForAccount($account->id);

                return $n === 0 ? $blockers : [...$blockers, \sprintf('The account still has %d website%s. Delete %s first.', $n, $n === 1 ? '' : 's', $n === 1 ? 'it' : 'them')];
            }

            if ($action === 'suspend' || $action === 'unsuspend') {
                foreach ($websites->page(null, 'current', accountId: $account->id, editedOnly: true) as $edited) {
                    $blockers[] = \sprintf('The Nginx file of %s was edited outside LPanel. Restore LPanel\'s version first.', $edited->domain);
                }
            }

            return $blockers;
        }, 10, 'Websites');

        $filters->add('hosting.account.panels', static fn(array $panels, Account $account, Identity $identity): array => $container->get(WebsitePanels::class)->account($panels, $account, $identity), 10, 'Websites');
        $filters->add('servers.detail.panels', static fn(array $panels, Server $server, Identity $identity): array => $container->get(WebsitePanels::class)->server($panels, $server, $identity), 20, 'Websites');

        $nav->add('hosting', 'websites', 'Websites', 'websites.index', 'globe', can: 'websites.own', order: 10);
    });
};
