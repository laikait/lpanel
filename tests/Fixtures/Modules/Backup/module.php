<?php

declare(strict_types=1);

use App\Engine\Cli\CommandCollector;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Hook\HookEngine;
use App\Engine\Module\ModuleContext;
use App\Tests\Fixtures\Modules\Backup\BackupService;
use App\Tests\Fixtures\Modules\Backup\Cli\BackupMakeCommand;
use App\Tests\Fixtures\Modules\Backup\Cli\BackupRestoreCommand;
use App\Tests\Fixtures\Modules\Backup\Drivers\MySqlDriver;
use App\Tests\Fixtures\Modules\Backup\Drivers\PostgresDriver;
use App\Tests\Fixtures\Modules\Backup\Drivers\SqliteDriver;
use App\Tests\Fixtures\Modules\Backup\Drivers\SqlServerDriver;
use App\Tests\Fixtures\Modules\Backup\Log\BackupLog;
use App\Tests\Fixtures\Modules\Backup\RestoreService;

/**
 * backup:make / backup:restore, across all four database drivers.
 *
 * MySQL and PostgreSQL shell out to their own vendor tools
 * (mysqldump/mysql, pg_dump/psql) through CommandExecutor, so those four
 * binaries must be named in config/system.php's commands.allowed before
 * either driver will run -- CommandExecutor default-denies everything else.
 * SQLite and SQL Server never leave PDO, so neither needs an allowlist
 * entry at all.
 *
 * See docs/guides/backups.md, especially the SQL Server "same filesystem"
 * caveat and the SQLite "stop the application first" caveat before
 * restoring either.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Backup')
        ->version('0.1.0')
        ->description('backup:make / backup:restore for MySQL, PostgreSQL, SQLite and SQL Server.');

    $module->requires('Shared');

    $module->config([
        // null = system/Backups under the application root.
        'directory' => null,
    ]);

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(MySqlDriver::class);
        $services->singleton(PostgresDriver::class);
        $services->singleton(SqliteDriver::class);
        $services->singleton(SqlServerDriver::class);
        $services->singleton(BackupService::class);
        $services->singleton(RestoreService::class);
        $services->bind(BackupMakeCommand::class);
        $services->bind(BackupRestoreCommand::class);
        $services->bind(BackupLog::class);
    });

    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('backup:make', BackupMakeCommand::class)
            ->option('connection', 'Which connection to back up. Defaults to the default one.', shortcut: 'c')
            ->note('The filename is timestamped, so nothing already there is ever overwritten.');

        $commands->add('backup:restore', BackupRestoreCommand::class)
            ->argument('path', 'The backup file to restore from.')
            ->option('connection', 'Which connection to restore into. Defaults to the default one.', shortcut: 'c')
            ->flag('force', 'Required. Restoring replaces the target database.')
            ->note('Refused without --force, on every environment: there is no undo for a restore.');
    });

    // Not run by default -- see docs/guides/backups.md. A copied application
    // adds this because it decided to, not because the module did.
    //
    // $module->schedules(static function (ScheduleCollector $schedules): void {
    //     $schedules->command('backup:make')->dailyAt('02:00')->withoutOverlapping();
    // });

    // Registered from onBoot(), not hook(): BackupLog's methods are not
    // static, so the instance has to exist first -- see the exception
    // hook() throws for a non-static [Class::class, 'method'] pair, which
    // spells out this exact fix.
    $module->onBoot(static function (BackupLog $log, HookEngine $hooks): void {
        $hooks->add('backup.completed', [$log, 'completed'], 10, 'Backup');
        $hooks->add('backup.failed', [$log, 'failed'], 10, 'Backup');
        $hooks->add('restore.completed', [$log, 'restored'], 10, 'Backup');
        $hooks->add('restore.failed', [$log, 'restoreFailed'], 10, 'Backup');
    });
};
