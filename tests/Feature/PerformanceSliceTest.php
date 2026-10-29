<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\AuthManager;
use App\Engine\Bootstrap\Bootstrap;
use App\Engine\Cli\CommandDispatcher;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\ConsoleKernel;
use App\Engine\Cli\Output;
use App\Engine\Config\ConfigCache;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Engine\Error\ErrorHandler;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Request;
use App\Engine\Module\ModuleManager;
use App\Engine\Module\ModuleRegistry;
use App\Engine\Routing\Router;
use App\Engine\Support\Extensions;
use App\Tests\Benchmark\Suite;
use App\Tests\Support\TestCase;

/**
 * Phase 27, end to end: what is NOT done for a request, asserted exactly.
 *
 * Timings belong to the benchmarks, which run on whatever machine is in front
 * of them and cannot fail a build honestly. What can -- and what matters more --
 * is whether work happens at all: whether a stylesheet loads a module, whether a
 * public page builds the authentication layer, whether a warmed boot walks a
 * directory. Those are facts on every machine, so they are the part of
 * "performance must not be based only on theoretical architecture" that lives in
 * the gate.
 */
final class PerformanceSliceTest extends TestCase
{
    private ?string $temporary = null;

    protected function tearDown(): void
    {
        if ($this->temporary !== null) {
            $this->remove($this->temporary);
        }

        parent::tearDown();
    }

    // ---- lazy loading ------------------------------------------------------

    /**
     * The specification's own example: "/assets/... should not initialize
     * billing". Nothing a module declares is loaded -- no context, no route,
     * no onBoot -- and the plugin's file is still served, because publishing it
     * never needed the module's code.
     */
    public function test_an_asset_request_loads_no_module(): void
    {
        $app = $this->application();

        $response = $app->handle($this->request('/assets/module/Example/js/example.js'));

        self::assertSame(200, $response->status());
        self::assertFalse($app->isBooted());

        $container = $app->container();
        self::assertSame([], $container->get(ModuleManager::class)->registry()->contexts(), 'a module.php ran');
        self::assertSame(0, $container->get(Router::class)->count(), 'a module registered its routes');
        self::assertSame(0, $container->get(HookEngine::class)->didCount('app.booted'));
        self::assertFalse($container->resolved(AuthManager::class));
    }

