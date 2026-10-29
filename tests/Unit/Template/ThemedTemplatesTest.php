<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use App\Engine\Asset\AssetManager;
use App\Engine\Asset\AssetRegistry;
use App\Engine\Asset\AssetResolver;
use App\Engine\Asset\AssetVersioning;
use App\Engine\Cache\Cache;
use App\Engine\Cache\Stores\ArrayStore;
use App\Engine\Error\ErrorDocument;
use App\Engine\Error\ErrorPage;
use App\Engine\Http\Request;
use App\Engine\Template\PhpTemplateEngine;
use App\Engine\Template\TemplateException;
use App\Engine\Template\TemplateManager;
use App\Engine\Template\TemplateRegistry;
use App\Engine\Template\TemplateSource;
use App\Engine\Template\ThemeRegistry;
use App\Engine\Template\TwigTemplateEngine;
use App\Tests\Support\TestCase;

/**
 * Views and error pages with a theme active.
 *
 * A theme's directory is searched ahead of templates/ and templates/ is its
 * fallback, for a page, for the layout it extends and for an error page.
 */
final class ThemedTemplatesTest extends TestCase
{
    private string $root;

    private TemplateRegistry $views;

    private ThemeRegistry $themes;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/framework-themes-' . \bin2hex(\random_bytes(6));

        $this->write('page.php', 'base page');
        $this->write('only-in-base.php', 'base only');
        $this->write('layout.twig', 'BASE[{% block content %}{{ content|default("")|raw }}{% endblock %}]');
        $this->write('home.twig', '{% extends "layout.twig" %}{% block content %}home{% endblock %}');
        $this->write('errors/404.php', 'base 404');
        $this->write('errors/error.twig', '{% extends "layout.twig" %}{% block content %}{{ error.status }}{% endblock %}');
        $this->write('Example/row.php', 'base override');

        $this->write('admin/dark/page.php', 'dark page');
        $this->write('admin/dark/layout.twig', 'DARK[{% block content %}{{ content|default("")|raw }}{% endblock %}]');
        $this->write('admin/dark/errors/404.php', 'dark 404');
        $this->write('admin/dark/Example/row.php', 'dark override');
        $this->write('admin/dark/assets/evil.php', '<?php echo "ran";');

        $this->write('module/row.php', 'module row');

        $this->views = new TemplateRegistry();
        $this->views->add(null, $this->root . '/templates', TemplateSource::OVERRIDE);
        $this->views->add('Example', $this->root . '/templates/module');

