<?php

declare(strict_types=1);

use App\Engine\Auth\AccessCollector;
use App\Engine\Cli\CommandCollector;
use App\Engine\Container\Container;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Hook\HookEngine;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Modules\Hosting\Accounts\AccountAccess;
use App\Modules\Hosting\Accounts\AccountActions;
use App\Modules\Hosting\Accounts\AccountStore;
use App\Modules\Hosting\Commands\AccountCommands;
use App\Modules\Hosting\Http\AccountsApi;
use App\Modules\Hosting\Http\AccountsPage;
use App\Modules\Hosting\Http\AccountViews;
use App\Modules\Operations\Store\Operation;
use App\Modules\Shared\Ui\Navigation;

/**
 * Hosting accounts (ADR-0013, milestone M6a): a Linux user, a root-owned home
 * and a PHP-FPM pool per account on a managed server, made, suspended and
 * deleted through the agent's privileged half, lpa-exec.
 *
 * Administrators manage accounts; a customer sees its own. Every account id
 * a person sends goes through AccountAccess (THREAT_MODEL.md test 1).
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Hosting')
        ->version('0.1.0')
        ->description('Hosting accounts: a Linux user, home and PHP-FPM pool per customer account.')
        ->requires('Identity')
        ->requires('Servers')
        ->requires('Operations')
        ->requires('Audit');

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(AccountStore::class);
        $services->singleton(AccountAccess::class);
        $services->singleton(AccountActions::class);
        $services->singleton(AccountViews::class);
    });

    $module->access(static function (AccessCollector $access): void {
        $access->capability('accounts.own', 'See the hosting accounts you own.');
        $access->capability('accounts.read', 'See every hosting account.');
        $access->capability('accounts.manage', 'Create, suspend and delete hosting accounts.');
    });

    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('accounts:create', [AccountCommands::class, 'create'])
            ->describe('Create a hosting account on a server, for a customer.')
            ->argument('server', 'The server\'s id, or its name.')
            ->argument('owner', 'The customer\'s login, or user id.')
            ->argument('label', 'What to call the account, e.g. "Acme Ltd".')
            ->option('timeout', 'With --wait, seconds to wait (default 120).')
            ->flag('wait', 'Wait until it is set up; exit 0 only if it became active.');
        $commands->add('accounts:list', [AccountCommands::class, 'list'])
            ->describe('List hosting accounts.')
            ->option('status', 'current (default), active, suspended, busy, failed or deleted.')
            ->flag('json', 'As JSON.');
        $commands->add('accounts:suspend', [AccountCommands::class, 'suspend'])
            ->describe('Suspend an account: no logins, no PHP, its processes stopped. Files are kept.')
            ->argument('account', 'The account\'s id, or its system user.')
            ->option('timeout', 'With --wait, seconds to wait (default 120).')
            ->flag('wait', 'Wait until it is suspended.');
        $commands->add('accounts:unsuspend', [AccountCommands::class, 'unsuspend'])
            ->describe('Unsuspend an account.')
            ->argument('account', 'The account\'s id, or its system user.')
            ->option('timeout', 'With --wait, seconds to wait (default 120).')
            ->flag('wait', 'Wait until it is active.');
        $commands->add('accounts:delete', [AccountCommands::class, 'delete'])
            ->describe('Delete an account and every file in it. There is no undo.')
            ->argument('account', 'The account\'s id, or its system user.')
            ->option('confirm', 'The account\'s system user, typed again.')
            ->option('timeout', 'With --wait, seconds to wait (default 300).')
            ->flag('wait', 'Wait until it is deleted.');
    });

    $module->routes(static function (RouteCollector $routes): void {
        $ulid = '[0-9A-HJKMNP-TV-Z]{26}';

        // accounts.own is the floor; AccountAccess decides which account, and
        // AccountActions checks accounts.manage, auditing every refusal.
        $routes->get('/accounts', [AccountsPage::class, 'index'])->name('accounts.index')->meta(['can' => 'accounts.own']);
        $routes->get('/accounts/new', [AccountsPage::class, 'create'])->name('accounts.create')->meta(['can' => 'accounts.manage']);
        $routes->post('/accounts', [AccountsPage::class, 'store'])->name('accounts.store')->meta(['can' => 'accounts.manage', 'rate_limit' => '30/1m']);
        $routes->get('/accounts/{id}', [AccountsPage::class, 'show'])->name('accounts.show')->where('id', $ulid)->meta(['can' => 'accounts.own']);
        foreach (['suspend', 'unsuspend', 'delete', 'retry'] as $action) {
            $routes->post('/accounts/{id}/' . $action, [AccountsPage::class, $action])->name('accounts.' . $action)->where('id', $ulid)->meta(['can' => 'accounts.own', 'rate_limit' => '30/1m']);
        }

        $api = static function (RouteCollector $routes) use ($ulid): void {
            $routes->get('/accounts', [AccountsApi::class, 'index'])->name('accounts.index')->meta(['can' => 'accounts.own']);
            $routes->post('/accounts', [AccountsApi::class, 'store'])->name('accounts.store')->meta(['can' => 'accounts.own', 'rate_limit' => '30/1m']);
            $routes->get('/accounts/{id}', [AccountsApi::class, 'show'])->name('accounts.show')->where('id', $ulid)->meta(['can' => 'accounts.own']);
            $routes->delete('/accounts/{id}', [AccountsApi::class, 'destroy'])->name('accounts.destroy')->where('id', $ulid)->meta(['can' => 'accounts.own', 'rate_limit' => '30/1m']);
            foreach (['suspend', 'unsuspend', 'retry'] as $action) {
                $routes->post('/accounts/{id}/' . $action, [AccountsApi::class, $action])->name('accounts.' . $action)->where('id', $ulid)->meta(['can' => 'accounts.own', 'rate_limit' => '30/1m']);
            }
        };
        // Cookie-authenticated, so CSRF stays on (ARCHITECTURE.md §4).
        $routes->group('/api/v1', $api, name: 'api.v1.', meta: ['api' => true]);
        $routes->group('/api/token/v1', $api, name: 'api.token.v1.', meta: ['api' => true, 'csrf' => false, 'auth' => true, 'rate_limit' => '120/1m']);
    });

    $module->onBoot(static function (HookEngine $hooks, Navigation $nav, Container $container): void {
        // An account's operation settles the account, in the operation's own transaction.
        $hooks->add('operations.finished', static function (Operation $op) use ($container): void {
            $container->get(AccountActions::class)->settle($op);
        }, 10, 'Hosting');

        $nav->add('hosting', 'accounts', 'Hosting accounts', 'accounts.index', 'folder', can: 'accounts.own', order: 5);
    });
};
