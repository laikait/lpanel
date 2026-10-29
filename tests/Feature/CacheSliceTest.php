<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Asset\AssetManager;
use App\Engine\Cache\Cache;
use App\Engine\Cache\CacheStore;
use App\Engine\Cache\Stores\ArrayStore;
use App\Engine\Cache\Stores\FileStore;
use App\Engine\Cache\Stores\NullStore;
use App\Engine\Cli\CommandDispatcher;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\ConsoleKernel;
use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Engine\Error\ErrorHandler;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Request;
use App\Engine\Template\TemplateManager;
use App\Tests\Fixtures\Showcase\Plugins\Example\Data\CustomerQuery;
use App\Tests\Support\TestCase;

/**
 * The cache through a real application.
 *
 * Two things are being proved. The wiring: which store an application ends up
 * with is configuration, and everything that caches is handed a namespace of it
 * rather than reaching for one. And the point of the exercise: work done in one
 * request is not done again in the next.
 */
final class CacheSliceTest extends TestCase
{
    private function dataDirectory(): string
    {
        return $this->basePath('system/Cache/data');
    }

    protected function tearDown(): void
    {
        $directory = $this->dataDirectory();

        if (\is_dir($directory)) {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($entries as $entry) {
                if ($entry instanceof \SplFileInfo) {
                    $entry->isDir() ? @\rmdir($entry->getPathname()) : @\unlink($entry->getPathname());
                }
            }

            @\rmdir($directory);
        }

        parent::tearDown();
    }

    /** @param array<string, mixed> $config */
    private function app(array $config = []): Application
    {
        return $this->application($config)->boot();
    }

    // ---- wiring --------------------------------------------------------------

    public function test_a_cache_is_injectable(): void
    {
        $cache = $this->app()->container()->get(Cache::class);

        self::assertInstanceOf(Cache::class, $cache);
        self::assertInstanceOf(ArrayStore::class, $cache->store(), 'memory is the default');
    }

    public function test_the_store_is_a_configuration_decision(): void
    {
        self::assertInstanceOf(
            FileStore::class,
            $this->app(['cache' => ['store' => 'file']])->container()->get(Cache::class)->store(),
        );

        self::assertInstanceOf(
            NullStore::class,
            $this->app(['cache' => ['store' => 'null']])->container()->get(Cache::class)->store(),
        );
    }

    /** A typo in a deployment's configuration must not stop the application. */
    public function test_an_unknown_store_falls_back_rather_than_failing(): void
    {
        self::assertInstanceOf(
            ArrayStore::class,
            $this->app(['cache' => ['store' => 'reddis']])->container()->get(Cache::class)->store(),
        );
    }

    public function test_the_store_is_injectable_on_its_own(): void
    {
        $container = $this->app()->container();

        self::assertSame(
            $container->get(Cache::class)->store(),
            $container->get(CacheStore::class),
            'a class that wants the backend rather than the behaviour gets the same object',
        );
    }

    public function test_the_default_lifetime_comes_from_configuration(): void
    {
        self::assertSame(
            120,
            $this->app(['cache' => ['ttl' => 120]])->container()->get(Cache::class)->defaultTtl(),
        );
    }

    public function test_the_file_store_writes_where_it_says_it_does(): void
    {
        $cache = $this->app(['cache' => ['store' => 'file']])->container()->get(Cache::class);

        $cache->set('probe', 'written by a test');

        self::assertDirectoryExists($this->dataDirectory());
        self::assertSame('written by a test', $cache->get('probe'));
    }

    // ---- what the framework itself caches ---------------------------------------

    /**
     * A resolved template name survives the application that resolved it.
     *
     * Two applications over one file store stand in for two requests, which is
     * what this is for: the search is directories times extensions of is_file,
     * and a page with a layout and six partials does it seven times.
     */
    public function test_template_resolution_carries_into_the_next_request(): void
    {
        $first = $this->app(['cache' => ['store' => 'file']]);
        $file = $first->container()->get(TemplateManager::class)->locate('errors/404');

        $second = $this->app(['cache' => ['store' => 'file']]);
        $cached = $second->container()->get(Cache::class)->namespace('templates')->get(\hash('xxh128', 'errors/404'));

        self::assertNotNull($cached);
        self::assertSame($file->absolutePath, $second->container()->get(TemplateManager::class)
            ->locate('errors/404')->absolutePath);
    }

    /**
     * A cache that outlived the layout it describes must degrade into a slow
     * lookup, never into a missing template.
     */
    public function test_a_remembered_template_that_no_longer_exists_is_looked_up_again(): void
    {
        $app = $this->app(['cache' => ['store' => 'file']]);
        $templates = $app->container()->get(TemplateManager::class);
        $expected = $templates->locate('errors/404')->absolutePath;

        // Poison the cache with a resolution pointing at nothing.
        $app->container()->get(Cache::class)->namespace('templates')->set(
            \hash('xxh128', 'errors/404'),
            new \App\Engine\Template\TemplateFile(
                name: 'errors/404',
                root: $this->basePath('templates'),
                relativePath: 'gone.php',
                absolutePath: $this->basePath('templates/gone.php'),
                extension: 'php',
            ),
        );

        $fresh = $this->app(['cache' => ['store' => 'file']]);

        self::assertSame($expected, $fresh->container()->get(TemplateManager::class)->locate('errors/404')->absolutePath);
    }

