<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\Backup;

use App\Engine\Database\ConnectionManager;
use App\Engine\Hook\HookEngine;
use App\Tests\Fixtures\Modules\Backup\Drivers\DriverInterface;
use App\Tests\Fixtures\Modules\Backup\Drivers\MySqlDriver;
use App\Tests\Fixtures\Modules\Backup\Drivers\PostgresDriver;
use App\Tests\Fixtures\Modules\Backup\Drivers\SqliteDriver;
use App\Tests\Fixtures\Modules\Backup\Drivers\SqlServerDriver;

/**
 * Replaces a connection's database with what a backup file holds.
 *
 * There is no confirmation prompt and no dry run in here -- that belongs to
 * the command, which is where a person actually reads `--force` before
 * typing it. This class does exactly what it is called to do, the moment
 * it is called.
 */
final class RestoreService
{
    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly HookEngine $hooks,
        private readonly MySqlDriver $mysql,
        private readonly PostgresDriver $postgres,
        private readonly SqliteDriver $sqlite,
        private readonly SqlServerDriver $sqlserver,
    ) {}

    public function restore(?string $connectionName, string $source): BackupOutcome
    {
        if (!\is_file($source)) {
            throw BackupException::sourceNotFound($source);
        }

        $connection = $this->connections->connection($connectionName);
        $dsn = Dsn::parse($connection->config());
        $driver = $this->driverFor($dsn->driver);

        $this->hooks->do('restore.started', $connection->name(), $dsn->driver, $source);

        $started = \microtime(true);

        try {
            $driver->restore($connection, $dsn, $source);
        } catch (\Throwable $e) {
            $this->hooks->do('restore.failed', $connection->name(), $dsn->driver, $e);

            throw $e;
        }

        $outcome = new BackupOutcome(
            $connection->name(),
            $dsn->driver,
            $source,
            (int) (@\filesize($source) ?: 0),
            \microtime(true) - $started,
        );

        $this->hooks->do('restore.completed', $outcome);

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
}
