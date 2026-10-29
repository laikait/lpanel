<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Module\ModuleRegistry;
use App\Engine\Template\TemplateManager;
use App\Tests\Support\TestCase;

/**
 * What a fresh installation answers, with nothing added to it.
 *
 * The framework ships one module -- shared -- and a default template. These
 * tests boot exactly that: no showcase, no fixtures, modules/Plugins/ and
 * modules/Gateways/ absent. What they prove is that such an application has a
 * front page and a 404 page, both rendered by Twig through the default
 * template's layout, and that neither is something an application has to
 * fight to replace.
 */
final class DefaultPagesSliceTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function shipped(array $config = []): Application
    {
        // Production, unless a test says otherwise: in debug mode an error is
        // the built-in diagnostic page, which is not what this is about.
        $config['app']['debug'] ??= false;

        return $this->shippedApplication($config)->boot();
    }

    private function get(Application $app, string $path, string $base = ''): Response
    {
        return $app->handle(Request::create('GET', $base . $path, [
            'server' => ['SCRIPT_NAME' => $base . '/index.php'],
            'headers' => ['Accept' => 'text/html'],
        ]));
    }

    // ---- what ships -----------------------------------------------------------

    public function test_the_framework_ships_the_shared_module_and_nothing_else(): void
    {
        $registry = $this->shipped()->container()->get(ModuleRegistry::class);

        self::assertSame(['Shared'], $registry->ids());
        self::assertDirectoryDoesNotExist($this->basePath('modules/Plugins/Example'));
        self::assertDirectoryDoesNotExist($this->basePath('modules/Gateways/ExampleGateway'));
    }

    /** Twig is the default engine, so both pages resolve to .twig files. */
    public function test_both_default_pages_are_twig_templates(): void
    {
        $templates = $this->shipped()->container()->get(TemplateManager::class);

        foreach (['home', 'layout', 'errors/404', 'errors/error'] as $name) {
            self::assertSame('twig', $templates->locate($name)->extension, $name);
        }
    }

    // ---- the home page --------------------------------------------------------

    public function test_the_front_page_is_the_default_home_page(): void
    {
        $response = $this->get($this->shipped(), '/');

        self::assertSame(200, $response->status());
        self::assertSame('text/html; charset=UTF-8', $response->contentType());

        $body = $response->body();

        self::assertStringContainsString('<title>Welcome</title>', $body);
        self::assertStringContainsString('Your application is running', $body);
        self::assertStringContainsString('<header class="site-header">', $body, 'rendered through the layout');
        self::assertStringContainsString('href="/assets/core/css/app.css?v=', $body);
        self::assertStringContainsString('href="/assets/template/css/theme.css?v=', $body);
    }

    /**
     * Under Apache in a subdirectory, every URL on the page carries the prefix
     * -- the stylesheets from the asset layer, the home link from the request.
     */
    public function test_the_front_page_links_carry_the_subdirectory(): void
    {
        $app = $this->shipped(['http' => ['base_path' => '/framework']]);
        $body = $this->get($app, '/', '/framework')->body();

        self::assertStringContainsString('<a class="brand" href="/framework/">', $body);
        self::assertStringContainsString('href="/framework/assets/core/css/app.css?v=', $body);
    }

    /** A public default page says nothing about the machine it runs on. */
    public function test_the_front_page_discloses_no_path_or_version(): void
    {
        $body = $this->get($this->shipped(), '/')->body();

        self::assertStringNotContainsString(\str_replace('\\', '/', $this->basePath()), \str_replace('\\', '/', $body));
        self::assertStringNotContainsString(Application::VERSION, $body);
    }

    /**
     * Replacing the page takes one route in one module, and nothing in the
     * shared module: plugins register after it, and the last route declared
     * for a method and path is the one that answers.
     */
    public function test_a_module_that_declares_the_front_page_replaces_the_default(): void
    {
        $app = $this->shipped(['modules' => ['paths' => [
            'modules/Shared',
            'tests/Fixtures/Modules/FrontPage/Plugins',
        ]]]);

        $response = $this->get($app, '/');

        self::assertSame(200, $response->status());
        self::assertSame('the landing page', $response->body());
    }

    // ---- the 404 page ---------------------------------------------------------

    public function test_an_unknown_path_gets_the_twig_404_page(): void
    {
        $response = $this->get($this->shipped(), '/no/such/page');

        self::assertSame(404, $response->status());
        self::assertSame('text/html; charset=UTF-8', $response->contentType());

        $body = $response->body();

        self::assertStringContainsString('<title>Page not found</title>', $body);
        self::assertStringContainsString('That page is not here', $body);
        self::assertStringContainsString('<header class="site-header">', $body, 'rendered through the layout');
    }

    /** The way back home is the one link a 404 page must get right. */
    public function test_the_404_page_leads_home_through_the_subdirectory(): void
    {
        $app = $this->shipped(['http' => ['base_path' => '/framework']]);
        $body = $this->get($app, '/missing', '/framework')->body();

        self::assertStringContainsString('<a class="button" href="/framework/">Back to the home page</a>', $body);
    }

    /** The page never repeats the address back, so the address cannot inject markup. */
    public function test_the_404_page_does_not_reflect_the_requested_path(): void
    {
        $body = $this->get($this->shipped(), '/%3Cscript%3Ealert(1)%3C/script%3E')->body();

        self::assertStringNotContainsString('<script>alert', $body);
        self::assertStringNotContainsString('alert(1)', $body);
    }

    /**
     * A program asking for JSON gets the error document, not the page -- the
     * default template is for people.
     */
    public function test_a_json_client_gets_the_error_document_instead(): void
    {
        $response = $this->shipped()->handle(Request::create('GET', '/missing', [
            'headers' => ['Accept' => 'application/json'],
        ]));

        self::assertSame(404, $response->status());
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertStringNotContainsString('<html', $response->body());
    }

    /** In debug mode the diagnostic page wins, so a trace is never hidden behind a pretty 404. */
    public function test_debug_mode_shows_the_built_in_page_not_the_template(): void
    {
        $body = $this->get($this->shipped(['app' => ['debug' => true]]), '/missing')->body();

        // Whoops, which is a dev dependency: the diagnostic page, not the
        // layout the visitor's 404 template extends.
        self::assertStringContainsString('<title>App\\Engine\\Http\\HttpException', $body);
        self::assertStringNotContainsString('<header class="site-header"', $body);
    }
}
