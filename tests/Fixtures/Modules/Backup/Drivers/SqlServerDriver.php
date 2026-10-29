<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\Backup\Drivers;

use App\Engine\Database\Connection;
use App\Tests\Fixtures\Modules\Backup\BackupException;
use App\Tests\Fixtures\Modules\Backup\Dsn;

/**
 * `BACKUP DATABASE`/`RESTORE DATABASE`, over the same PDO connection the
 * application already uses -- no external tool, no CommandPolicy entry.
 *
 * **The path is on the database server's own filesystem, not necessarily
 * this one.** That only lines up automatically when the application and
 * the database share a machine; otherwise $destination/$source must be a
 * path the SQL Server service account can itself reach (a UNC share, most
 * often), which is a deployment decision this driver cannot make for you.
 * See docs/guides/backups.md.
 */
final class SqlServerDriver implements DriverInterface
{
    public function extension(): string
    {
        return 'bak';
    }

    public function backup(Connection $connection, Dsn $dsn, string $destination): void
    {
        $connection->pdo()->exec(\sprintf(
            'BACKUP DATABASE %s TO DISK = N%s WITH INIT, COMPRESSION',
            $this->database($connection, $dsn),
            $this->literal($destination),
        ));
    }

    public function restore(Connection $connection, Dsn $dsn, string $source): void
    {
        $connection->pdo()->exec(\sprintf(
            'RESTORE DATABASE %s FROM DISK = N%s WITH REPLACE',
            $this->database($connection, $dsn),
            $this->literal($source),
        ));
    }

    private function database(Connection $connection, Dsn $dsn): string
    {
        if ($dsn->database === null) {
            throw BackupException::namesNoDatabase($connection->name());
        }

        return '[' . \str_replace(']', ']]', $dsn->database) . ']';
    }

    private function literal(string $value): string
    {
        return "'" . \str_replace("'", "''", $value) . "'";
    }
}
