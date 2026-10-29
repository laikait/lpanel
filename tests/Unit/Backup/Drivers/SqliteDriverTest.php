<?php

declare(strict_types=1);

namespace App\Tests\Unit\Backup\Drivers;

use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionConfig;
use App\Tests\Fixtures\Modules\Backup\BackupException;
use App\Tests\Fixtures\Modules\Backup\Drivers\SqliteDriver;
use App\Tests\Fixtures\Modules\Backup\Dsn;
use App\Tests\Support\TestCase;

/**
 * The one driver that needs no external process and no fake binary: real
 * file-backed SQLite databases, backed up and restored for real.
 */
final class SqliteDriverTest extends TestCase
{
    private string $directory;

    private SqliteDriver $driver;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/backup-sqlite-' . \bin2hex(\random_bytes(6));
        \mkdir($this->directory);
        $this->driver = new SqliteDriver();
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->directory . '/*') ?: [] as $file) {
            @\unlink($file);
        }

        @\rmdir($this->directory);

        parent::tearDown();
    }

    public function test_backup_writes_a_file_that_holds_the_data(): void
    {
        $source = $this->directory . '/source.sqlite';
        $connection = new Connection(ConnectionConfig::of('main', 'sqlite:' . $source));
        $connection->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->execute('INSERT INTO t (name) VALUES (?)', ['ada']);

        $destination = $this->directory . '/backup.sqlite';
        $this->driver->backup($connection, Dsn::parse($connection->config()), $destination);

        self::assertFileExists($destination);

        $copy = new Connection(ConnectionConfig::of('copy', 'sqlite:' . $destination));
        self::assertSame([['id' => 1, 'name' => 'ada']], $copy->select('SELECT * FROM t'));
    }

    public function test_restore_replaces_the_live_file_with_the_backup(): void
    {
        $target = $this->directory . '/target.sqlite';
        $connection = new Connection(ConnectionConfig::of('main', 'sqlite:' . $target));
        $connection->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->execute('INSERT INTO t (name) VALUES (?)', ['before']);

        // A backup made from a different, unrelated database.
        $backupFile = $this->directory . '/backup.sqlite';
        $other = new Connection(ConnectionConfig::of('other', 'sqlite:' . $backupFile));
        $other->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $other->execute('INSERT INTO t (name) VALUES (?)', ['after']);
        $other->disconnect();

        $this->driver->restore($connection, Dsn::parse($connection->config()), $backupFile);

        $fresh = new Connection(ConnectionConfig::of('fresh', 'sqlite:' . $target));
        self::assertSame([['id' => 1, 'name' => 'after']], $fresh->select('SELECT * FROM t'));
    }

    public function test_restore_refuses_a_missing_source_file(): void
    {
        $connection = new Connection(ConnectionConfig::of('main', 'sqlite:' . $this->directory . '/target.sqlite'));

        $this->expectException(BackupException::class);
        $this->driver->restore($connection, Dsn::parse($connection->config()), $this->directory . '/no-such-file.sqlite');
    }

    public function test_restore_refuses_an_in_memory_connection(): void
    {
        $connection = new Connection(ConnectionConfig::of('main', 'sqlite::memory:'));
        \touch($this->directory . '/backup.sqlite');

        $this->expectException(BackupException::class);
        $this->driver->restore($connection, Dsn::parse($connection->config()), $this->directory . '/backup.sqlite');
    }
}
