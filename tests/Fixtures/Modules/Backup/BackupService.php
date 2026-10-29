<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\Backup;

use App\Engine\Config\Config;
use App\Engine\Core\Application;
use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionManager;
use App\Engine\Hook\HookEngine;
use App\Engine\Support\Path;
use App\Tests\Fixtures\Modules\Backup\Drivers\DriverInterface;
use App\Tests\Fixtures\Modules\Backup\Drivers\MySqlDriver;
use App\Tests\Fixtures\Modules\Backup\Drivers\PostgresDriver;
use App\Tests\Fixtures\Modules\Backup\Drivers\SqliteDriver;
use App\Tests\Fixtures\Modules\Backup\Drivers\SqlServerDriver;

/**
 * Dumps one connection's database to a file, picking the driver by the
 * connection's own DSN prefix. Which driver actually runs is the only
 * decision made here; how each one talks to its database lives in
 * Drivers/*, not in this class.
 */
final class BackupService
{
    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Application $application,
        private readonly Config $config,
        private readonly HookEngine $hooks,
        private readonly MySqlDriver $mysql,
        private readonly PostgresDriver $postgres,
        private readonly SqliteDriver $sqlite,
        private readonly SqlServerDriver $sqlserver,
    ) {}

    public function make(?string $connectionName): BackupOutcome
    {
        $connection = $this->connections->connection($connectionName);
        $dsn = Dsn::parse($connection->config());
        $driver = $this->driverFor($dsn->driver);

        $destination = Path::join($this->directory(), $this->filename($connection, $dsn, $driver));
        $this->hooks->do('backup.started', $connection->name(), $dsn->driver);

        $started = \microtime(true);

        try {
            $driver->backup($connection, $dsn, $destination);
        } catch (\Throwable $e) {
            $this->hooks->do('backup.failed', $connection->name(), $dsn->driver, $e);

            throw $e;
        }

        $outcome = new BackupOutcome(
            $connection->name(),
            $dsn->driver,
            $destination,
            (int) (@\filesize($destination) ?: 0),
            \microtime(true) - $started,
        );

        $this->hooks->do('backup.completed', $outcome);

        return $outcome;
    }

    private function driverFor(string $driver): DriverInterface
    {
        return match ($driver) {
            'mysql' => $this->mysql,
            'pgsql' => $this->postgres,
            'sqlite' => $this->sqlite,
            'sqlsrv' => $this->sqlserver,
            default => throw BackupException::noDriverFor($driver),
        };
    }

    private function filename(Connection $connection, Dsn $dsn, DriverInterface $driver): string
    {
        return \sprintf(
            '%s-%s-%s.%s',
            $connection->name(),
            $dsn->driver,
            \date('Y-m-d-His'),
            $driver->extension(),
        );
    }

    private function directory(): string
    {
        $configured = $this->config->string('Backup.directory');
        $directory = $configured !== null && $configured !== ''
            ? $configured
            : $this->application->basePath('system/Backups');

        if (!\is_dir($directory) && !@\mkdir($directory, 0o770, true) && !\is_dir($directory)) {
            throw BackupException::couldNotCreateDirectory($directory);
        }

        return $directory;
    }
}
