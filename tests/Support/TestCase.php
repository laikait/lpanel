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
 * Grows as the framework does; right now its only job is to expose the project
 * root so tests can reason about real files on disk.
 */
abstract class TestCase extends PHPUnitTestCase
{
    protected function basePath(string $relative = ''): string
    {
        $base = \dirname(__DIR__, 2);

        return $relative === '' ? $base : $base . \DIRECTORY_SEPARATOR . $relative;
    }

    /** Where the showcase modules live: a plugin and a gateway, both called Example. */
    protected const SHOWCASE = 'tests/Fixtures/Showcase';

    /**
     * Build a real application over the real Bootstrap, with the showcase.
     *
     * Nothing is mocked: the container, the module manager, the router and both
     * engines are the production ones. The modules are the shipped shared
     * module plus the showcase -- Example and ExampleGateway, which
     * used to ship in modules/ and now live under tests/Fixtures/Showcase.
     * They are the only thing that exercises every subsystem through one
     * request, and an application does not need them to be installed.
     *
     * config/Example.php went with them, so the value it set is handed
     * to Bootstrap here instead. It arrives at the same layer -- above a
     * module's own defaults -- which is the thing the tests that read it prove.
     *
     * Error handling stays off so the handler does not take set_error_handler()
     * away from PHPUnit.
     *
     * @param array<string, mixed> $config
     */
    protected function application(array $config = []): Application
    {
        // The showcase's Shared first, and on its own: a test that brings its
        // own modules still wants it, because its accounts and tokens are the
        // ones every such test logs in with. The shipped one has none.
        $config['modules']['paths'] ??= [
            self::SHOWCASE . '/Shared',
            self::SHOWCASE . '/Plugins',
            self::SHOWCASE . '/Gateways',
        ];

        $config['Example']['page_size'] ??= 10;

        return $this->build($config);
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
     * @param array<string, mixed> $config
     */
    protected function shippedApplication(array $config = []): Application
    {
        return $this->build($config);
    }

    /** @param array<string, mixed> $config */
    private function build(array $config): Application
    {
        Extensions::reset();

        $config['app']['handle_errors'] = false;

        // A developer's own `php laika openapi:docs --enable` writes into this
        // project's system/Runtime; it must not change what the suite sees. A
        // test that wants the switches says so.
        $config['openapi']['runtime'] ??= false;

        // Rate-limit counts go to memory unless a test says otherwise.
        //
        // Two reasons, and the second is the one that bites. The file store
        // writes into the project's own system/Security, which a test run has
        // no business touching -- and those counts SURVIVE the run. A suite
        // that exercises a rate-limited route a few dozen times per run would
        // quietly accumulate hits until a later run started getting 429s from
        // counters left behind by an earlier one, which is a failure that looks
        // like flakiness and is not.
        //
        // ??= rather than =: a test that is specifically about the file store
        // still gets it by asking.
        $config['security']['counters'] ??= 'memory';

        // Sessions too, and for a sharper reason than the counters: a session
        // file is a live credential. A test run has no business leaving one on
        // the machine it ran on, in a directory somebody might later copy into
        // a backup or a support ticket.
        $config['session']['store'] ??= 'memory';

        return Bootstrap::create(
            $this->basePath(),
            ExecutionContext::http(['REQUEST_TIME_FLOAT' => \microtime(true)]),
            $config,
        );
    }

    /**
     * An application wired to the fixture modules rather than the real ones.
     *
     * @param array<string, mixed> $config
     */
    protected function fixtureApplication(array $config = []): Application
    {
        $config['modules']['paths'] = [
            'tests/Fixtures/Modules/Shared',
            'tests/Fixtures/Modules/Plugins',
            'tests/Fixtures/Modules/Gateways',
        ];

        return $this->build($config);
    }

    protected function tearDown(): void
    {
        // Extensions is the one piece of static framework state. Clearing it
        // keeps one test's listeners out of the next test's run, and proves the
        // helpers are wired rather than ambient.
        Extensions::reset();

        parent::tearDown();
    }
}
