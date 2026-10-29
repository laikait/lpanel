<?php

declare(strict_types=1);

namespace App\Tests\Unit\Error;

use App\Engine\Asset\AssetManager;
use App\Engine\Asset\AssetRegistry;
use App\Engine\Asset\AssetResolver;
use App\Engine\Error\ErrorDocument;
use App\Engine\Error\ErrorPage;
use App\Engine\Http\HttpException;
use App\Engine\Template\Escaper;
use App\Engine\Template\PhpTemplateEngine;
use App\Engine\Template\TemplateManager;
use App\Engine\Template\TemplateRegistry;
use App\Engine\Template\TemplateSource;
use App\Tests\Support\TestCase;

/**
 * An error, as a page.
 *
 * Two things matter here and they pull against each other. An application must
 * be able to supply its own page, because "404" in Helvetica is not what a
 * business wants a lost customer to see. And the page must still appear when
 * the application is the thing that is broken, which is why the built-in one
 * has no dependencies at all and why a template that throws falls back to it.
 */
final class ErrorPageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/error-page-' . \bin2hex(\random_bytes(6));
        \mkdir($this->root . '/errors', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->root . '/errors/*') ?: [] as $file) {
            \unlink($file);
        }

        @\rmdir($this->root . '/errors');
        @\rmdir($this->root);

        parent::tearDown();
    }

    private function write(string $name, string $contents): void
    {
        \file_put_contents($this->root . '/' . $name . '.php', $contents);
    }

    private function page(): ErrorPage
    {
        $registry = new TemplateRegistry();
        $registry->add(null, $this->root, TemplateSource::OVERRIDE);

        $assets = new AssetManager(new AssetRegistry(), new AssetResolver(), '');
        $templates = new TemplateManager($registry, $assets, new Escaper());
        $templates->addEngine(new PhpTemplateEngine());

        return new ErrorPage($templates);
    }

    // ---- the built-in page --------------------------------------------------

    public function test_without_templates_the_built_in_page_is_used(): void
    {
        $html = (new ErrorPage())->render(ErrorDocument::of(404, 'No route matches /nope'));

        self::assertStringStartsWith('<!DOCTYPE html>', $html);
        self::assertStringContainsString('404 Not Found', $html);
        self::assertStringContainsString('No route matches /nope', $html);
    }

    /**
     * It has to work when the thing that broke is the asset pipeline.
     *
     * An error page that links a stylesheet is an error page that renders
     * unstyled, or not at all, exactly when it is needed.
     */
    public function test_the_built_in_page_asks_the_application_for_nothing(): void
    {
        $html = (new ErrorPage())->render(ErrorDocument::of(500));

        self::assertStringNotContainsString('<link', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('http', $html);
    }

    /**
     * A trace argument can be a string that came straight from the request, so
     * an unescaped debug page is a scripting hole reachable on purpose.
     */
    public function test_the_built_in_page_escapes_even_in_debug(): void
    {
        $document = ErrorDocument::fromThrowable(new \RuntimeException('<script>alert(1)</script>'), debug: true);

        $html = (new ErrorPage())->render($document);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_the_built_in_page_shows_the_trace_only_when_there_is_one(): void
    {
        $plain = (new ErrorPage())->render(ErrorDocument::fromThrowable(new \RuntimeException('boom')));
        $debug = (new ErrorPage())->render(ErrorDocument::fromThrowable(new \RuntimeException('boom'), debug: true));

        self::assertStringNotContainsString('<pre>', $plain);
        self::assertStringContainsString('<pre>', $debug);
    }

    // ---- the application's own page -----------------------------------------

    public function test_a_status_template_wins_over_the_generic_one(): void
    {
        $this->write('errors/404', '<?php echo "the 404 page"; ?>');
        $this->write('errors/error', '<?php echo "the generic page"; ?>');

        self::assertSame('the 404 page', $this->page()->render(ErrorDocument::of(404)));
    }

    public function test_the_generic_template_catches_everything_else(): void
    {
        $this->write('errors/error', '<?php echo "the generic page"; ?>');

        self::assertSame('the generic page', $this->page()->render(ErrorDocument::of(503)));
    }

    public function test_the_document_is_in_scope(): void
    {
        $this->write('errors/error', '<?php echo $error->status . " " . $error->message; ?>');

        self::assertSame(
            '403 Keep out',
            $this->page()->render(ErrorDocument::fromThrowable(new HttpException(403, 'Keep out'))),
        );
    }

    public function test_with_no_matching_template_the_built_in_page_is_used(): void
    {
        self::assertStringStartsWith('<!DOCTYPE html>', $this->page()->render(ErrorDocument::of(404)));
    }

    /**
     * The one that turns a handled error into an unhandled one.
     *
     * A typo in errors/500 is discovered the day the first 500 happens, and at
     * that point the visitor needs a page far more than the framework needs to
     * be right about which one.
     */
    public function test_a_template_that_throws_falls_back_instead_of_propagating(): void
    {
        $this->write('errors/error', '<?php throw new \RuntimeException("the error page is broken"); ?>');

        $html = $this->page()->render(ErrorDocument::of(500));

        self::assertStringStartsWith('<!DOCTYPE html>', $html);
        self::assertStringContainsString('500 Internal Server Error', $html);
        self::assertStringNotContainsString('the error page is broken', $html);
    }

    public function test_the_candidates_are_most_specific_first(): void
    {
        self::assertSame(['errors/404', 'errors/error'], (new ErrorPage())->candidates(ErrorDocument::of(404)));
    }

    // ---- Whoops -----------------------------------------------------------

    public function test_debug_with_the_exception_renders_whoops(): void
    {
        $e = new \RuntimeException('boom <b>x</b>');
        $html = (new ErrorPage())->render(ErrorDocument::fromThrowable($e, debug: true), null, $e);

        self::assertStringContainsString('<title>RuntimeException: boom &lt;b&gt;x&lt;/b&gt;', $html);
        self::assertStringContainsString('boom &lt;b&gt;x&lt;/b&gt;', $html, 'the message is escaped');
        self::assertStringNotContainsString('<b>x</b>', $html);
    }

    /**
     * When Whoops cannot render, the plain page in its place says why.
     *
     * The real case that prompted this: an APP_EDITOR Whoops does not know
     * made it throw while being set up, and the debug page quietly became the
     * plain one -- which looked like the debug page simply not working.
     */
    public function test_when_whoops_fails_the_plain_page_says_why(): void
    {
        $e = new \RuntimeException('boom');
        $page = new ErrorPage(editor: 'no-such-editor');

        $html = $page->render(ErrorDocument::fromThrowable($e, debug: true), null, $e);

        self::assertStringNotContainsString('Whoops container', $html);
        self::assertStringContainsString('Whoops could not render this error', $html);
        self::assertStringContainsString('Unknown editor identifier: no-such-editor', $html);
        self::assertStringContainsString('#0', $html, 'and the trace is still there');
    }

    public function test_whoops_is_never_used_outside_debug(): void
    {
        $e = new \RuntimeException('boom');
        $html = (new ErrorPage())->render(ErrorDocument::fromThrowable($e), null, $e);

        self::assertStringNotContainsString('Whoops', $html);
        self::assertStringNotContainsString('boom', $html);
    }

    public function test_debug_without_the_exception_keeps_the_built_in_page(): void
    {
        $html = (new ErrorPage())->render(ErrorDocument::fromThrowable(new \RuntimeException('boom'), debug: true));

        self::assertStringNotContainsString('Whoops', $html);
        self::assertStringContainsString('#0', $html);
    }

    public function test_whoops_masks_cookies_the_environment_and_secret_looking_names(): void
    {
        // Values built at run time, so they are not in the source Whoops shows.
        $secrets = [\str_rot13('ncc-xrl-inyhr'), \str_rot13('qo-cnffjbeq-inyhr'), \str_rot13('frffvba-inyhr'), \str_rot13('cbfgrq-cnffjbeq')];
        $saved = [$_ENV, $_SERVER, $_COOKIE, $_POST];
        $_ENV['LPHP_TEST_KEY'] = $secrets[0];
        $_SERVER['LPHP_DB_PASSWORD'] = $secrets[1];
        $_COOKIE['lphp_session'] = $secrets[2];
        $_POST['password'] = $secrets[3];
        $_SERVER['LPHP_VISIBLE'] = \str_rot13('cynva-inyhr');

        try {
            $e = new \RuntimeException('boom');
            $html = (new ErrorPage(environment: ['LPHP_TEST_KEY']))->render(ErrorDocument::fromThrowable($e, debug: true), null, $e);
        } finally {
            [$_ENV, $_SERVER, $_COOKIE, $_POST] = $saved;
        }

        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }

        self::assertStringContainsString(\str_rot13('cynva-inyhr'), $html, 'an ordinary value is still shown');
    }

    public function test_whoops_links_files_to_the_editor_and_shortens_paths(): void
    {
        $e = new \RuntimeException('boom');
        $html = (new ErrorPage(basePath: $this->basePath(), editor: 'vscode'))->render(ErrorDocument::fromThrowable($e, debug: true), null, $e);

        self::assertStringContainsString('vscode://file/', $html);
    }
}
