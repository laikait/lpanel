<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\Backup\Drivers;

use App\Engine\Database\Connection;
use App\Tests\Fixtures\Modules\Backup\BackupException;
use App\Tests\Fixtures\Modules\Backup\Dsn;

/**
 * No external process at all: SQLite is a file, and PDO already knows how
 * to copy one consistently.
 */
final class SqliteDriver implements DriverInterface
{
    public function extension(): string
    {
        return 'sqlite';
    }

    /**
     * `VACUUM INTO` is atomic and safe to run on a live database -- it is
     * SQLite's own answer to "back this up while it may be in use" -- so
     * this needs no lock and no coordination with the application.
     */
    public function backup(Connection $connection, Dsn $dsn, string $destination): void
    {
        $connection->pdo()->exec('VACUUM INTO ' . $this->quote($destination));
    }

    /**
     * There is no `VACUUM INTO` in reverse. Restoring means replacing the
     * live file, which is only safe once nothing is reading or writing it --
     * this method does not attempt to coordinate that with the rest of the
     * application, or with any other process that opened the same file.
     * **Stop the application first.**
     */
    public function restore(Connection $connection, Dsn $dsn, string $source): void
    {
        if ($dsn->path === null) {
            throw BackupException::inMemoryConnection($connection->name());
        }

        if (!\is_file($source)) {
            throw BackupException::sourceNotFound($source);
        }

        // Every statement this connection has open must let go of the file
        // before it can be replaced out from under it.
        $connection->disconnect();

        if (!@\copy($source, $dsn->path)) {
            throw BackupException::couldNotWrite($dsn->path);
        }
    }

    private function quote(string $path): string
    {
        return "'" . \str_replace("'", "''", $path) . "'";
    }
}