        $this->themes = new ThemeRegistry(new AssetRegistry(), $this->root . '/templates');
        $this->themes->register('admin/dark');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    private function write(string $relative, string $contents): void
    {
        $file = $this->root . '/templates/' . $relative;

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

    private function manager(?Cache $cache = null): TemplateManager
    {
        $templates = new TemplateManager(
            $this->views,
            new AssetManager(new AssetRegistry(), new AssetResolver(), '', AssetVersioning::None),
            cache: $cache ?? new Cache(new ArrayStore()),
            themes: $this->themes,
        );

        $templates->addEngine(new TwigTemplateEngine($this->views, themes: $this->themes));
        $templates->addEngine(new PhpTemplateEngine());

        return $templates;
    }

    // ---- views ------------------------------------------------------------

    public function test_without_a_theme_nothing_changes(): void
    {
        self::assertSame('base page', $this->manager()->render('page'));
    }

    public function test_the_active_theme_wins_and_templates_is_the_fallback(): void
    {
        $this->themes->use('admin/dark');
        $templates = $this->manager();

        self::assertSame('dark page', $templates->render('page'));
        self::assertSame('base only', $templates->render('only-in-base'));
    }

    public function test_a_theme_overrides_a_module_template_too(): void
    {
        $templates = $this->manager();

        self::assertSame('base override', $templates->render('@Example/row'));

        $this->themes->use('admin/dark');

        self::assertSame('dark override', $templates->render('@Example/row'));
    }

    /** {% extends "layout.twig" %} has to find the theme's layout, not templates/'. */
    public function test_a_base_page_extends_the_themes_layout(): void
    {
        $templates = $this->manager();

        self::assertSame('BASE[home]', $templates->render('home'));

        $this->themes->use('admin/dark');

        self::assertSame('DARK[home]', $templates->render('home'));
    }

    /**
     * One manager, one cache, two themes: the name resolved under one must
     * never be handed back under the other.
     */
    public function test_a_resolution_is_remembered_per_theme(): void
    {
        $cache = new Cache(new ArrayStore());

        $this->themes->use('admin/dark');
        self::assertSame('dark page', $this->manager($cache)->render('page'));

        $this->themes->use(null);
        self::assertSame('base page', $this->manager($cache)->render('page'));

        // And within one process, through the in-memory memo.
        $templates = $this->manager($cache);
        self::assertSame('base page', $templates->render('page'));
        $this->themes->use('admin/dark');
        self::assertSame('dark page', $templates->render('page'));
    }

    // ---- the PHP engine ---------------------------------------------------

    /**
     * Folder-major, as without themes: the theme's .php beats templates/'
     * .twig, because every extension is tried in the theme before templates/.
     */
    public function test_a_themes_php_view_beats_a_base_twig_view(): void
    {
        $this->write('greeting.twig', 'base twig');
        $this->write('admin/dark/greeting.php', 'dark php');
        $this->themes->use('admin/dark');

        self::assertSame('dark php', $this->manager()->render('greeting'));
    }

    /** Within one folder Twig is still registered first, so it still wins. */
    public function test_in_one_theme_folder_twig_beats_php(): void
    {
        $this->write('admin/dark/both.twig', 'twig');
        $this->write('admin/dark/both.php', 'php');
        $this->themes->use('admin/dark');

        self::assertSame('twig', $this->manager()->render('both'));
    }

    /** $view->render() goes through the manager, so a partial follows the theme -- and falls back. */
    public function test_a_php_partial_follows_the_theme_and_falls_back(): void
    {
        $this->write('partials/a.php', 'base a');
        $this->write('partials/b.php', 'base b');
        $this->write('admin/dark/partials/a.php', 'dark a');
        $this->write('admin/dark/list.php', '<?= $view->render("partials/a") ?>+<?= $view->render("partials/b") ?>');
        $this->themes->use('admin/dark');

        self::assertSame('dark a+base b', $this->manager()->render('list'));
    }

    /** A PHP page hands its markup to the theme's Twig layout as `content`. */
    public function test_a_php_page_renders_inside_the_themes_twig_layout(): void
    {
        $this->write('admin/dark/report.php', '<?= $view->render("layout", ["content" => "<b>" . $e("1 < 2") . "</b>"]) ?>');
        $this->themes->use('admin/dark');

        self::assertSame('DARK[<b>1 &lt; 2</b>]', $this->manager()->render('report'));
    }

    /** Twig's own include, from a base page, finds the theme's partial. */
    public function test_a_twig_include_follows_the_theme(): void
    {
        $this->write('box.twig', '{% include "inner.twig" %}');
        $this->write('inner.twig', 'base inner');
        $this->write('admin/dark/inner.twig', 'dark inner');
        $templates = $this->manager();

        self::assertSame('base inner', $templates->render('box'));

        $this->themes->use('admin/dark');

        self::assertSame('dark inner', $templates->render('box'));
    }

    public function test_a_themes_assets_folder_is_not_a_view(): void
    {
        $templates = $this->manager();

        foreach ([null, 'admin/dark'] as $theme) {
            $this->themes->use($theme);

            foreach (['admin/dark/assets/evil', 'Admin/Dark/Assets/evil', 'assets/evil'] as $name) {
                try {
                    $templates->render($name);
                    self::fail(\sprintf('"%s" rendered with theme %s', $name, $theme ?? 'none'));
                } catch (TemplateException $e) {
                    self::assertStringContainsString('static files, not views', $e->getMessage());
                }
            }
        }
    }

    // ---- error pages --------------------------------------------------------

    private function errorPage(): ErrorPage
    {
        return new ErrorPage($this->manager(), themes: $this->themes);
    }

    public function test_an_active_theme_supplies_its_own_error_page(): void
    {
        $this->themes->use('admin/dark');

        self::assertSame('dark 404', $this->errorPage()->render(ErrorDocument::of(404)));
    }

    /** No errors/403 anywhere: errors/error from templates/, in the theme's layout. */
    public function test_a_base_error_page_renders_in_the_themes_layout(): void
    {
        $this->themes->use('admin/dark');

        self::assertSame('DARK[403]', $this->errorPage()->render(ErrorDocument::of(403)));
    }

    /** An unknown URL fails before any route could choose a theme. */
    public function test_an_unrouted_error_takes_its_theme_from_the_path(): void
    {
        $this->themes->mapErrors('/admin', 'admin/dark');
        $page = $this->errorPage();

        self::assertSame('dark 404', $page->render(ErrorDocument::of(404), Request::create('GET', '/admin/missing')));
        self::assertSame('base 404', $page->render(ErrorDocument::of(404), Request::create('GET', '/administrator')));
        self::assertSame('base 404', $page->render(ErrorDocument::of(404), Request::create('GET', '/customers')));
    }

    public function test_the_borrowed_theme_is_given_back(): void
    {
        $this->themes->mapErrors('/admin', 'admin/dark');

        $this->errorPage()->render(ErrorDocument::of(404), Request::create('GET', '/admin/missing'));

        self::assertNull($this->themes->active());
    }

    /** A route that chose a theme keeps it, whatever the path map says. */
    public function test_the_routes_theme_beats_the_path_map(): void
    {
        $this->themes->register('dark');
        $this->write('dark/errors/404.php', 'plain dark 404');
        $this->themes->mapErrors('/admin', 'admin/dark');
        $this->themes->use('dark');

        self::assertSame(
            'plain dark 404',
            $this->errorPage()->render(ErrorDocument::of(404), Request::create('GET', '/admin/missing')),
        );
        self::assertSame('dark', $this->themes->active());
    }
}
