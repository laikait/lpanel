<?php

declare(strict_types=1);

namespace App\Tests\Unit\Backup;

use App\Engine\Database\ConnectionConfig;
use App\Tests\Fixtures\Modules\Backup\Dsn;
use App\Tests\Support\TestCase;

final class DsnTest extends TestCase
{
    public function test_mysql(): void
    {
        $dsn = Dsn::parse(ConnectionConfig::of('main', 'mysql:host=db;port=3306;dbname=app;charset=utf8mb4', 'appuser', 's3cret'));

        self::assertSame('mysql', $dsn->driver);
        self::assertSame('db', $dsn->host);
        self::assertSame(3306, $dsn->port);
        self::assertSame('app', $dsn->database);
        self::assertNull($dsn->path);
    }

    public function test_pgsql(): void
    {
        $dsn = Dsn::parse(ConnectionConfig::of('main', 'pgsql:host=db;port=5432;dbname=warehouse'));

        self::assertSame('pgsql', $dsn->driver);
        self::assertSame('db', $dsn->host);
        self::assertSame(5432, $dsn->port);
        self::assertSame('warehouse', $dsn->database);
    }

    public function test_sqlsrv_with_a_port(): void
    {
        $dsn = Dsn::parse(ConnectionConfig::of('main', 'sqlsrv:Server=db,1433;Database=erp'));

        self::assertSame('sqlsrv', $dsn->driver);
        self::assertSame('db', $dsn->host);
        self::assertSame(1433, $dsn->port);
        self::assertSame('erp', $dsn->database);
    }

    public function test_sqlsrv_without_a_port(): void
    {
        $dsn = Dsn::parse(ConnectionConfig::of('main', 'sqlsrv:Server=db;Database=erp'));

        self::assertSame('db', $dsn->host);
        self::assertNull($dsn->port);
    }

    public function test_sqlite_file(): void
    {
        $dsn = Dsn::parse(ConnectionConfig::of('main', 'sqlite:/var/lib/app/db.sqlite'));

        self::assertSame('sqlite', $dsn->driver);
        self::assertSame('/var/lib/app/db.sqlite', $dsn->path);
        self::assertNull($dsn->host);
    }

    public function test_sqlite_in_memory(): void
    {
        $dsn = Dsn::parse(ConnectionConfig::of('main', 'sqlite::memory:'));

        self::assertNull($dsn->path);
    }

    /** A DSN written by hand may not match this framework's own casing. */
    public function test_keys_are_read_case_insensitively(): void
    {
        $dsn = Dsn::parse(ConnectionConfig::of('main', 'mysql:HOST=db;DbName=app'));

        self::assertSame('db', $dsn->host);
        self::assertSame('app', $dsn->database);
    }
}
