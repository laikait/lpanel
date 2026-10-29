<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Asset\AssetKind;
use App\Engine\Asset\AssetRegistry;
use App\Engine\Core\Application;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Route;
use App\Engine\Routing\RouteCollector;
use App\Engine\Routing\Router;
use App\Engine\Template\TemplateException;
use App\Engine\Template\TemplateManager;
use App\Engine\Template\ThemeProvider;
use App\Engine\Template\ThemeRegistry;
use App\Tests\Support\TestCase;

/**
 * Themes through the real application: configuration and a provider register
 * them, an asset request reaches them without loading a module, a route's meta
 * activates one, and an error before routing finds one by its path.
 *
 * The theme directories are created under the project's own templates/ with a
 * random area name, because that is where a theme lives, and removed after.
 */
final class ThemeSliceTest extends TestCase
{
    private string $area;

    protected function setUp(): void
    {
        $this->area = 'zztheme' . \bin2hex(\random_bytes(4));

        $this->write('dark/page.php', 'dark page');
        $this->write('dark/errors/404.php', 'dark 404');
        $this->write('dark/errors/error.php', 'dark <?= $error->status ?>');
        $this->write('dark/assets/css/app.css', 'aside{}');
        $this->write('light/assets/css/app.css', 'main{}');

        ThemeSliceProvider::$themes = [$this->area . '/light'];
        ThemeSliceProvider::$errorThemes = [];
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->basePath('templates/' . $this->area));

