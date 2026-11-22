<?php

declare(strict_types=1);

use App\Engine\Auth\AccessCollector;
use App\Engine\Cli\CommandCollector;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Filter\FilterEngine;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\Scheduler\ScheduleCollector;
use App\Modules\Backups\AccountExport;
use App\Modules\Backups\AccountImport;
use App\Modules\Backups\BackupListeners;
use App\Modules\Backups\BackupRestores;
use App\Modules\Backups\BackupRuns;
use App\Modules\Backups\BackupSettings;
use App\Modules\Backups\Commands\BackupCommands;
use App\Modules\Backups\Destinations;
use App\Modules\Backups\Http\BackupApi;
use App\Modules\Backups\Http\BackupPages;
use App\Modules\Backups\Http\RestoreApi;
use App\Modules\Backups\Http\RestorePages;

/**
 * Backups: every account backed up with restic by the agent, on a schedule
 * or by hand, to a local folder, an SFTP server, an S3-compatible store or
 * Backblaze B2, with daily, weekly and monthly retention. Each snapshot
 * holds the account's home, its databases and the panel's description of
 * it. The agent alone holds the destinations' credentials and passwords.
 *
 * Restores (M8b): files, mailboxes, databases or all of an account put back
 * from its snapshots, by the administrator or (User panel) its owner;
 * archives to download; an account that is not on the server made again
 * from its snapshot, here or on another server.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Backups')
        ->version('0.1.0')
        ->description('Scheduled and manual backups of every account, with restic.');

    $module->requires('Panel', '^0.1')
        ->requires('Audit', '^0.1')
        ->requires('Identity', '^0.1')
        ->requires('Agent', '^0.1')
        ->requires('Packages', '^0.1')
        ->requires('Accounts', '^0.1')
        ->requires('Subusers', '^0.1');

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(BackupSettings::class);
        $services->singleton(Destinations::class);
        $services->singleton(AccountExport::class);
        $services->singleton(BackupRuns::class);
        $services->singleton(AccountImport::class);
        $services->singleton(BackupRestores::class);
        $services->singleton(BackupListeners::class);
        $services->bind(BackupPages::class);
        $services->bind(BackupApi::class);
        $services->bind(RestorePages::class);
        $services->bind(RestoreApi::class);
        $services->bind(BackupCommands::class);
    });

    $module->access(static function (AccessCollector $access): void {
        $access->capability('server.backups', 'Set up backup destinations and the schedule, back accounts up, see their snapshots and restore from them.');
    });

    $module->routes(static function (RouteCollector $routes): void {
        $id = '\d+';

        $routes->group('/admin/backups', static function (RouteCollector $routes) use ($id): void {
            $routes->get('', [BackupPages::class, 'index'])->name('');
            $routes->post('/destinations', [BackupPages::class, 'addDestination'])->name('.add');
            $routes->post('/destinations/{id}', [BackupPages::class, 'changeDestination'])->name('.change')->where('id', $id);
            $routes->post('/destinations/{id}/delete', [BackupPages::class, 'removeDestination'])->name('.remove')->where('id', $id);
            $routes->post('/settings', [BackupPages::class, 'saveSettings'])->name('.settings');
            $routes->post('/run', [BackupPages::class, 'run'])->name('.run');
            $routes->get('/restore', [RestorePages::class, 'overview'])->name('.overview');
            $routes->get('/restore/plan', [RestorePages::class, 'plan'])->name('.plan');
            $routes->post('/restore/account', [RestorePages::class, 'recreate'])->name('.recreate');
            $user = '[a-z][a-z0-9]{1,15}';
            $routes->get('/accounts/{username}', [RestorePages::class, 'account'])->name('.account')->where('username', $user);
            $routes->post('/accounts/{username}/restore', [RestorePages::class, 'accountRestore'])->name('.account.restore')->where('username', $user);
            $routes->post('/accounts/{username}/download', [RestorePages::class, 'accountDownload'])->name('.account.download')->where('username', $user);
        }, name: 'admin.backups', meta: ['can' => 'server.backups']);

        // The account's owner (and sub-users allowed backups): its own snapshots.
        $routes->group('/panel/backups', static function (RouteCollector $routes): void {
            $routes->get('', [RestorePages::class, 'user'])->name('');
            $routes->post('/restore', [RestorePages::class, 'userRestore'])->name('.restore');
            $routes->post('/download', [RestorePages::class, 'userDownload'])->name('.download');
        }, name: 'user.backups', meta: ['can' => 'feature.backups']);

        $routes->group('/admin/api/v1/backups', static function (RouteCollector $routes) use ($id): void {
            $routes->get('', [BackupApi::class, 'index'])->name('');
            $routes->put('/settings', [BackupApi::class, 'saveSettings'])->name('.settings');
            $routes->post('/destinations', [BackupApi::class, 'addDestination'])->name('.add');
            $routes->put('/destinations/{id}', [BackupApi::class, 'changeDestination'])->name('.change')->where('id', $id);
            $routes->delete('/destinations/{id}', [BackupApi::class, 'removeDestination'])->name('.remove')->where('id', $id);
            $routes->post('/runs', [BackupApi::class, 'startRun'])->name('.run');
            $routes->get('/runs/{id}', [BackupApi::class, 'showRun'])->name('.show_run')->where('id', $id);
            $routes->get('/snapshots', [RestoreApi::class, 'snapshots'])->name('.snapshots');
            $routes->get('/snapshots/{snapshot}/plan', [RestoreApi::class, 'plan'])->name('.plan')->where('snapshot', '[0-9a-f]{8,64}');
            $routes->post('/restores', [RestoreApi::class, 'recreate'])->name('.recreate');
            $routes->get('/restores', [RestoreApi::class, 'restores'])->name('.restores');
            $routes->get('/restores/{id}', [RestoreApi::class, 'show'])->name('.show_restore')->where('id', $id);
        }, name: 'api.backups', meta: ['can' => 'server.backups', 'csrf' => false, 'rate_limit' => '60/1m']);

        $routes->get('/admin/api/v1/accounts/{username}/backups', [BackupApi::class, 'account'])
            ->name('api.account_backups')->where('username', '[a-z][a-z0-9]{1,15}')
            ->meta(['can' => ['server.backups', 'accounts.manage'], 'csrf' => false, 'rate_limit' => '60/1m']);

        // Restoring an account's data is changing the account.
        $routes->group('/admin/api/v1/accounts/{username}/backups', static function (RouteCollector $routes): void {
            $user = '[a-z][a-z0-9]{1,15}';
            $routes->get('/files', [RestoreApi::class, 'files'])->name('.files')->where('username', $user);
            $routes->post('/restores', [RestoreApi::class, 'restore'])->name('.restore')->where('username', $user);
            $routes->post('/downloads', [RestoreApi::class, 'download'])->name('.download')->where('username', $user);
        }, name: 'api.account_restores', meta: ['can' => 'server.backups', 'csrf' => false, 'rate_limit' => '60/1m']);
    });

    $module->commands(static function (CommandCollector $commands): void {
        $where = [
            'path' => 'local and sftp: the folder.',
            'host' => 'sftp: the server.',
            'port' => 'sftp: the port (default 22).',
            'user' => 'sftp: the login.',
            'endpoint' => 's3: the host (and :port), such as s3.eu-central-1.wasabisys.com.',
            'scheme' => 's3: https (default) or http.',
            'region' => 's3: the region, when the store wants one.',
            'bucket' => 's3 and b2: the bucket.',
            'prefix' => 's3 and b2: a folder in the bucket.',
        ];
        $commands->add('backup:destinations', [BackupCommands::class, 'destinations'])
            ->describe('List the backup destinations.');
        $add = $commands->add('backup:destination-add', [BackupCommands::class, 'add'])
            ->describe('Add a destination; the agent checks it and makes its repository. For s3 and b2, standard input holds the access key and the secret, on two lines.')
            ->argument('name', 'What to call it.')
            ->argument('type', 'local, sftp, s3 or b2.')
            ->flag('existing', 'The repository is another server\'s: its password (lpa backup-password there) on standard input, after any keys.');
        $change = $commands->add('backup:destination-change', [BackupCommands::class, 'change'])
            ->describe('Change a destination (the agent checks it again).')
            ->argument('destination', 'Its id or name.')
            ->option('name', 'A new name.')
            ->flag('secrets', 'New keys, on standard input (two lines).');

        foreach ($where as $option => $text) {
            $add->option($option, $text);
            $change->option($option, $text);
        }

        $commands->add('backup:destination-remove', [BackupCommands::class, 'remove'])
            ->describe('Forget a destination (what is there stays there).')
            ->argument('destination', 'Its id or name.')
            ->flag('yes', 'Really do it.');
        $commands->add('backup:settings', [BackupCommands::class, 'settings'])
            ->describe('Show the schedule and retention, or change them.')
            ->option('enabled', '1 to back every account up each day, 0 not to.')
            ->option('time', 'When, HH:MM in the server\'s time zone.')
            ->option('destination', 'Where to (id or name).')
            ->option('daily', 'Daily snapshots kept.')
            ->option('weekly', 'Weekly snapshots kept.')
            ->option('monthly', 'Monthly snapshots kept.')
            ->option('prune-day', 'The weekday (0 Sunday … 6) the space of forgotten snapshots is freed.');
        $commands->add('backup:run', [BackupCommands::class, 'run'])
            ->describe('Back up now: every account, or those named.')
            ->option('accounts', 'Comma-separated usernames.')
            ->option('destination', 'Where to (default: the schedule\'s).')
            ->flag('wait', 'Wait for the run to end and show each account\'s outcome.');
        $commands->add('backup:runs', [BackupCommands::class, 'runs'])
            ->describe('List recent runs.');
        $commands->add('backup:snapshots', [BackupCommands::class, 'snapshots'])
            ->describe('List an account\'s snapshots, newest first.')
            ->argument('username', 'The account.')
            ->option('destination', 'Where (default: the schedule\'s).');
        $commands->add('backup:accounts', [BackupCommands::class, 'accounts'])
            ->describe('List every account a destination has snapshots of, and whether it is on this server.')
            ->option('destination', 'Where (default: the schedule\'s).');
        $commands->add('backup:files', [BackupCommands::class, 'files'])
            ->describe('List a folder of an account\'s snapshot, and the databases it holds.')
            ->argument('username', 'The account.')
            ->argument('snapshot', 'The snapshot (its short id is enough).')
            ->option('path', 'A folder in the home (default: the top).')
            ->option('destination', 'Where (default: the schedule\'s).');
        $commands->add('backup:restore', [BackupCommands::class, 'restore'])
            ->describe('Put files, a mailbox, databases or all of an account on this server back from one of its snapshots.')
            ->argument('username', 'The account.')
            ->argument('snapshot', 'The snapshot.')
            ->option('paths', 'Comma-separated paths in the home.')
            ->option('mailbox', 'A mailbox, by its address.')
            ->option('databases', 'Comma-separated databases.')
            ->flag('all', 'All of it: the home and every database.')
            ->flag('folder', 'Files into restored-<time>/ instead of where they were.')
            ->option('destination', 'Where from (default: the schedule\'s).')
            ->flag('wait', 'Wait for it to end.');
        $commands->add('backup:recreate', [BackupCommands::class, 'recreate'])
            ->describe('Make an account that is not on this server again from one of its snapshots: its settings, files, mail and databases.')
            ->argument('snapshot', 'The snapshot.')
            ->option('destination', 'Where from (default: the schedule\'s).')
            ->option('package', 'Put it on this package (id) instead of its own.')
            ->flag('dry-run', 'Only say whether it can be done, and how.')
            ->flag('wait', 'Wait for it to end.');
        $commands->add('backup:download', [BackupCommands::class, 'download'])
            ->describe('Put an archive of a snapshot, a folder of it or a database in the account\'s lpanel-backups/ folder.')
            ->argument('username', 'The account.')
            ->argument('snapshot', 'The snapshot.')
            ->option('path', 'A folder or file in the home (default: everything).')
            ->option('database', 'A database instead.')
            ->option('destination', 'Where from (default: the schedule\'s).')
            ->flag('wait', 'Wait for it to end.');
        $commands->add('backup:restores', [BackupCommands::class, 'restores'])
            ->describe('List recent restores and downloads.')
            ->option('account', 'Only this account\'s.');
        $commands->add('backup:settle', [BackupCommands::class, 'settle'])
            ->describe('Ask the agent how running backups and restores went.');
        $commands->add('backup:due', [BackupCommands::class, 'due'])
            ->describe('Start the scheduled backup if it is due.');
    });

    $module->schedules(static function (ScheduleCollector $schedules): void {
        $schedules->command('backup:settle')->everyMinute()->withoutOverlapping(600);
        $schedules->command('backup:due')->everyMinute()->withoutOverlapping(600);
    });

    $module->filter('panel.nav', static function (array $items): array {
        $items[] = ['area' => 'admin', 'section' => 'Panel.section_server', 'key' => 'backups', 'label' => 'Backups.title', 'icon' => 'archive', 'route' => 'admin.backups', 'can' => 'server.backups', 'order' => 48];

        $items[] = ['area' => 'user', 'section' => 'Panel.section_hosting', 'key' => 'backups', 'label' => 'Backups.title', 'icon' => 'archive', 'route' => 'user.backups', 'can' => 'feature.backups', 'order' => 21];

        return $items;
    });

    $module->onBoot(static function (BackupListeners $listeners, FilterEngine $filters): void {
        $filters->add('accounts.show', [$listeners, 'accountShow'], 30, 'Backups', 3);
        $filters->add('panel.attention', [$listeners, 'attention'], 30, 'Backups', 3);
    });
};