    public function test_the_engine_still_attends_to_an_asset_response(): void
    {
        $response = $this->application()->handle($this->request('/assets/core/css/app.css'));

        self::assertSame(200, $response->status());
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'), 'the engine\'s security headers ran');
    }

    /** A process that serves an asset and then a page must serve both properly. */
    public function test_a_page_after_an_asset_boots_everything_it_skipped(): void
    {
        $app = $this->application();

        self::assertSame(200, $app->handle($this->request('/assets/module/Example/js/example.js'))->status());
        self::assertSame(200, $app->handle($this->request('/customers.json'))->status());

        self::assertTrue($app->isBooted());
        self::assertGreaterThan(0, $app->container()->get(Router::class)->count());
        self::assertSame(200, $app->handle($this->request('/assets/module/Example/js/example.js'))->status());
    }

    /**
     * Measuring found this one: the listener that tells the authentication
     * layer about each request built it to do so, and with it the user provider
     * and the shared module's repository -- on every request.
     */
    public function test_a_public_page_does_not_build_the_authentication_layer(): void
    {
        $app = $this->application();

        self::assertSame(200, $app->handle($this->request('/customers.json'))->status());
        self::assertFalse($app->container()->resolved(AuthManager::class));
    }

    /** ...and one built part-way through a request still knows which request it is. */
    public function test_an_authentication_layer_built_late_still_sees_the_request(): void
    {
        $response = $this->application()->handle($this->request('/me', [
            'headers' => ['Authorization' => 'Bearer ada-token-do-not-use'],
        ]));

        self::assertSame(200, $response->status());
    }

    // ---- the production boot path ------------------------------------------

    /**
     * cache:warm builds both caches, and the next process boots from them:
     * configuration from one file, modules from another, no directory walked.
     */
    public function test_cache_warm_builds_the_boot_path_the_next_process_uses(): void
    {
        $base = $this->productionTree();

        [$status, $output] = $this->console($this->treeApplication($base), 'cache:warm');

        self::assertSame(ConsoleKernel::SUCCESS, $status, $output);
        self::assertFileExists(ConfigCache::file($base));
        self::assertFileExists(ModuleRegistry::cacheFile($base));
        self::assertStringContainsString('not cached', $output, 'what it does not cache is said, not implied');

        $next = $this->treeApplication($base)->boot();
        $manager = $next->container()->get(ModuleManager::class);

        self::assertTrue($manager->discoveredFromCache());
        self::assertSame(['Shared', 'Alpha', 'Beta', 'Zeta'], $manager->registry()->ids());

        [, $about] = $this->console($next, 'about');
        self::assertStringContainsString('config cached, modules cached', $about);
    }

    public function test_cache_warm_refuses_in_a_debug_process(): void
    {
        $base = $this->productionTree();

        [$status, $output] = $this->console($this->treeApplication($base, ['app' => ['debug' => true]]), 'cache:warm');

        self::assertSame(1, $status);
        self::assertStringContainsString('APP_DEBUG', $output);
        self::assertFileDoesNotExist(ConfigCache::file($base));
        self::assertFileDoesNotExist(ModuleRegistry::cacheFile($base));
    }

    /**
     * The process was told debug is off, but the files it would cache say on.
     * Caching them anyway would deploy a debug configuration.
     */
    public function test_cache_warm_refuses_a_configuration_on_disk_that_turns_debug_on(): void
    {
        $base = $this->productionTree(debug: true);

        [$status] = $this->console($this->treeApplication($base, ['app' => ['debug' => false]]), 'cache:warm');

        self::assertSame(1, $status);
        self::assertFileDoesNotExist(ConfigCache::file($base));
    }

    public function test_about_says_when_the_boot_path_is_not_cached(): void
    {
        [, $output] = $this->console($this->application()->boot(), 'about');

        self::assertStringContainsString('modules scanned (debug never reads the cache)', $output);
    }

    // ---- benchmarks ----------------------------------------------------------

    /**
     * The benchmarks are not run here -- a timing cannot fail a build honestly
     * -- but every one of them is prepared, so a benchmark broken by a change is
     * found by the gate rather than by the next person who runs composer bench.
     */
    public function test_every_benchmark_can_be_prepared(): void
    {
        foreach (Suite::all($this->basePath()) as $benchmark) {
            $subject = ($benchmark->prepare)();

            self::assertInstanceOf(\Closure::class, $subject, $benchmark->key());
            $subject();
        }

        Extensions::reset();
    }

    // ---- helpers -------------------------------------------------------------

    /** @param array<string, mixed> $options */
    private function request(string $path, array $options = []): Request
    {
        return Request::create('GET', $path, ['server' => ['SCRIPT_NAME' => '/index.php'], ...$options]);
    }

    /**
     * An application directory of its own, configured the way a production
     * deployment would be: debug off in config/app.php, and module roots in
     * config/modules.php. The fixture modules stand in for real ones.
     */
    private function productionTree(bool $debug = false): string
    {
        $base = \str_replace('\\', '/', \sys_get_temp_dir()) . '/performance-slice-' . \bin2hex(\random_bytes(6));
        $this->temporary = $base;

        \mkdir($base . '/config', 0o777, true);

        \file_put_contents(
            $base . '/config/app.php',
            "<?php\n\nreturn ['debug' => " . \var_export($debug, true) . "];\n",
        );

        \file_put_contents($base . '/config/modules.php', "<?php\n\nreturn " . \var_export(['paths' => [
            $this->fixture('Shared'),
            $this->fixture('Plugins'),
            $this->fixture('Gateways'),
        ]], true) . ";\n");

        return $base;
    }

    private function fixture(string $root): string
    {
        return \str_replace('\\', '/', $this->basePath('tests/Fixtures/Modules/' . $root));
    }

    /** @param array<string, mixed> $config */
    private function treeApplication(string $base, array $config = []): Application
    {
        Extensions::reset();

        return Bootstrap::create(
            $base,
            ExecutionContext::cli(['laika']),
            \array_replace_recursive([
                'app' => ['handle_errors' => false],
                'security' => ['counters' => 'memory'],
                'session' => ['store' => 'memory'],
                'scheduler' => ['lock' => 'memory'],
            ], $config),
        );
    }

    /** @return array{int, string} */
    private function console(Application $app, string ...$arguments): array
    {
        $app->boot();
        $container = $app->container();

        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        $status = (new ConsoleKernel(
            $container->get(CommandRegistry::class),
            $container->get(CommandDispatcher::class),
            $container->get(HookEngine::class),
            $container->get(ErrorHandler::class),
            new Output($stream),
        ))->handle(ExecutionContext::cli(\array_values(['laika', ...$arguments])));

        \rewind($stream);
        $output = (string) \stream_get_contents($stream);
        \fclose($stream);

        return [$status, $output];
    }

    private function remove(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $entry->isDir() ? @\rmdir($entry->getPathname()) : @\unlink($entry->getPathname());
            }
        }

        @\rmdir($path);
    }
}