        parent::tearDown();
    }

    private function write(string $relative, string $contents): void
    {
        $file = $this->basePath('templates/' . $this->area . '/' . $relative);

        if (!\is_dir(\dirname($file))) {
            \mkdir(\dirname($file), 0o777, true);
        }

        \file_put_contents($file, $contents);
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }

        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            \is_dir($child) ? $this->removeTree($child) : \unlink($child);
        }

        \rmdir($path);
    }

    /**
     * Whatever config/templates.php registers, kept.
     *
     * Settings merge lists by index, so a list given here would overwrite the
     * project's themes one by one while its error_themes still name them --
     * and an error theme naming an unregistered theme refuses to load.
     *
     * @return list<string>
     */
    private function projectThemes(): array
    {
        $file = $this->basePath('config/templates.php');

        /** @var mixed $config */
        $config = \is_file($file) ? require $file : [];
        $themes = \is_array($config) && \is_array($config['themes'] ?? null) ? $config['themes'] : [];

        return \array_values(\array_filter($themes, 'is_string'));
    }

    /** @param array<string, mixed> $templates */
    private function app(array $templates = []): Application
    {
        if (isset($templates['themes']) && \is_array($templates['themes'])) {
            $templates['themes'] = [...$this->projectThemes(), ...$templates['themes']];
        }

        return $this->application([
            'app' => ['debug' => false],
            'templates' => $templates + [
                'themes' => [...$this->projectThemes(), $this->area . '/dark'],
                'theme_provider' => ThemeSliceProvider::class,
                'error_themes' => ['/' . $this->area => $this->area . '/dark'],
            ],
        ]);
    }

    private function html(string $method, string $uri): Request
    {
        return Request::create($method, $uri, ['headers' => ['Accept' => 'text/html']]);
    }

    /**
     * Each call is a new request, and the same URI can answer differently
     * after another one -- which is exactly what the reordered assertions
     * check. Impure, so static analysis does not assume a repeat is a copy.
     *
     * @phpstan-impure
     */
    private function bodyAt(Application $app, string $uri): string
    {
        return $app->handle($this->html('GET', $uri))->body();
    }

    private function bodyOf(Response $response): string
    {
        \ob_start();
        \ob_start();

        $response->send();

        \ob_end_flush();

        return (string) \ob_get_clean();
    }

    // ---- registration -----------------------------------------------------

    public function test_config_and_the_provider_both_register_themes(): void
    {
        $app = $this->app();
        $app->boot();

        $themes = $app->container()->get(ThemeRegistry::class);
        self::assertInstanceOf(ThemeRegistry::class, $themes);

        self::assertTrue($themes->has($this->area . '/dark'), 'from templates.themes');
        self::assertTrue($themes->has($this->area . '/light'), 'from the provider');
    }

    /** A directory under templates/ is not a theme until something says so. */
    public function test_an_unregistered_theme_directory_publishes_nothing(): void
    {
        ThemeSliceProvider::$themes = [];

        $app = $this->app(['themes' => [], 'error_themes' => []]);
        $response = $app->handle(Request::create('GET', '/assets/template/' . $this->area . '/dark/css/app.css'));

        self::assertSame(404, $response->status());
    }

    public function test_a_theme_named_after_a_module_is_refused(): void
    {
        $app = $this->app(['themes' => ['Example'], 'error_themes' => []]);

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('override directory');

        $app->boot();
    }

    // ---- assets -----------------------------------------------------------

    /**
     * The asset path loads no module.php, so themes have to be loaded on it
     * as well -- a provider's included.
     */
    public function test_an_asset_request_reaches_a_theme_without_booting(): void
    {
        $app = $this->app();

        $dark = $app->handle(Request::create('GET', '/assets/template/' . $this->area . '/dark/css/app.css'));
        $light = $app->handle(Request::create('GET', '/assets/template/' . $this->area . '/light/css/app.css'));

        self::assertSame(200, $dark->status());
        self::assertSame('aside{}', $this->bodyOf($dark));
        self::assertSame(200, $light->status());
        self::assertSame('main{}', $this->bodyOf($light));
        self::assertFalse($app->isBooted(), 'an asset request must not boot the modules');
    }

    public function test_the_url_names_the_theme(): void
    {
        $app = $this->app();
        $app->boot();

        self::assertMatchesRegularExpression(
            '#/assets/template/' . $this->area . '/dark/css/app\.css\?v=[0-9a-f]{8}$#',
            asset()->template($this->area . '/dark', 'css/app.css'),
        );
        self::assertTrue(
            $app->container()->get(AssetRegistry::class)?->has(AssetKind::Template, $this->area . '/dark') ?? false,
        );
    }

    // ---- views ------------------------------------------------------------

    public function test_route_meta_activates_the_theme(): void
    {
        $app = $this->app();
        $app->boot();

        $app->container()->get(Router::class)->add(
            (new Route('GET', '/themed-page', static fn(TemplateManager $templates): Response => new Response(
                $templates->render('page'),
            )))->meta(['theme' => $this->area . '/dark']),
        );

        $response = $app->handle($this->html('GET', '/themed-page'));

        self::assertSame(200, $response->status());
        self::assertSame('dark page', $response->body());
    }

    /**
     * Every way a module declares the theme key, through the same
     * RouteCollector a module.php is handed: one route, a group, a route
     * overriding its group, a nested group overriding its parent, and a
     * group with no theme at all.
     */
    public function test_a_single_route_and_a_group_can_both_declare_a_theme(): void
    {
        $this->write('light/page.php', 'light page');

        $app = $this->app();
        $app->boot();

        $render = static fn(TemplateManager $templates): Response => new Response($templates->render('page'));
        $dark = $this->area . '/dark';
        $light = $this->area . '/light';
        $router = $app->container()->get(Router::class);
        self::assertInstanceOf(Router::class, $router);
        $routes = new RouteCollector($router);

        // One route, on its own.
        $routes->get('/single', $render)->meta(['theme' => $dark]);

        // A group: every route inside inherits it.
        $routes->group('/grouped', static function (RouteCollector $group) use ($render, $dark): void {
            $group->get('/a', $render);
            $group->get('/b', $render);

            // A route's own meta is merged over the group's, so it can choose another.
            $group->get('/override', $render)->meta(['theme' => $dark]);

            // A nested group can override its parent's theme too -- written
            // like a path here, which is trimmed to the same name.
            $group->group('/nested', static function (RouteCollector $inner) use ($render): void {
                $inner->get('/', $render);
            }, '', ['theme' => '/' . $dark . '/']);
        }, '', ['theme' => $light]);

        // No theme anywhere: templates/ alone. "page" exists only in the
        // themes, so this answers whether any theme leaked in.
        $routes->group('/plain', static function (RouteCollector $group): void {
            $group->get('/', static fn(TemplateManager $templates): Response => new Response(
                $templates->exists('page') ? 'themed' : 'no theme',
            ));
        });

        self::assertSame('dark page', $this->bodyAt($app, '/single'));
        self::assertSame('light page', $this->bodyAt($app, '/grouped/a'));
        self::assertSame('light page', $this->bodyAt($app, '/grouped/b'));
        self::assertSame('dark page', $this->bodyAt($app, '/grouped/override'));
        self::assertSame('dark page', $this->bodyAt($app, '/grouped/nested'));
        self::assertSame('no theme', $this->bodyAt($app, '/plain'));

        // And in the other order, so no answer above leaned on a theme left
        // over from the request before it.
        self::assertSame('no theme', $this->bodyAt($app, '/plain'));
        self::assertSame('light page', $this->bodyAt($app, '/grouped/b'));
        self::assertSame('dark page', $this->bodyAt($app, '/single'));
        self::assertSame('no theme', $this->bodyAt($app, '/plain'));
    }

    /** Route meta written like a path -- '/area/dark/' -- is the same theme. */
    public function test_route_meta_may_be_written_like_a_path(): void
    {
        $app = $this->app();
        $app->boot();

        $app->container()->get(Router::class)->add(
            (new Route('GET', '/themed-slashes', static fn(TemplateManager $templates): Response => new Response(
                $templates->render('page'),
            )))->meta(['theme' => '/' . $this->area . '/dark/']),
        );

        self::assertSame('dark page', $app->handle($this->html('GET', '/themed-slashes'))->body());
    }

    /**
     * One process, two requests: the second must not inherit the first's
     * theme. A worker or a test serves many requests with one Application.
     */
    public function test_a_theme_does_not_carry_into_the_next_request(): void
    {
        $this->write('dark/home.twig', 'dark home');

        $app = $this->app();
        $app->boot();

        $app->container()->get(Router::class)->add(
            (new Route('GET', '/themed-first', static fn(TemplateManager $templates): Response => new Response(
                $templates->render('home'),
            )))->meta(['theme' => $this->area . '/dark']),
        );

        self::assertSame('dark home', $app->handle($this->html('GET', '/themed-first'))->body());

        $plain = $app->handle($this->html('GET', '/'));

        self::assertSame(200, $plain->status());
        self::assertStringNotContainsString('dark home', $plain->body());
        self::assertNull($app->container()->get(ThemeRegistry::class)?->active());
    }

    /**
     * The guard refuses at dispatch.before priority 5; the theme listener at 1
     * has already run, so the refusal is themed too.
     */
    public function test_a_guards_refusal_is_rendered_in_the_routes_theme(): void
    {
        $app = $this->app();
        $app->boot();

        $app->container()->get(Router::class)->add(
            (new Route('POST', '/themed-form', static fn(): Response => new Response('saved')))
                ->meta(['theme' => $this->area . '/dark']),
        );

        // A POST with no CSRF token; which refusal it is (403 or 419) is the
        // guard's business. That it is themed is this test's.
        $response = $app->handle($this->html('POST', '/themed-form'));

        self::assertContains($response->status(), [403, 419]);
        self::assertSame('dark ' . $response->status(), $response->body());
    }

    // ---- errors before routing ---------------------------------------------

    public function test_an_unknown_url_in_an_area_gets_the_areas_404(): void
    {
        $app = $this->app();

        $themed = $app->handle($this->html('GET', '/' . $this->area . '/nowhere'));

        self::assertSame(404, $themed->status());
        self::assertSame('dark 404', $themed->body());
    }

    public function test_an_unknown_url_elsewhere_keeps_the_sites_404(): void
    {
        $app = $this->app();

        $plain = $app->handle($this->html('GET', '/nowhere-' . $this->area));

        self::assertSame(404, $plain->status());
        self::assertStringNotContainsString('dark 404', $plain->body());
    }

    public function test_the_provider_can_map_error_themes(): void
    {
        ThemeSliceProvider::$errorThemes = ['/' . $this->area . '/reports' => $this->area . '/light'];
        $this->write('light/errors/404.php', 'light 404');

        $app = $this->app();

        self::assertSame('light 404', $app->handle($this->html('GET', '/' . $this->area . '/reports/x'))->body());
        self::assertSame('dark 404', $app->handle($this->html('GET', '/' . $this->area . '/x'))->body());
    }
}

/** A provider the way an application would write one, minus the database. */
final class ThemeSliceProvider implements ThemeProvider
{
    /** @var list<string> */
    public static array $themes = [];

    /** @var array<string, string> */
    public static array $errorThemes = [];

    public function themes(): iterable
    {
        return self::$themes;
    }

    public function errorThemes(): iterable
    {
        return self::$errorThemes;
    }
}
