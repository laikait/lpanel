<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Engine\Core\Application;
use App\Engine\Migration\Migrator;
use App\Engine\Security\Signer;

/**
 * A test of Laika Bill Manager itself: the modules in modules/, on a fresh
 * in-memory SQLite database with every migration run.
 *
 * The engine's own tests boot the showcase instead (TestCase::application());
 * these boot what ships, so what they prove is what an installation runs. A
 * test that needs MySQL's behaviour sets DB_TEST_MYSQL_DSN and asks for it.
 */
abstract class ApplicationTestCase extends TestCase
{
    /** A fixed vault key: tests never read the machine's. */
    protected const VAULT_KEY = 'test-vault-key-0123456789abcdef0123456789abcdef';

    private ?Application $app = null;

    /** @param array<string, mixed> $config */
    protected function app(array $config = []): Application
    {
        if ($this->app !== null && $config === []) {
            return $this->app;
        }

        $config['database']['connections']['default']['dsn'] ??= 'sqlite::memory:';
        $config['security']['key'] ??= Signer::generate();
        $config['Shared']['vault_key'] ??= self::VAULT_KEY;

        $app = $this->shippedApplication($config)->boot();
        $app->container()->get(Migrator::class)->migrate();

        return $this->app = $app;
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
