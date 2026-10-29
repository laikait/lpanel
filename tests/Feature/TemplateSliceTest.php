<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Asset\AssetKind;
use App\Engine\Asset\AssetRegistry;
use App\Engine\Core\HttpKernel;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Template\TemplateManager;
use App\Engine\Template\TemplateRegistry;
use App\Engine\Template\TemplateSource;
use App\Tests\Support\TestCase;

/**
 * The template layer through the real application.
 *
 * Real Bootstrap, real discovery, real modules, real files on disk. What this
 * proves is the thing a template system is actually for: a page rendered from
 * a module's data, through a theme that can replace any part of it, with every
 * URL in the markup coming from the asset layer.
 */
final class TemplateSliceTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function booted(array $config = []): \App\Engine\Core\Application
    {
        return $this->application($config)->boot();
    }

    /** @param array<string, mixed> $config */
    private function templates(array $config = []): TemplateManager
    {
        $templates = $this->booted($config)->container()->get(TemplateManager::class);
        self::assertInstanceOf(TemplateManager::class, $templates);

        return $templates;
    }

    private function html(string $uri = '/customers'): Response
    {
        $kernel = $this->booted()->container()->get(HttpKernel::class);
        self::assertInstanceOf(HttpKernel::class, $kernel);

        return $kernel->handle(Request::create('GET', $uri));
    }

    // ---- registration -----------------------------------------------------

    /**
     * A module is registered because it has a Templates/ directory, not because
     * it asked to be. There is nothing about templates in any module.php.
     */
    public function test_modules_with_a_templates_directory_are_registered_automatically(): void
    {
        $application = $this->application();
        $views = $application->container()->get(TemplateRegistry::class);
        self::assertInstanceOf(TemplateRegistry::class, $views);

        self::assertFalse($views->hasNamespace('Example'), 'nothing is registered before boot');

        $application->boot();

        self::assertSame(['Example', 'Shared'], $views->namespaces());
    }

    /**
     * Shared is a module like any other here: its Templates/ is "@Shared", and
     * the showcase's has no assets/, so it publishes none.
     */
    public function test_the_shared_module_has_templates_and_assets_only_if_it_has_the_directory(): void
    {
        $application = $this->booted();

        $views = $application->container()->get(TemplateRegistry::class);
        $assets = $application->container()->get(AssetRegistry::class);
        self::assertInstanceOf(TemplateRegistry::class, $views);
        self::assertInstanceOf(AssetRegistry::class, $assets);

        self::assertTrue($views->hasNamespace('Shared'));

        foreach ($assets->all() as $source) {
            self::assertNotSame('Shared', $source->name);
        }
    }

    public function test_the_templates_directory_is_registered_above_every_module(): void
    {
        $views = $this->booted()->container()->get(TemplateRegistry::class);
        self::assertInstanceOf(TemplateRegistry::class, $views);

        $path = $views->searchPath('Example');

        self::assertStringEndsWith('templates/Example', $path[0]);
        self::assertStringEndsWith(self::SHOWCASE . '/Plugins/Example/Templates', $path[1]);
    }

    // ---- resolution -------------------------------------------------------

    public function test_a_module_template_is_rendered_from_inside_the_denied_module_tree(): void
    {
        $html = $this->templates()->render('@Example/customer/promo', ['heading' => 'Hi']);

        self::assertStringContainsString('ships with the Example plugin', $html);
        self::assertStringContainsString('<h2>Hi</h2>', $html);
    }

    /**
     * The override rule, against real files: a theme ships a replacement for
     * the plugin's customer profile, and the plugin was never edited, asked or
     * told.
     *
     * The shipped default template does not override a plugin it does not ship
     * with, so the showcase's theme directory is added as a second theme. It
     * cannot share the active template's precedence -- two directories at one
     * level are refused -- so it sits one step below it and still well above
     * every module, which is all the override rule asks of a theme.
     */
    public function test_the_theme_overrides_the_plugins_profile_template(): void
    {
        $application = $this->application();
        $views = $application->container()->get(TemplateRegistry::class);
        self::assertInstanceOf(TemplateRegistry::class, $views);
        $views->add(null, $this->basePath(self::SHOWCASE . '/Theme'), TemplateSource::OVERRIDE + 1);

        $templates = $application->boot()->container()->get(TemplateManager::class);
        self::assertInstanceOf(TemplateManager::class, $templates);

        $customer = new \App\Tests\Fixtures\Showcase\Plugins\Example\Model\Customer(
            id: 1,
            name: 'Ada Lovelace',
            email: 'ada@example.test',
        );

        $html = $templates->render('@Example/customer/profile', ['customer' => $customer]);

        self::assertStringContainsString('profile--overridden', $html);
        self::assertStringNotContainsString('profile--module', $html);

        // ...and the module's own copy is still there, as the fallback.
        self::assertFileExists($this->basePath(self::SHOWCASE . '/Plugins/Example/Templates/customer/profile.php'));
        self::assertStringEndsWith(
            self::SHOWCASE . '/Theme/Example/customer/profile.php',
            $templates->locate('@Example/customer/profile')->absolutePath,
        );
    }

    public function test_a_shared_partial_is_reachable_by_namespace(): void
    {
        $html = $this->templates()->render('@Shared/money', ['amount' => 125000, 'currency' => 'USD']);

        self::assertStringContainsString('1,250.00 USD', $html);
    }

    public function test_the_global_helper_is_the_same_manager(): void
    {
        $templates = $this->templates();

        self::assertSame($templates, template());
    }

    // ---- the page ---------------------------------------------------------

    public function test_the_customer_list_is_served_as_html(): void
    {
        $response = $this->html();

        self::assertSame(200, $response->status());
        self::assertSame('text/html; charset=UTF-8', $response->header('Content-Type'));

        $body = $response->body();

        self::assertStringContainsString('<title>Customers</title>', $body);
        self::assertStringContainsString('Ada Lovelace', $body);
        self::assertStringContainsString('3 in total', $body);
    }

    /**
     * Every URL in the markup comes from the asset layer, so none of them says
     * where anything lives. That is §46 stated as an assertion.
     */
    public function test_the_page_exposes_no_physical_directory(): void
    {
        $body = $this->html()->body();

        foreach (['/modules/', '/engine/', '/templates/', 'xampp'] as $leak) {
            self::assertStringNotContainsString($leak, $body, 'the markup leaks ' . $leak);
        }

        self::assertStringContainsString('/assets/core/css/app.css?v=', $body);
        self::assertStringContainsString('/assets/template/css/theme.css?v=', $body);
    }

    /** The layout, the page and a module partial, composed without inheritance. */
    public function test_the_page_composes_a_layout_and_a_module_partial(): void
    {
        $body = $this->html()->body();

        self::assertStringContainsString('<main>', $body);
        self::assertStringContainsString('promo--module', $body);
        self::assertStringContainsString('</html>', $body);
    }

    public function test_the_same_data_is_still_available_as_json(): void
    {
        $response = $this->html('/customers.json');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('application/json', (string) $response->header('Content-Type'));
    }

    /** Nothing a handler passes reaches the page unescaped. */
    public function test_markup_in_the_data_is_escaped(): void
    {
        $html = $this->templates()->render('@Example/customer/promo', [
            'heading' => '<script>alert(1)</script>',
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    // ---- configuration ----------------------------------------------------

    /** templates/ is the site's views, and its assets/ the site's static files. */
    public function test_templates_holds_the_views_and_its_assets_folder_the_static_files(): void
    {
        $application = $this->booted();

        $views = $application->container()->get(TemplateRegistry::class);
        $assets = $application->container()->get(AssetRegistry::class);
        self::assertInstanceOf(TemplateRegistry::class, $views);
        self::assertInstanceOf(AssetRegistry::class, $assets);

        self::assertStringEndsWith('/templates', $views->searchPath(null)[0]);
        self::assertStringEndsWith('templates/assets', $assets->source(AssetKind::Template)->root);
    }
}
