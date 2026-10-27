<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Engine\Core\Application;
use App\Engine\Mail\Transport;
use App\Engine\Mail\Transports\ArrayTransport;
use App\Engine\Migration\Migrator;
use App\Engine\Security\Signer;

/**
 * A test of LPanel itself: the modules in modules/, on a fresh in-memory
 * SQLite database with every migration run.
 *
 * These boot what ships, so what they prove is what an installation runs.
 * With LPANEL_TEST_DB_DSN set they run on that MariaDB instead, which is how
 * test/mariadb/run.sh checks 10.11 and 11.8 (ADR-0008).
 */
abstract class ApplicationTestCase extends TestCase
{
    /** Root's password in tests, checked by DevRootVerifier: no PAM, no lpanel-authd. */
    protected const ROOT_PASSWORD = 'root-test-password';

    private ?Application $app = null;

    private static ?string $rootHash = null;

    /** @param array<string, mixed> $config */
    protected function app(array $config = []): Application
    {
        if ($this->app !== null && $config === []) {
            return $this->app;
        }

        $config['database']['connections']['default'] ??= self::database();
        $config['security']['key'] ??= Signer::generate();
        $config['app']['url'] ??= 'https://panel.test';
        // Kept in memory and sent inline (the queue is "sync"), so a test can read what was mailed.
        $config['mail']['transport'] ??= 'array';
        $config['mail']['from']['address'] ??= 'panel@panel.test';
        $config['Identity']['root']['verifier'] ??= 'dev';
        $config['Identity']['root']['dev_password_hash'] ??= self::$rootHash ??= \password_hash(self::ROOT_PASSWORD, \PASSWORD_DEFAULT);

        $app = $this->shippedApplication($config)->boot();
        $app->container()->get(Migrator::class)->migrate();

        return $this->app = $app;
    }

    /**
     * In-memory SQLite, or the MariaDB that LPANEL_TEST_DB_DSN names (test/mariadb/run.sh).
     *
     * A server database is emptied first, so every test starts from the
     * migrations as an installation does. Point it at a scratch database only.
     *
     * @return array{dsn: string, username?: ?string, password?: ?string}
     */
    private static function database(): array
    {
        $dsn = \getenv('LPANEL_TEST_DB_DSN');

        if (!\is_string($dsn) || $dsn === '') {
            return ['dsn' => 'sqlite::memory:'];
        }

        $username = \getenv('LPANEL_TEST_DB_USERNAME') ?: null;
        $password = \getenv('LPANEL_TEST_DB_PASSWORD') ?: null;
        $pdo = new \PDO($dsn, $username, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $tables = $pdo->query('SHOW TABLES');

        foreach ($tables === false ? [] : $tables->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE `' . \str_replace('`', '``', (string) $table) . '`');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        return ['dsn' => $dsn, 'username' => $username, 'password' => $password];
    }

    /** The mail the application sent, newest last, as raw MIME with quoted-printable undone. */
    protected function mailTo(string $address): ?string
    {
        $transport = $this->service(Transport::class);
        self::assertInstanceOf(ArrayTransport::class, $transport);
        $found = null;

        foreach ($transport->sent() as $envelope) {
            if (\in_array($address, $envelope->recipients, true)) {
                $found = \quoted_printable_decode($envelope->raw);
            }
        }

        return $found;
    }

    /** A browser on the booted application. */
    protected function browser(string $ip = '203.0.113.10'): Browser
    {
        return new Browser($this->app(), $ip);
    }

    /**
     * A service from the booted application.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function service(string $class): object
    {
        $service = $this->app()->container()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    protected function tearDown(): void
    {
        $this->app = null;

        parent::tearDown();
    }
}
