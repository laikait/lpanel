<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Asset\AssetKind;
use App\Engine\Asset\AssetManager;
use App\Engine\Asset\AssetRegistry;
use App\Engine\Core\HttpKernel;
use App\Engine\Filter\FilterEngine;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Tests\Support\TestCase;

/**
 * The asset layer through the real application.
 *
 * Nothing is mocked: a real Bootstrap, real module discovery, the real kernel
 * and the real demo modules. What this proves is the claim the phase is for --
 * that a plugin's assets/ directory, inside the modules/ tree that .htaccess
 * denies outright, is reachable through a URL that names no directory at all.
 */
final class AssetSliceTest extends TestCase
{
    private function kernel(string $basePath = ''): HttpKernel
    {
        $application = $this->application(
            $basePath === '' ? [] : ['http' => ['base_path' => $basePath]],
        );
        $application->boot();

        $kernel = $application->container()->get(HttpKernel::class);
        self::assertInstanceOf(HttpKernel::class, $kernel);

        return $kernel;
    }

    /** @param array<string, string> $headers */
    private function handle(HttpKernel $kernel, string $uri, array $headers = [], string $method = 'GET'): Response
    {
        return $kernel->handle(Request::create($method, $uri, ['headers' => $headers]));
    }

    private function bodyOf(Response $response): string
    {
        // Two buffers: an asset body is streamed and flushes into the
        // enclosing one. See AssetServerTest for the long version.
        \ob_start();
        \ob_start();

        $response->send();

        \ob_end_flush();

        return (string) \ob_get_clean();
    }

    // ---- publication ------------------------------------------------------

    /**
     * A module is published because it has an assets/ directory, not because it
     * asked to be. There is nothing in any module.php about assets.
     */
    public function test_modules_with_an_assets_directory_are_published_automatically(): void
    {
        $application = $this->application();
        $registry = $application->container()->get(AssetRegistry::class);
        self::assertInstanceOf(AssetRegistry::class, $registry);

        self::assertFalse($registry->has(AssetKind::Module, 'Example'), 'nothing is published before boot');

        $application->boot();

        self::assertTrue($registry->has(AssetKind::Module, 'Example'));
        self::assertTrue($registry->has(AssetKind::Module, 'Example'));
        self::assertTrue($registry->has(AssetKind::Core));

        $source = $registry->source(AssetKind::Module, 'Example');
        self::assertStringEndsWith(self::SHOWCASE . '/Plugins/Example/assets', $source->root);
    }

    public function test_the_shared_module_publishes_nothing(): void
    {
        $application = $this->application();
        $application->boot();

        $registry = $application->container()->get(AssetRegistry::class);
        self::assertInstanceOf(AssetRegistry::class, $registry);

        foreach ($registry->all() as $source) {
            self::assertNotSame('Shared', $source->name, 'the showcase Shared has no assets/, so it publishes nothing');
        }
    }

    // ---- the developer API ------------------------------------------------

    public function test_the_global_helper_builds_the_same_url_as_the_injected_manager(): void
    {
        $application = $this->application();
        $application->boot();

        $manager = $application->container()->get(AssetManager::class);
        self::assertInstanceOf(AssetManager::class, $manager);

        self::assertSame($manager->core('css/app.css'), asset()->core('css/app.css'));
        self::assertSame(
            $manager->module('Example', 'js/example.js'),
            asset()->module('Example', 'js/example.js'),
        );
    }

    /**
     * The showcase plugin's index builds its asset URLs with the global helper,
     * from inside a closure written in module.php -- the case the helper exists
     * for.
     */
    public function test_the_showcase_index_advertises_its_assets(): void
    {
        $response = $this->handle($this->kernel(), '/links.json');

        self::assertSame(200, $response->status());

        /** @var array{assets: array<string, string>} $payload */
        $payload = \json_decode($this->bodyOf($response), true, 16, \JSON_THROW_ON_ERROR);

        self::assertMatchesRegularExpression('#^/assets/core/css/app\.css\?v=#', $payload['assets']['app.css']);
        self::assertMatchesRegularExpression('#^/assets/module/Example/js/example\.js\?v=#', $payload['assets']['plugin.js']);
        self::assertMatchesRegularExpression('#^/assets/module/ExampleGateway/js/gateway\.js\?v=#', $payload['assets']['gateway.js']);

        foreach ($payload['assets'] as $url) {
            self::assertStringNotContainsString('modules', $url, 'the URL leaks the module directory');
        }
    }

    // ---- delivery through the kernel --------------------------------------

