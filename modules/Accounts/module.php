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
use App\Modules\Accounts\AccountListeners;
use App\Modules\Accounts\AccountRules;
use App\Modules\Accounts\Accounts;
use App\Modules\Accounts\AccountView;
use App\Modules\Accounts\Commands\AccountCommands;
use App\Modules\Accounts\Commands\DevSeedHosting;
use App\Modules\Accounts\Http\AccountApi;
use App\Modules\Accounts\Http\AccountPages;
use App\Modules\Accounts\Provisioner;

/**
 * Hosting accounts: the Linux user, home folder, disk quota and shell made
 * through the agent, and the owner's User panel login.
 *
 * Every change goes through Provisioner, which records the agent operation
 * before sending it so that it is carried out exactly once (see there).
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Accounts')
        ->version('0.1.0')
        ->description('Hosting accounts: create, change package, suspend, unsuspend and terminate.');

    $module->requires('Panel', '^0.1')
        ->requires('Audit', '^0.1')
        ->requires('Identity', '^0.1')
        ->requires('Agent', '^0.1')
        ->requires('Packages', '^0.1');

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(Accounts::class);
        $services->singleton(AccountRules::class);
        $services->singleton(AccountView::class);
        $services->singleton(Provisioner::class);
        $services->singleton(AccountListeners::class);
        $services->bind(AccountPages::class);
        $services->bind(AccountApi::class);
        $services->bind(AccountCommands::class);
        $services->bind(DevSeedHosting::class);
    });

    $module->access(static function (AccessCollector $access): void {
        $access->capability('accounts.manage', 'See hosting accounts (yours, or all with accounts.all).');
        $access->capability('accounts.all', 'Manage every hosting account, not only your own.');
        $access->capability('accounts.create', 'Create hosting accounts.');
        $access->capability('accounts.modify', 'Change an account\'s package and its owner\'s password.');
        $access->capability('accounts.suspend', 'Suspend and unsuspend hosting accounts.');
        $access->capability('accounts.terminate', 'Terminate hosting accounts.');
        $access->capability('accounts.login_as', 'Sign in to the User panel as an account\'s owner.');
    });

    $module->routes(static function (RouteCollector $routes): void {
        $routes->group('/admin/accounts', static function (RouteCollector $routes): void {
            $routes->get('', [AccountPages::class, 'index'])->name('');
            $routes->get('/new', [AccountPages::class, 'new'])->name('.new')->meta(['can' => 'accounts.create']);
            $routes->post('', [AccountPages::class, 'store'])->name('.store')->meta(['can' => 'accounts.create']);
            $routes->get('/{id}', [AccountPages::class, 'show'])->name('.show')->where('id', '\d+');
            $routes->post('/{id}/package', [AccountPages::class, 'package'])->name('.package')->where('id', '\d+')->meta(['can' => 'accounts.modify']);
            $routes->post('/{id}/suspend', [AccountPages::class, 'suspend'])->name('.suspend')->where('id', '\d+')->meta(['can' => 'accounts.suspend']);
            $routes->post('/{id}/unsuspend', [AccountPages::class, 'unsuspend'])->name('.unsuspend')->where('id', '\d+')->meta(['can' => 'accounts.suspend']);
            $routes->post('/{id}/password', [AccountPages::class, 'password'])->name('.password')->where('id', '\d+')->meta(['can' => 'accounts.modify']);
            $routes->post('/{id}/terminate', [AccountPages::class, 'terminate'])->name('.terminate')->where('id', '\d+')->meta(['can' => 'accounts.terminate']);
        }, name: 'admin.accounts', meta: ['can' => 'accounts.manage']);

        // Bearer tokens only: csrf => false makes the framework ignore the
        // session cookie on these routes.
        $routes->group('/admin/api/v1/accounts', static function (RouteCollector $routes): void {
            $user = '[a-z][a-z0-9]{1,15}';
            $routes->get('', [AccountApi::class, 'index'])->name('');
            $routes->post('', [AccountApi::class, 'create'])->name('.create')->meta(['can' => 'accounts.create']);
            $routes->get('/{username}', [AccountApi::class, 'show'])->name('.show')->where('username', $user);
            $routes->put('/{username}/package', [AccountApi::class, 'package'])->name('.package')->where('username', $user)->meta(['can' => 'accounts.modify']);
            $routes->post('/{username}/suspend', [AccountApi::class, 'suspend'])->name('.suspend')->where('username', $user)->meta(['can' => 'accounts.suspend']);
            $routes->post('/{username}/unsuspend', [AccountApi::class, 'unsuspend'])->name('.unsuspend')->where('username', $user)->meta(['can' => 'accounts.suspend']);
            $routes->delete('/{username}', [AccountApi::class, 'terminate'])->name('.terminate')->where('username', $user)->meta(['can' => 'accounts.terminate']);
        }, name: 'api.accounts', meta: ['can' => 'accounts.manage', 'csrf' => false, 'rate_limit' => '120/1m']);
    });

    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('accounts:list', [AccountCommands::class, 'list'])
            ->describe('List every hosting account.')
            ->option('status', 'Only accounts with this status.');
        $commands->add('accounts:create', [AccountCommands::class, 'create'])
            ->describe('Create a hosting account; the owner\'s password is read from standard input.')
            ->argument('username', 'The Linux user and login name: 2–16 lowercase letters and digits.')
            ->argument('domain', 'The account\'s main domain.')
            ->argument('package', 'The package, by name or id.')
            ->option('owner', 'The reseller who owns it (default: the server).')
            ->option('email', 'The owner\'s email address.')
            ->option('name', 'The owner\'s name.');
        $commands->add('accounts:package', [AccountCommands::class, 'package'])
            ->describe('Move an account to another package.')
            ->argument('username', 'The account.')
            ->argument('package', 'The package, by name or id.');
        $commands->add('accounts:suspend', [AccountCommands::class, 'suspend'])
            ->describe('Suspend an account: its logins stop and its processes end.')
            ->argument('username', 'The account.')
            ->option('reason', 'Why, for the record.');
        $commands->add('accounts:unsuspend', [AccountCommands::class, 'unsuspend'])
            ->describe('Unsuspend an account.')
            ->argument('username', 'The account.');
        $commands->add('accounts:terminate', [AccountCommands::class, 'terminate'])
            ->describe('Remove an account, its home folder and everything in it.')
            ->argument('username', 'The account.')
            ->flag('yes', 'Really do it.');
        $commands->add('accounts:settle', [AccountCommands::class, 'settle'])
            ->describe('Finish account changes the agent has not answered yet.');
        $commands->add('accounts:usage', [AccountCommands::class, 'usage'])
            ->describe('Read every account\'s disk use from the kernel\'s quotas.');
        $commands->add('dev:seed-hosting', DevSeedHosting::class)
            ->describe('Sample packages and accounts (rows only), on a developer\'s machine only. Run dev:seed first.');
    });

    $module->schedules(static function (ScheduleCollector $schedules): void {
        $schedules->command('accounts:settle')->everyMinute()->withoutOverlapping(600);
        $schedules->command('accounts:usage')->everyFifteenMinutes()->withoutOverlapping(900);
    });

    $module->filter('panel.nav', static function (array $items): array {
        $items[] = ['area' => 'admin', 'section' => 'Panel.section_hosting', 'key' => 'accounts', 'label' => 'Accounts.accounts', 'icon' => 'globe', 'route' => 'admin.accounts', 'can' => 'accounts.manage', 'order' => 5];

        return $items;
    });

    $module->onBoot(static function (AccountListeners $listeners, HookEngine $hooks, FilterEngine $filters): void {
        $filters->add('panel.dashboard', [$listeners, 'dashboard'], 10, 'Accounts', 3);
        $filters->add('packages.in_use', [$listeners, 'packageInUse'], 10, 'Accounts', 2);
        $hooks->add('packages.updated', [$listeners, 'packageUpdated'], 10, 'Accounts', 1);
    });
};
