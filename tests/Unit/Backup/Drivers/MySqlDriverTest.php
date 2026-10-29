<?php

declare(strict_types=1);

namespace App\Tests\Unit\Backup\Drivers;

use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionConfig;
use App\Engine\System\Command\CommandExecutor;
use App\Tests\Fixtures\Modules\Backup\Drivers\MySqlDriver;
use App\Tests\Fixtures\Modules\Backup\Dsn;
use App\Tests\Support\TestCase;

/**
 * There is no real mysqldump here -- a tiny shell script stands in for it,
 * on a PATH built just for this test, and writes down exactly what it was
 * called with. That is the only way to see what MySqlDriver actually built
 * without either running a real MySQL server or mocking CommandExecutor,
 * which is final and has nothing to mock through.
 */
final class MySqlDriverTest extends TestCase
{
    private string $directory;

    private string $originalPath;

    private MySqlDriver $driver;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/backup-mysql-' . \bin2hex(\random_bytes(6));
        \mkdir($this->directory);
        $this->originalPath = (string) \getenv('PATH');
        $this->driver = new MySqlDriver(new CommandExecutor());
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

    /**
     * Both putenv() and $_SERVER['PATH'] are set: MySqlDriver reads PATH
     * through Env::raw(), which checks $_ENV and $_SERVER before it ever
     * falls back to getenv() (engine/Config/Env.php) -- putenv() alone is
     * invisible to it as long as $_SERVER already holds a PATH, which it
     * does in every CLI SAPI.
     */
    private function prependToPath(): void
    {
        $path = $this->directory . ':' . $this->originalPath;
        \putenv('PATH=' . $path);
        $_SERVER['PATH'] = $path;
    }

    /** A fake `mysqldump` that records its argv and $MYSQL_PWD, then exits 0. */
    private function fakeDump(): void
    {
        $script = $this->directory . '/mysqldump';
        \file_put_contents($script, "#!/bin/sh\necho \"ARGS:\$@\"\necho \"PWD:\$MYSQL_PWD\"\n");
        \chmod($script, 0o755);
        $this->prependToPath();
    }

    /** A fake `mysql` that records its argv, $MYSQL_PWD, and stdin to a marker file. */
    private function fakeClient(): string
    {
        $marker = $this->directory . '/received';
        $script = $this->directory . '/mysql';
        \file_put_contents($script, "#!/bin/sh\necho \"ARGS:\$@\" > \"$marker\"\necho \"PWD:\$MYSQL_PWD\" >> \"$marker\"\ncat >> \"$marker\"\n");
        \chmod($script, 0o755);
        $this->prependToPath();

        return $marker;
    }

    private function connection(): Connection
    {
        return new Connection(ConnectionConfig::of(
            'main',
            'mysql:host=dbhost;port=3306;dbname=app;charset=utf8mb4',
            'appuser',
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
        self::assertStringContainsString('-P 3306', $written);
        self::assertStringContainsString('-u appuser', $written);
        self::assertStringContainsString('app', $written);
    }

    public function test_backup_passes_the_password_as_mysql_pwd_not_as_an_argument(): void
    {
        $this->fakeDump();
        $connection = $this->connection();
        $destination = $this->directory . '/out.sql';

        $this->driver->backup($connection, Dsn::parse($connection->config()), $destination);

        $written = (string) \file_get_contents($destination);
        self::assertStringContainsString('PWD:secret', $written);
        self::assertStringNotContainsString('ARGS:' . \PHP_EOL . 'secret', $written);
    }

    public function test_restore_pipes_the_backup_file_to_mysql_as_stdin(): void
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
