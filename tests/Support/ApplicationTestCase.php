<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Engine\Core\Application;
use App\Engine\Migration\Migrator;
use App\Engine\Security\Signer;

/**
 * A test of LPanel itself: the modules in modules/, on a fresh in-memory
 * SQLite database with every migration run.
 *
 * These boot what ships, so what they prove is what an installation runs. A
 * test that needs MySQL's behaviour sets DB_TEST_MYSQL_DSN and asks for it.
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

        $config['database']['connections']['default']['dsn'] ??= 'sqlite::memory:';
        $config['security']['key'] ??= Signer::generate();
        $config['Identity']['root']['verifier'] ??= 'dev';
        $config['Identity']['root']['dev_password_hash'] ??= self::$rootHash ??= \password_hash(self::ROOT_PASSWORD, \PASSWORD_DEFAULT);

        $app = $this->shippedApplication($config)->boot();
        $app->container()->get(Migrator::class)->migrate();

        return $this->app = $app;
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