    public function test_an_asset_version_token_survives_into_the_next_request(): void
    {
        $app = $this->app(['cache' => ['store' => 'file'], 'app' => ['debug' => false]]);
        $url = $app->container()->get(AssetManager::class)->core('js/app.js');

        self::assertMatchesRegularExpression('/\?v=[0-9a-f]+$/', $url);

        $next = $this->app(['cache' => ['store' => 'file'], 'app' => ['debug' => false]]);

        self::assertSame($url, $next->container()->get(AssetManager::class)->core('js/app.js'));
        self::assertNotSame(
            [],
            \glob($this->dataDirectory() . '/assets/*') ?: [],
            'the token was kept, so the file was not hashed again',
        );
    }

    /**
     * Not in development. A hash that outlives the file it describes would mean
     * editing a stylesheet and having the browser keep the old one, which is
     * the exact failure content hashing exists to prevent.
     */
    public function test_asset_tokens_are_not_cached_while_debugging(): void
    {
        $app = $this->app(['cache' => ['store' => 'file'], 'app' => ['debug' => true]]);

        $app->container()->get(AssetManager::class)->core('js/app.js');

        self::assertSame([], \glob($this->dataDirectory() . '/assets/*') ?: []);
    }

    // ---- what a module does with it ------------------------------------------------

    public function test_a_module_caches_in_its_own_namespace(): void
    {
        $app = $this->app();
        $customers = $app->container()->get(CustomerQuery::class);

        self::assertSame(3, $customers->total());
        self::assertSame(
            3,
            $app->container()->get(Cache::class)->namespace('plugins.example')->get(CustomerQuery::TOTAL),
        );
    }

    /**
     * A TTL is a backstop, not a plan. What knows the count has changed is the
     * event saying a customer was created, so that is what clears it.
     */
    public function test_creating_a_customer_invalidates_the_cached_count(): void
    {
        $app = $this->app();
        $customers = $app->container()->get(CustomerQuery::class);
        $cache = $app->container()->get(Cache::class)->namespace('plugins.example');

        self::assertSame(3, $customers->total());
        self::assertTrue($cache->has(CustomerQuery::TOTAL));

        $app->handle(Request::create('POST', '/api/v1/customers', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"name":"Ada Second","email":"ada2@example.test"}',
        ]));

        // The cached value is right rather than absent, and the difference is
        // worth reading. The hook clears it; the job the same request dispatches
        // asks for the count again, and with the default synchronous store that
        // happens before the response is sent. What is being asserted is the
        // thing that matters -- nobody is served a stale three.
        self::assertSame(4, $cache->get(CustomerQuery::TOTAL), 'cleared by the hook, recomputed by the job');
        self::assertSame(4, $customers->total());
    }

    public function test_a_module_cannot_clear_another_modules_entries(): void
    {
        $cache = $this->app()->container()->get(Cache::class);

        $cache->namespace('plugins.example')->set('kept', 'yes');
        $cache->namespace('gateways.example')->clear();

        self::assertSame('yes', $cache->namespace('plugins.example')->get('kept'));
    }

    // ---- the console -----------------------------------------------------------------

    public function test_cache_clear_empties_the_application_cache(): void
    {
        $app = $this->app(['cache' => ['store' => 'file']]);
        $cache = $app->container()->get(Cache::class);
        $cache->set('probe', 'value');

        [$status, $output] = $this->console($app, 'cache:clear');

        self::assertSame(ConsoleKernel::SUCCESS, $status);
        self::assertStringContainsString('file', $output, 'it reports which store it just cleared');
        self::assertFalse($cache->has('probe'));
    }

    public function test_cache_clear_expired_keeps_what_is_still_valid(): void
    {
        $app = $this->app(['cache' => ['store' => 'file']]);
        $cache = $app->container()->get(Cache::class);

        $cache->set('stale', 'value', -1);
        $cache->set('fresh', 'value', 600);

        [$status, $output] = $this->console($app, 'cache:clear', '--expired');

        self::assertSame(ConsoleKernel::SUCCESS, $status);
        self::assertStringContainsString('1 expired entry removed', $output);
        self::assertSame('value', $cache->get('fresh'));
    }

    /** @return array{int, string} */
    private function console(Application $app, string ...$arguments): array
    {
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        $status = (new ConsoleKernel(
            $app->container()->get(CommandRegistry::class),
            $app->container()->get(CommandDispatcher::class),
            $app->container()->get(HookEngine::class),
            $app->container()->get(ErrorHandler::class),
            new Output($stream),
        ))->handle(ExecutionContext::cli(\array_values(['laika', ...$arguments])));

        \rewind($stream);
        $output = (string) \stream_get_contents($stream);
        \fclose($stream);

        return [$status, $output];
    }
}
