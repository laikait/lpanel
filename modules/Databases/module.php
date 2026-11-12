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
use App\Engine\Config\Env;
use App\Modules\Databases\Commands\DbCommands;
use App\Modules\Databases\Databases;
use App\Modules\Databases\DbActions;
use App\Modules\Databases\DbListeners;
use App\Modules\Databases\DbSync;
use App\Modules\Databases\DbUsage;
use App\Modules\Databases\Http\DatabaseApi;
use App\Modules\Databases\Http\DatabasePages;
use App\Modules\Databases\PhpMyAdmin;

/**
 * Databases: each account's MariaDB databases and users (named with the
 * account's prefix), what each user may do, the hosts users may connect
 * from, phpMyAdmin with single sign-on, and the space databases take,
 * counted against the package's disk space.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Databases')
        ->version('0.1.0')
        ->description('MariaDB databases, users and privileges for each account, with phpMyAdmin.');

    $module->requires('Panel', '^0.1')
        ->requires('Audit', '^0.1')
        ->requires('Identity', '^0.1')
        ->requires('Agent', '^0.1')
        ->requires('Packages', '^0.1')
        ->requires('Accounts', '^0.1')
        ->requires('Subusers', '^0.1');

    $module->config([
        // Where the browser goes for phpMyAdmin (the User panel's own port).
        'phpmyadmin_url' => Env::string('LPANEL_PMA_URL', '/phpmyadmin/'),
        // One-time sign-on tickets, shared with phpMyAdmin's PHP pool.
        'phpmyadmin_tickets' => Env::string('LPANEL_PMA_TICKETS', '/var/lib/lpanel-pma/tickets'),
    ]);

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(Databases::class);
        $services->singleton(DbSync::class);
        $services->singleton(DbActions::class);
        $services->singleton(DbUsage::class);
        $services->singleton(PhpMyAdmin::class);
        $services->singleton(DbListeners::class);
        $services->bind(DatabasePages::class);
        $services->bind(DatabaseApi::class);
        $services->bind(DbCommands::class);
    });

    $module->access(static function (AccessCollector $access): void {
        $access->capability('server.databases', 'See MariaDB\'s state and connections, every account\'s databases, and end an account\'s connections.');
    });

    $module->routes(static function (RouteCollector $routes): void {
        $id = '\d+';

        $routes->group('/panel/databases', static function (RouteCollector $routes) use ($id): void {
            $routes->get('', [DatabasePages::class, 'index'])->name('');
            $routes->post('', [DatabasePages::class, 'store'])->name('.store');
            $routes->post('/{id}/rename', [DatabasePages::class, 'rename'])->name('.rename')->where('id', $id);
            $routes->post('/{id}/delete', [DatabasePages::class, 'delete'])->name('.delete')->where('id', $id);
            $routes->post('/users', [DatabasePages::class, 'storeUser'])->name('.users');
            $routes->post('/users/{id}/password', [DatabasePages::class, 'password'])->name('.users.password')->where('id', $id);
            $routes->post('/users/{id}/grants', [DatabasePages::class, 'grant'])->name('.users.grant')->where('id', $id);
            $routes->post('/users/{id}/delete', [DatabasePages::class, 'deleteUser'])->name('.users.delete')->where('id', $id);
            $routes->post('/hosts', [DatabasePages::class, 'addHost'])->name('.hosts');
            $routes->post('/hosts/{id}/delete', [DatabasePages::class, 'removeHost'])->name('.hosts.delete')->where('id', $id);
            $routes->post('/phpmyadmin', [DatabasePages::class, 'phpMyAdmin'])->name('.phpmyadmin');
        }, name: 'user.databases', meta: ['can' => 'feature.databases']);

        $routes->group('/admin/databases', static function (RouteCollector $routes) use ($id): void {
            $routes->get('', [DatabasePages::class, 'admin'])->name('');
            $routes->post('/processes/{id}/kill', [DatabasePages::class, 'kill'])->name('.kill')->where('id', $id);
        }, name: 'admin.databases', meta: ['can' => 'server.databases']);

        $routes->group('/admin/api/v1/databases', static function (RouteCollector $routes) use ($id): void {
            $routes->get('', [DatabaseApi::class, 'server'])->name('');
            $routes->delete('/processes/{id}', [DatabaseApi::class, 'kill'])->name('.kill')->where('id', $id);
        }, name: 'api.databases', meta: ['can' => 'server.databases', 'csrf' => false, 'rate_limit' => '60/1m']);

        // Changing an account's databases is changing the account.
        $routes->group('/admin/api/v1/accounts/{username}/databases', static function (RouteCollector $routes) use ($id): void {
            $user = '[a-z][a-z0-9]{1,15}';
            $name = '[a-z][a-z0-9]{1,15}_[a-z0-9_]{1,63}';
            $modify = ['can' => 'accounts.modify'];
            $routes->get('', [DatabaseApi::class, 'index'])->name('')->where('username', $user);
            $routes->post('', [DatabaseApi::class, 'store'])->name('.store')->where('username', $user)->meta($modify);
            $routes->post('/users', [DatabaseApi::class, 'storeUser'])->name('.users')->where('username', $user)->meta($modify);
            $routes->put('/users/{user}/password', [DatabaseApi::class, 'password'])->name('.users.password')->where('username', $user)->where('user', $name)->meta($modify);
            $routes->delete('/users/{user}', [DatabaseApi::class, 'destroyUser'])->name('.users.destroy')->where('username', $user)->where('user', $name)->meta($modify);
            $routes->put('/users/{user}/grants/{database}', [DatabaseApi::class, 'grant'])->name('.grant')->where('username', $user)->where('user', $name)->where('database', $name)->meta($modify);
            $routes->delete('/users/{user}/grants/{database}', [DatabaseApi::class, 'revoke'])->name('.revoke')->where('username', $user)->where('user', $name)->where('database', $name)->meta($modify);
            $routes->post('/hosts', [DatabaseApi::class, 'addHost'])->name('.hosts')->where('username', $user)->meta($modify);
            $routes->delete('/hosts/{id}', [DatabaseApi::class, 'removeHost'])->name('.hosts.destroy')->where('username', $user)->where('id', $id)->meta($modify);
            $routes->put('/{database}', [DatabaseApi::class, 'rename'])->name('.rename')->where('username', $user)->where('database', $name)->meta($modify);
            $routes->delete('/{database}', [DatabaseApi::class, 'destroy'])->name('.destroy')->where('username', $user)->where('database', $name)->meta($modify);
        }, name: 'api.account_databases', meta: ['can' => 'accounts.manage', 'csrf' => false, 'rate_limit' => '120/1m']);
    });

    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('db:list', [DbCommands::class, 'list'])
            ->describe('List an account\'s databases, database users and remote hosts.')
            ->argument('username', 'The account.');
        $commands->add('db:create', [DbCommands::class, 'create'])
            ->describe('Make a database (its name gets the account\'s prefix).')
            ->argument('username', 'The account.')
            ->argument('name', 'Such as shop, or bob_shop.');
        $commands->add('db:drop', [DbCommands::class, 'drop'])
            ->describe('Drop a database and everything in it.')
            ->argument('username', 'The account.')
            ->argument('name', 'The database.');
        $commands->add('db:rename', [DbCommands::class, 'rename'])
            ->describe('Rename a database (its tables move; views, routines, triggers and events stop it).')
            ->argument('username', 'The account.')
            ->argument('from', 'The database.')
            ->argument('to', 'Its new name.');
        $commands->add('db:user-create', [DbCommands::class, 'userCreate'])
            ->describe('Make a database user; its password is read from standard input.')
            ->argument('username', 'The account.')
            ->argument('name', 'Such as app, or bob_app.')
            ->option('grant', 'Databases it gets every privilege on, comma-separated.');
        $commands->add('db:user-password', [DbCommands::class, 'userPassword'])
            ->describe('Change a database user\'s password, read from standard input.')
            ->argument('username', 'The account.')
            ->argument('name', 'The database user.');
        $commands->add('db:user-drop', [DbCommands::class, 'userDrop'])
            ->describe('Remove a database user.')
            ->argument('username', 'The account.')
            ->argument('name', 'The database user.');
        $commands->add('db:grant', [DbCommands::class, 'grant'])
            ->describe('Set what a user may do in a database.')
            ->argument('username', 'The account.')
            ->argument('user', 'The database user.')
            ->argument('database', 'The database.')
            ->option('privileges', 'ALL (default), or a comma-separated list such as SELECT,INSERT,UPDATE,DELETE.');
        $commands->add('db:revoke', [DbCommands::class, 'revoke'])
            ->describe('Take away everything a user may do in a database.')
            ->argument('username', 'The account.')
            ->argument('user', 'The database user.')
            ->argument('database', 'The database.');
        $commands->add('db:host-add', [DbCommands::class, 'hostAdd'])
            ->describe('Let the account\'s database users connect from another host.')
            ->argument('username', 'The account.')
            ->argument('host', 'An address, a network such as 203.0.113.0/24, a host name or a pattern with %.')
            ->option('note', 'What it is.');
        $commands->add('db:host-remove', [DbCommands::class, 'hostRemove'])
            ->describe('Stop letting the account\'s users connect from a host.')
            ->argument('username', 'The account.')
            ->argument('host', 'The host, as added.');
        $commands->add('db:usage', [DbCommands::class, 'usage'])
            ->describe('Measure every account\'s databases and count them against its disk space.');
        $commands->add('db:settle', [DbCommands::class, 'settle'])
            ->describe('Send database changes the agent has not answered yet, and remove stale phpMyAdmin logins.');
        $commands->add('db:sync', [DbCommands::class, 'sync'])
            ->describe('Send an account\'s databases and users to the server again, as the panel has them.')
            ->argument('username', 'The account.', required: false)
            ->flag('all', 'Every account.');
        $commands->add('db:info', [DbCommands::class, 'info'])
            ->describe('Show MariaDB\'s state and its connections.');
        $commands->add('db:kill', [DbCommands::class, 'kill'])
            ->describe('End one of an account\'s database connections.')
            ->argument('id', 'The connection id (db:info lists them).');
    });

    $module->schedules(static function (ScheduleCollector $schedules): void {
        $schedules->command('db:settle')->everyMinute()->withoutOverlapping(600);
        $schedules->command('db:usage')->everyFifteenMinutes()->withoutOverlapping(900);
    });

    $module->filter('panel.nav', static function (array $items): array {
        $items[] = ['area' => 'admin', 'section' => 'Panel.section_server', 'key' => 'databases', 'label' => 'Databases.title', 'icon' => 'database', 'route' => 'admin.databases', 'can' => 'server.databases', 'order' => 40];
        $items[] = ['area' => 'user', 'section' => 'Panel.section_hosting', 'key' => 'databases', 'label' => 'Databases.title', 'icon' => 'database', 'route' => 'user.databases', 'can' => 'feature.databases', 'order' => 25];

        return $items;
    });

    $module->onBoot(static function (DbListeners $listeners, HookEngine $hooks, FilterEngine $filters): void {
        $hooks->add('accounts.changed', [$listeners, 'accountChanged'], 20, 'Databases', 2);
        $filters->add('accounts.show', [$listeners, 'accountShow'], 20, 'Databases', 3);
        $filters->add('panel.dashboard', [$listeners, 'dashboard'], 30, 'Databases', 3);
    });
};
