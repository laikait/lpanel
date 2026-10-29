<?php

declare(strict_types=1);

namespace App\Tests\Unit\Backup\Drivers;

use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionConfig;
use App\Engine\System\Command\CommandExecutor;
use App\Tests\Fixtures\Modules\Backup\Drivers\PostgresDriver;
use App\Tests\Fixtures\Modules\Backup\Dsn;
use App\Tests\Support\TestCase;

/** Same technique as MySqlDriverTest: a fake pg_dump/psql on a test-only PATH. */
final class PostgresDriverTest extends TestCase
{
    private string $directory;

    private string $originalPath;

    private PostgresDriver $driver;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/backup-pgsql-' . \bin2hex(\random_bytes(6));
        \mkdir($this->directory);
        $this->originalPath = (string) \getenv('PATH');
        $this->driver = new PostgresDriver(new CommandExecutor());
    }

    protected function tearDown(): void
    {
        \putenv('PATH=' . $this->originalPath);
        $_SERVER['PATH'] = $this->originalPath;

        foreach (\glob($this->directory . '/*') ?: [] as $file) {
            @\unlink($file);
        }

        @\rmdir($this->directory);

        parent::tearDown();
    }

    private function prependToPath(): void
    {
        $path = $this->directory . ':' . $this->originalPath;
        \putenv('PATH=' . $path);
        $_SERVER['PATH'] = $path;
    }

    private function fakeDump(): void
    {
        $script = $this->directory . '/pg_dump';
        \file_put_contents($script, "#!/bin/sh\necho \"ARGS:\$@\"\necho \"PWD:\$PGPASSWORD\"\n");
        \chmod($script, 0o755);
        $this->prependToPath();
    }

    private function fakeClient(): string
    {
        $marker = $this->directory . '/received';
        $script = $this->directory . '/psql';
        \file_put_contents($script, "#!/bin/sh\necho \"ARGS:\$@\" > \"$marker\"\necho \"PWD:\$PGPASSWORD\" >> \"$marker\"\ncat >> \"$marker\"\n");
        \chmod($script, 0o755);
        $this->prependToPath();

        return $marker;
    }

    private function connection(): Connection
    {
        return new Connection(ConnectionConfig::of(
            'main',
            'pgsql:host=dbhost;port=5432;dbname=warehouse',
            'reader',
            'secret',
        ));
    }

    public function test_backup_passes_host_port_user_and_database(): void
    {
        $this->fakeDump();
        $connection = $this->connection();
        $destination = $this->directory . '/out.sql';

        $this->driver->backup($connection, Dsn::parse($connection->config()), $destination);

        $written = (string) \file_get_contents($destination);
        self::assertStringContainsString('-h dbhost', $written);
        self::assertStringContainsString('-p 5432', $written);
        self::assertStringContainsString('-U reader', $written);
        self::assertStringContainsString('warehouse', $written);
        self::assertStringContainsString('PWD:secret', $written);
    }

    public function test_restore_pipes_the_backup_file_to_psql_as_stdin(): void
    {
        $marker = $this->fakeClient();
        $connection = $this->connection();

        $source = $this->directory . '/backup.sql';
        \file_put_contents($source, "INSERT INTO t VALUES (1);\n");

        $this->driver->restore($connection, Dsn::parse($connection->config()), $source);

        $received = (string) \file_get_contents($marker);
        self::assertStringContainsString('-h dbhost', $received);
        self::assertStringContainsString('PWD:secret', $received);
        self::assertStringContainsString('INSERT INTO t VALUES (1);', $received);
    }
}
