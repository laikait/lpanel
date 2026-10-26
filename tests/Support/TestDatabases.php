<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Engine\Database\ConnectionConfig;

/**
 * The databases a test run can reach.
 *
 * SQLite in memory always, because it needs nothing installed. A database
 * server only when the environment names one:
 *
 *     DB_TEST_MYSQL_DSN   DB_TEST_MYSQL_USERNAME   DB_TEST_MYSQL_PASSWORD
 *     DB_TEST_PGSQL_DSN   DB_TEST_PGSQL_USERNAME   DB_TEST_PGSQL_PASSWORD
 *     DB_TEST_SQLSRV_DSN  DB_TEST_SQLSRV_USERNAME  DB_TEST_SQLSRV_PASSWORD
 *
 * The tests create and drop their own tables in that database, so point these
 * at a scratch database and never at one that matters.
 *
 * A server that is named but unreachable, or whose PDO driver is missing,
 * fails its tests rather than skipping them: a CI job that set the variable
 * meant it, and a skip there would be a green build that tested nothing.
 */
final class TestDatabases
{
    /** @var array<string, string> PDO driver => environment prefix */
    private const SERVERS = [
        'mysql' => 'DB_TEST_MYSQL',
        'pgsql' => 'DB_TEST_PGSQL',
        'sqlsrv' => 'DB_TEST_SQLSRV',
    ];

    /** @return array<string, array{ConnectionConfig}> keyed by driver, for a data provider */
    public static function available(): array
    {
        $databases = [];

        if (\in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $databases['sqlite'] = [ConnectionConfig::of('sqlite', 'sqlite::memory:')];
        }

        foreach (self::SERVERS as $driver => $prefix) {
            $dsn = self::variable($prefix . '_DSN');

            if ($dsn !== null) {
                $databases[$driver] = [ConnectionConfig::of(
                    $driver,
                    $dsn,
                    self::variable($prefix . '_USERNAME'),
                    self::variable($prefix . '_PASSWORD'),
                )];
            }
        }

        return $databases;
    }

    private static function variable(string $name): ?string
    {
        $value = \getenv($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
