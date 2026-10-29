<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use App\Engine\Asset\AssetManager;
use App\Engine\Asset\AssetRegistry;
use App\Engine\Asset\AssetResolver;
use App\Engine\Asset\AssetVersioning;
use App\Engine\Template\PhpTemplateEngine;
use App\Engine\Template\TemplateException;
use App\Engine\Template\TemplateManager;
use App\Engine\Template\TemplateRegistry;
use App\Engine\Template\TemplateSource;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Resolution, precedence and the rules that keep a name from becoming a path.
 */
final class TemplateManagerTest extends TestCase
{
    private string $root;

    private TemplateRegistry $views;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/framework-views-' . \bin2hex(\random_bytes(6));

        foreach (['theme', 'theme/Example', 'module', 'module/deep', 'shared'] as $directory) {
            \mkdir($this->root . '/' . $directory, 0o777, true);
        }

        $this->write('theme/page.php', 'theme page');
        $this->write('theme/nested.php', '<?= $view->render("@Example/only-in-module") ?>');
        $this->write('theme/Example/overridden.php', 'the theme wins');
        $this->write('module/overridden.php', 'the module loses');
        $this->write('module/only-in-module.php', 'module only');
        $this->write('module/deep/thing.php', 'deep');
        $this->write('shared/money.php', 'money');
        $this->write('theme/secret.env', 'DB_PASSWORD=hunter2');

        $this->views = new TemplateRegistry();
        $this->views->add(null, $this->root . '/theme', TemplateSource::OVERRIDE);
        $this->views->add('Example', $this->root . '/module');
        $this->views->add('shared', $this->root . '/shared');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    private function write(string $relative, string $contents): void
    {
        \file_put_contents($this->root . '/' . $relative, $contents);
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

            if (\is_link($child) || \is_file($child)) {
                @\unlink($child);

                continue;
            }

            $this->removeTree($child);
        }

