<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\Backup;

use App\Engine\Error\FrameworkException;

/** Why a backup or a restore could not be made. Each message names what to fix. */
final class BackupException extends FrameworkException
{
    public static function noDriverFor(string $driver): self
    {
        return new self(\sprintf(
            'There is no backup driver for "%s". Supported: mysql, pgsql, sqlite, sqlsrv.',
            $driver,
        ));
    }

    public static function couldNotCreateDirectory(string $directory): self
    {
        return new self(\sprintf('Could not create the backup directory %s.', $directory));
    }

    public static function couldNotWrite(string $path): self
    {
        return new self(\sprintf('Could not write the backup to %s.', $path));
    }

    public static function sourceNotFound(string $path): self
    {
        return new self(\sprintf('The backup file %s does not exist.', $path));
    }

    public static function inMemoryConnection(string $connection): self
    {
        return new self(\sprintf(
            'The "%s" connection is an in-memory SQLite database, which has no file to back up or restore into.',
            $connection,
        ));
    }

    public static function namesNoDatabase(string $connection): self
    {
        return new self(\sprintf('The "%s" connection\'s DSN names no database.', $connection));
    }
}
