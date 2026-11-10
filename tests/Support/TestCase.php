<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Engine\Bootstrap\Bootstrap;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Engine\Migration\Migrator;
use App\Engine\Security\Secret;
use App\Engine\Support\Extensions;
use App\Modules\Agent\AgentKey;
use App\Modules\Agent\Transport;
use App\Modules\Identity\Root\RootCheck;
use App\Modules\Identity\Root\RootPasswordVerifier;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base for LPanel's tests: the real application, with every module in
 * modules/, over a fresh in-memory SQLite database with all migrations run.
 *
 * Sessions and rate-limit counters stay in memory, so a test run writes
 * nothing to system/ and no test inherits another's 429.
 */
abstract class TestCase extends PHPUnitTestCase
{
    /** @param array<string, mixed> $config */
    protected function app(array $config = []): Application
    {
        Extensions::reset();

        $config['app']['handle_errors'] = false;
        $config['app']['debug'] ??= false;
        $config['app']['env'] ??= 'testing';
        $config['security']['key'] ??= 'base64:' . \base64_encode(\str_repeat('k', 32));
        $config['database']['connections']['default']['dsn'] ??= 'sqlite::memory:';
        $config['security']['counters'] ??= 'memory';
        $config['session']['store'] ??= 'memory';
        $config['openapi']['runtime'] ??= false;

        $app = Bootstrap::create(
            \dirname(__DIR__, 2),
            ExecutionContext::http(['REQUEST_TIME_FLOAT' => \microtime(true)]),
            $config,
        )->boot();

        $app->container()->get(Migrator::class)->migrate();

        return $app;
    }

    /** A server's authd, without a server: root's password is $password. */
    protected function rootPassword(Application $app, string $password): void
    {
        $app->container()->instance(RootPasswordVerifier::class, new class ($password) implements RootPasswordVerifier {
            public function __construct(private readonly string $password) {}

            public function check(Secret $password, ?string $ip): RootCheck
            {
                return \hash_equals($this->password, $password->reveal()) ? RootCheck::allowed() : RootCheck::denied();
            }
        });
    }

    /** Answer agent requests from $agent instead of a socket. */
    protected function agent(Application $app, FakeAgent $agent): void
    {
        $app->container()->instance(Transport::class, $agent);
        $app->container()->instance(AgentKey::class, $agent->key);
    }

    protected function browser(Application $app): Browser
    {
        return new Browser($app);
    }

    protected function tearDown(): void
    {
        Extensions::reset();

        parent::tearDown();
    }
}