    /**
     * The whole point of the phase, in one assertion.
     *
     * modules/Plugins/Example/assets/js/example.js sits inside a directory the
     * web server is configured to refuse, and it comes back over HTTP anyway --
     * through a URL that says "the plugin called Example" and nothing about
     * where that plugin is.
     */
    public function test_a_modules_asset_is_served_from_inside_the_denied_module_tree(): void
    {
        $response = $this->handle($this->kernel(), '/assets/module/Example/js/example.js');

        self::assertSame(200, $response->status());
        self::assertSame('text/javascript; charset=UTF-8', $response->header('Content-Type'));
        self::assertStringContainsString('export const customers', $this->bodyOf($response));
    }

    public function test_another_module_serves_its_own_file(): void
    {
        $plugin = $this->bodyOf($this->handle($this->kernel(), '/assets/module/Example/js/example.js'));
        $gateway = $this->bodyOf($this->handle($this->kernel(), '/assets/module/ExampleGateway/js/gateway.js'));

        self::assertNotSame($plugin, $gateway);
        self::assertStringContainsString('audit', $gateway);
    }

    public function test_the_applications_own_assets_are_served_too(): void
    {
        $response = $this->handle($this->kernel(), '/assets/core/css/app.css');

        self::assertSame(200, $response->status());
        self::assertSame('text/css; charset=UTF-8', $response->header('Content-Type'));
    }

    /**
     * The asset prefix is answered before routing, and everything else is
     * untouched by it.
     */
    public function test_assets_do_not_disturb_routing(): void
    {
        $kernel = $this->kernel();

        self::assertSame(200, $this->handle($kernel, '/customers')->status());
        self::assertSame(404, $this->handle($kernel, '/nope')->status());
        self::assertSame(404, $this->handle($kernel, '/assets/module/Example/js/nope.js')->status());
    }

    public function test_a_module_php_file_cannot_be_reached_through_an_asset_url(): void
    {
        $kernel = $this->kernel();

        foreach ([
            '/assets/module/Example/module.php',
            '/assets/module/Example/../module.php',
            '/assets/module/Example/../../../composer.json',
            '/assets/core/../composer.json',
        ] as $path) {
            $response = $this->handle($kernel, $path);

            self::assertSame(404, $response->status(), $path);
            self::assertStringNotContainsString('ModuleContext', $this->bodyOf($response), $path);
        }
    }

    /**
     * The final response filter still runs, so an asset is a response like any
     * other rather than a hole in the lifecycle.
     */
    public function test_an_asset_response_goes_through_the_final_response_filter(): void
    {
        $response = $this->handle($this->kernel(), '/assets/core/css/app.css');

        self::assertSame('app-framework', $response->header('X-Engine'));
    }

    public function test_a_module_can_make_an_asset_private_with_a_filter(): void
    {
        $application = $this->application();
        $application->boot();

        $filters = $application->container()->get(FilterEngine::class);
        self::assertInstanceOf(FilterEngine::class, $filters);

        $filters->add('asset.response', static fn(Response $response, string $path): Response
            => \str_starts_with($path, '/assets/module/ExampleGateway/') ? new Response('', 403) : $response);

        $kernel = $application->container()->get(HttpKernel::class);
        self::assertInstanceOf(HttpKernel::class, $kernel);

        self::assertSame(403, $this->handle($kernel, '/assets/module/ExampleGateway/js/gateway.js')->status());
        self::assertSame(200, $this->handle($kernel, '/assets/module/Example/js/example.js')->status());
    }

    // ---- deployment shapes ------------------------------------------------

    /**
     * Apache in a subdirectory: the URL the manager writes has to be the URL
     * the kernel later answers, or every asset on every page 404s.
     */
    public function test_a_subdirectory_install_generates_and_serves_the_same_url(): void
    {
        $application = $this->application(['http' => ['base_path' => '/framework']]);
        $application->boot();

        $manager = $application->container()->get(AssetManager::class);
        self::assertInstanceOf(AssetManager::class, $manager);

        $url = $manager->core('css/app.css');
        self::assertStringStartsWith('/framework/assets/core/css/app.css', $url);

        $kernel = $application->container()->get(HttpKernel::class);
        self::assertInstanceOf(HttpKernel::class, $kernel);

        $response = $kernel->handle(Request::create('GET', $url, [
            'server' => ['SCRIPT_NAME' => '/framework/index.php'],
        ]));

        self::assertSame(200, $response->status());
    }

    public function test_a_cached_client_gets_a_304(): void
    {
        $kernel = $this->kernel();

        $etag = $this->handle($kernel, '/assets/core/css/app.css')->header('ETag');
        self::assertNotNull($etag);

        $response = $this->handle($kernel, '/assets/core/css/app.css', ['If-None-Match' => $etag]);

        self::assertSame(304, $response->status());
        self::assertSame('', $this->bodyOf($response));
    }
}
