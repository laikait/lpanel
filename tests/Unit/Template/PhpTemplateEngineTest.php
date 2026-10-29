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

/**
 * What is in scope inside a PHP template, and what happens when one misbehaves.
 */
final class PhpTemplateEngineTest extends TestCase
{
    private string $root;

    private TemplateManager $templates;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/framework-php-views-' . \bin2hex(\random_bytes(6));
        \mkdir($this->root, 0o777, true);

        $views = new TemplateRegistry();
        $views->add(null, $this->root, TemplateSource::OVERRIDE);

        $this->templates = new TemplateManager($views, new AssetManager(
            new AssetRegistry(),
            new AssetResolver(),
            '',
            AssetVersioning::None,
        ));
        $this->templates->addEngine(new PhpTemplateEngine());
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->root . '/*') ?: [] as $file) {
            @\unlink($file);
        }

        @\rmdir($this->root);

        parent::tearDown();
    }

    private function write(string $name, string $contents): void
    {
        \file_put_contents($this->root . '/' . $name . '.php', $contents);
    }

    // ---- scope ------------------------------------------------------------

    public function test_data_arrives_as_local_variables(): void
    {
        $this->write('greet', '<?= $greeting ?>, <?= $name ?>');

        self::assertSame(
            'Hello, Ada',
            $this->templates->render('greet', ['greeting' => 'Hello', 'name' => 'Ada']),
        );
    }

    public function test_the_view_and_the_escaper_are_always_in_scope(): void
    {
        $this->write('scope', '<?= \get_debug_type($view) ?>|<?= \get_debug_type($e) ?>');

        self::assertSame(
            'App\\Engine\\Template\\TemplateView|App\\Engine\\Template\\Escaper',
            $this->templates->render('scope'),
        );
    }

    /**
     * Losing the escaper to a data key called "e" would be a security bug with
     * a very long fuse: every <?= $e($value) ?> in the application would start
     * calling whatever the handler happened to pass.
     */
    public function test_data_cannot_overwrite_the_escaper_or_the_view(): void
    {
        $this->write('clobber', '<?= \get_debug_type($e) ?>|<?= \get_debug_type($view) ?>');

        self::assertSame(
            'App\\Engine\\Template\\Escaper|App\\Engine\\Template\\TemplateView',
            $this->templates->render('clobber', ['e' => 'clobbered', 'view' => 'clobbered']),
        );
    }

    /** The shadowed value is not lost, only kept out of the way. */
    public function test_a_shadowed_key_is_still_readable_through_the_view(): void
    {
        $this->write('shadowed', '<?= $view->get("e") ?>');

        self::assertSame('mine', $this->templates->render('shadowed', ['e' => 'mine']));
    }

    /**
     * A template that could see $this could reach the engine's internals, and
     * from there anything the engine holds.
     */
    public function test_a_template_has_no_this(): void
    {
        $this->write('scope-this', '<?= isset($this) ? "bound" : "unbound" ?>');

        self::assertSame('unbound', $this->templates->render('scope-this'));
    }

    /**
     * The include path must survive extract(), or a template whose data has a
     * key named after it would include something else entirely.
     */
    public function test_the_include_path_cannot_be_hijacked_by_a_data_key(): void
    {
        $this->write('safe', 'the right file');

        self::assertSame(
            'the right file',
            $this->templates->render('safe', ['__path' => '/etc/passwd', '__data' => 'nonsense']),
        );
    }

    // ---- composition ------------------------------------------------------

    public function test_a_partial_is_rendered_through_the_view(): void
    {
        $this->write('row', '[<?= $label ?>]');
        $this->write('list', '<?= $view->render("row", ["label" => "a"]) ?><?= $view->render("row", ["label" => "b"]) ?>');

        self::assertSame('[a][b]', $this->templates->render('list'));
    }

    /**
     * A partial that could see its parent's variables is a partial whose
     * contract is "whatever happened to be in scope".
     */
    public function test_a_partial_does_not_inherit_its_parents_variables(): void
    {
        $this->write('child', '<?= $view->has("secret") ? "leaked" : "clean" ?>');
        $this->write('parent', '<?= $view->render("child") ?>');

        self::assertSame('clean', $this->templates->render('parent', ['secret' => 'value']));
    }

    /** A layout is a template handed a string, not a keyword. */
    public function test_a_layout_is_just_a_template_that_is_given_its_content(): void
    {
        $this->write('page', '<p>body</p>');
        $this->write('shell', '<main><?= $content ?></main>');

        self::assertSame(
            '<main><p>body</p></main>',
            $this->templates->render('shell', ['content' => $this->templates->render('page')]),
        );
    }

    // ---- failure ----------------------------------------------------------

    /**
     * A template that throws halfway through has already written markup.
     * Gluing that half-page onto the error response is how a stack trace ends
     * up inside a <table>.
     */
    public function test_output_written_before_a_throw_is_discarded(): void
    {
        $this->write('broken', '<p>printed first</p><?php throw new \RuntimeException("nope"); ?>');

        $level = \ob_get_level();

        try {
            $this->templates->render('broken');
            self::fail('the throwing template did not throw');
        } catch (TemplateException $e) {
            self::assertStringContainsString('Rendering "broken" failed', $e->getMessage());
            self::assertStringContainsString('nope', $e->getMessage());
            self::assertSame($level, \ob_get_level(), 'a buffer was left open');
        }
    }

    /** A template that opens a buffer and forgets to close it is also cleaned up. */
    public function test_a_buffer_left_open_by_a_failing_template_is_closed(): void
    {
        $this->write('leaky', '<?php \ob_start(); echo "swallowed"; throw new \RuntimeException("boom"); ?>');

        $level = \ob_get_level();

        try {
            $this->templates->render('leaky');
            self::fail('the leaky template did not throw');
        } catch (TemplateException) {
            self::assertSame($level, \ob_get_level());
        }
    }

    public function test_the_original_error_is_kept_as_the_cause(): void
    {
        $this->write('thrower', '<?php throw new \LogicException("the real problem"); ?>');

        try {
            $this->templates->render('thrower');
            self::fail('nothing was thrown');
        } catch (TemplateException $e) {
            self::assertInstanceOf(\LogicException::class, $e->getPrevious());
            self::assertSame('the real problem', $e->getPrevious()->getMessage());
        }
    }

    public function test_it_claims_php_and_phtml(): void
    {
        self::assertSame(['php', 'phtml'], (new PhpTemplateEngine())->extensions());
    }
}
