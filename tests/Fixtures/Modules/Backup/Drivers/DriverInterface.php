<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\Backup\Drivers;

use App\Engine\Database\Connection;
use App\Tests\Fixtures\Modules\Backup\Dsn;

/**
 * One driver, one way of getting a database out to a file and back.
 *
 * Nothing here is generic across drivers on purpose: what mysqldump takes as
 * flags, PostgreSQL takes differently, and SQLite and SQL Server need no
 * external process at all. A common interface that tried to average these
 * into one shape would either lose what each database can actually do, or
 * grow a flag per driver until it stopped being an interface.
 */
interface DriverInterface
{
    /** The extension a backup file for this driver gets: sql, sqlite, bak. */
    public function extension(): string;

    public function backup(Connection $connection, Dsn $dsn, string $destination): void;

    /** Replaces the connection's database with what $source holds. */
    public function restore(Connection $connection, Dsn $dsn, string $source): void;
}
