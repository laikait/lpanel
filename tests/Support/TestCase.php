<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Engine\Bootstrap\Bootstrap;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Engine\Migration\Migrator;
use App\Engine\Support\Extensions;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base test case.
 *
 * Exposes the project root so tests can reason about real files on disk, and
 * builds a real application over the real Bootstrap: the container, the module
 * manager, the router and both engines are the production ones.
 */
abstract class TestCase extends PHPUnitTestCase
{
    protected function basePath(string $relative = ''): string
    {
        $base = \dirname(__DIR__, 2);

        return $relative === '' ? $base : $base . \DIRECTORY_SEPARATOR . $relative;
    }

    /**
     * Run every module's migrations on a booted application, as
     * `php laika migrate` would: the same tables in a test as in production.
     */
    protected function migrate(Application $app): void
    {
        $app->container()->get(Migrator::class)->migrate();
    }

    /**
     * Exactly what ships: modules/ as it is on disk, nothing added.
     *
     * Error handling stays off so the handler does not take set_error_handler()
     * away from PHPUnit.
     *
     * @param array<string, mixed> $config
     */
    protected function shippedApplication(array $config = []): Application
    {
        Extensions::reset();

        $config['app']['handle_errors'] = false;

        // A developer's own `php laika openapi:docs --enable` writes into this
        // project's system/Runtime; it must not change what the suite sees. A
        // test that wants the switches says so.
        $config['openapi']['runtime'] ??= false;

        // Rate-limit counts go to memory unless a test says otherwise. The file
        // store writes into the project's own system/Security, and those counts
        // survive the run: a suite that hits a rate-limited route often enough
        // would start getting 429s from counters an earlier run left behind.
        $config['security']['counters'] ??= 'memory';

        // Sessions too: a session file is a live credential, and a test run has
        // no business leaving one on the machine it ran on.
        $config['session']['store'] ??= 'memory';

        return Bootstrap::create(
            $this->basePath(),
            ExecutionContext::http(['REQUEST_TIME_FLOAT' => \microtime(true)]),
            $config,
        );
    }

    protected function tearDown(): void
    {
        // Extensions is the one piece of static framework state. Clearing it
        // keeps one test's listeners out of the next test's run.
        Extensions::reset();

        parent::tearDown();
    }
}