        @\rmdir($path);
    }

    private function manager(): TemplateManager
    {
        $assets = new AssetManager(
            new AssetRegistry(),
            new AssetResolver(),
            '',
            AssetVersioning::None,
        );

        $templates = new TemplateManager($this->views, $assets);
        $templates->addEngine(new PhpTemplateEngine());

        return $templates;
    }

    // ---- resolution -------------------------------------------------------

    public function test_a_bare_name_resolves_against_the_application_search_path(): void
    {
        self::assertSame('theme page', $this->manager()->render('page'));
    }

    /**
     * "No extension should be required." That is not a convenience: it is what
     * lets a template move from PHP to Twig without a single caller changing.
     */
    public function test_no_extension_is_written_at_the_call_site(): void
    {
        $manager = $this->manager();

        self::assertSame('page.php', $manager->locate('page')->relativePath);
        self::assertSame('php', $manager->locate('page')->extension);
    }

    /** A folder under templates/ is part of the name, whatever it is called. */
    public function test_a_name_with_folders_is_a_path_under_the_templates_directory(): void
    {
        \mkdir($this->root . '/theme/admin/panel', 0o777, true);
        $this->write('theme/admin/panel/user.php', 'the user panel');

        self::assertSame('the user panel', $this->manager()->render('admin/panel/user'));
    }

    /**
     * templates/assets/ is the site's static files. A .php among them must
     * not be reachable as a view, and the refusal says where assets go.
     */
    public function test_the_assets_folder_is_not_a_view(): void
    {
        \mkdir($this->root . '/theme/assets', 0o777, true);
        $this->write('theme/assets/tool.php', 'ran as a view');
        $manager = $this->manager();

        self::assertFalse($manager->exists('assets/tool'));

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('templates/assets/ holds static files, not views');
        $manager->render('assets/tool');
    }

    public function test_a_module_may_still_have_a_folder_called_assets(): void
    {
        \mkdir($this->root . '/module/assets', 0o777, true);
        $this->write('module/assets/row.php', 'a module view');

        self::assertSame('a module view', $this->manager()->render('@Example/assets/row'));
    }

    public function test_a_namespaced_name_reaches_a_module(): void
    {
        self::assertSame('module only', $this->manager()->render('@Example/only-in-module'));
    }

    public function test_a_namespaced_name_can_be_nested(): void
    {
        self::assertSame('deep', $this->manager()->render('@Example/deep/thing'));
    }

    // ---- the override rule ------------------------------------------------

    /**
     * The point of the phase's resolution rules: a site replaces a module's
     * markup by putting a file in its own theme, and the module is neither
     * edited nor consulted.
     */
    public function test_the_active_template_overrides_a_module_template(): void
    {
        self::assertSame('the theme wins', $this->manager()->render('@Example/overridden'));
    }

    public function test_the_module_copy_is_the_fallback(): void
    {
        \unlink($this->root . '/theme/Example/overridden.php');

        self::assertSame('the module loses', $this->manager()->render('@Example/overridden'));
    }

    /**
     * Only the override tier takes part in the override rule. If a module could
     * override another module by guessing a directory name, "which template
     * wins" would depend on discovery order.
     */
    public function test_a_module_cannot_override_another_module(): void
    {
        \mkdir($this->root . '/shared/Example', 0o777, true);
        $this->write('shared/Example/only-in-module.php', 'shared tried to win');

        self::assertSame('module only', $this->manager()->render('@Example/only-in-module'));
    }

    public function test_the_search_path_is_reported_highest_precedence_first(): void
    {
        $path = $this->views->searchPath('Example');

        self::assertCount(2, $path);
        self::assertStringEndsWith('theme/Example', $path[0]);
        self::assertStringEndsWith('module', $path[1]);
    }

    // ---- engines ----------------------------------------------------------

    /**
     * Directory-major, not extension-major. A theme's .php has to beat a
     * module's .twig, and looping the other way round would let the module win
     * by virtue of its file extension.
     */
    public function test_a_higher_precedence_directory_wins_regardless_of_extension(): void
    {
        $manager = $this->manager();
        $manager->addEngine(new class implements \App\Engine\Template\TemplateEngine {
            public function extensions(): array
            {
                return ['tpl'];
            }

            public function render(
                \App\Engine\Template\TemplateFile $file,
                array $data,
                \App\Engine\Template\TemplateView $view,
            ): string {
                return 'rendered by the tpl engine';
            }
        });

        $this->write('module/mixed.tpl', 'module tpl');
        $this->write('theme/Example/mixed.php', 'theme php');

        self::assertSame('theme php', $manager->render('@Example/mixed'));
    }

    public function test_two_engines_cannot_claim_one_extension(): void
    {
        $manager = $this->manager();

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('already handled');

        $manager->addEngine(new class implements \App\Engine\Template\TemplateEngine {
            public function extensions(): array
            {
                return ['php'];
            }

            public function render(
                \App\Engine\Template\TemplateFile $file,
                array $data,
                \App\Engine\Template\TemplateView $view,
            ): string {
                return '';
            }
        });
    }

    public function test_a_longer_extension_is_matched_first(): void
    {
        $manager = $this->manager();
        $manager->addEngine(new class implements \App\Engine\Template\TemplateEngine {
            public function extensions(): array
            {
                return ['html.twig', 'twig'];
            }

            public function render(
                \App\Engine\Template\TemplateFile $file,
                array $data,
                \App\Engine\Template\TemplateView $view,
            ): string {
                return $file->extension;
            }
        });

        $this->write('theme/both.html.twig', '');

        self::assertSame('html.twig', $manager->render('both'));
    }

    // ---- failure ----------------------------------------------------------

    /**
     * "Not found" on its own is the least useful thing a template system can
     * say. The answer is nearly always that the file is one directory over.
     */
    public function test_a_missing_template_reports_where_it_looked(): void
    {
        try {
            $this->manager()->render('nope');
            self::fail('a missing template did not throw');
        } catch (TemplateException $e) {
            self::assertStringContainsString('No template named "nope"', $e->getMessage());
            self::assertStringContainsString('.php', $e->getMessage());
            self::assertStringContainsString('theme', $e->getMessage());
        }
    }

    public function test_an_unregistered_namespace_says_so(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('"Nothing" namespace');

        $this->manager()->render('@Nothing/page');
    }

    public function test_exists_answers_without_throwing(): void
    {
        $manager = $this->manager();

        self::assertTrue($manager->exists('page'));
        self::assertFalse($manager->exists('nope'));
        self::assertFalse($manager->exists('@Nothing/page'));
        self::assertFalse($manager->exists('../secret'));
    }

    /**
     * A template that renders itself is a stack overflow, and a stack overflow
     * in PHP is a process that dies with no message at all.
     */
    public function test_a_self_including_template_is_a_message_rather_than_a_crash(): void
    {
        $this->write('theme/loop.php', '<?= $view->render("loop") ?>');

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('includes itself');

        $this->manager()->render('loop');
    }

    // ---- names ------------------------------------------------------------

    /** @return list<array{string}> */
    public static function unusableNames(): array
    {
        return [
            ['../secret'],
            ['theme/../../secret'],
            ['./page'],
            ['/etc/passwd'],
            ['C:/Windows/win.ini'],
            ['page//name'],
            [''],
            ['secret.env'],
            ["page\0.php"],
            ['theme\\page'],
            ['@Example'],
            ['@/page'],
        ];
    }

    #[DataProvider('unusableNames')]
    public function test_a_name_cannot_become_an_arbitrary_path(string $name): void
    {
        $this->expectException(TemplateException::class);

        $this->manager()->render($name);
    }

    /**
     * A dotfile sitting in a template directory is not a template. The segment
     * rule excludes leading dots, so .env stays out without being named.
     */
    public function test_a_dotfile_in_a_template_directory_is_unreachable(): void
    {
        self::assertFileExists($this->root . '/theme/secret.env');

        $this->expectException(TemplateException::class);

        $this->manager()->render('.env');
    }

    public function test_a_symlink_out_of_a_template_directory_is_refused(): void
    {
        $outside = $this->root . '/outside.php';
        \file_put_contents($outside, 'outside');

        if (!@\symlink($outside, $this->root . '/theme/link.php')) {
            self::markTestSkipped('this account cannot create symlinks');
        }

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('outside its template directory');

        $this->manager()->render('link');
    }

    // ---- registration -----------------------------------------------------

    public function test_registering_the_same_directory_twice_is_harmless(): void
    {
        $before = $this->views->count();
        $this->views->add('shared', $this->root . '/shared');

        self::assertSame($before, $this->views->count());
    }

    public function test_two_directories_cannot_share_a_namespace_at_one_precedence(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('already registered');

        $this->views->add('shared', $this->root . '/module');
    }

    public function test_the_namespace_list_is_sorted_and_complete(): void
    {
        self::assertSame(['Example', 'shared'], $this->views->namespaces());
    }
}
