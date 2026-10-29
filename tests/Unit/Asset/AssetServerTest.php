<?php

declare(strict_types=1);

namespace App\Tests\Unit\Asset;

use App\Engine\Asset\AssetKind;
use App\Engine\Asset\AssetRegistry;
use App\Engine\Asset\AssetResolver;
use App\Engine\Asset\AssetServer;
use App\Engine\Filter\FilterEngine;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Tests\Support\TestCase;

final class AssetServerTest extends TestCase
{
    private string $root;

    private AssetRegistry $registry;

    private FilterEngine $filters;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/framework-serve-' . \bin2hex(\random_bytes(6));

        \mkdir($this->root . '/core/css', 0o777, true);
        \mkdir($this->root . '/core/img', 0o777, true);
        \mkdir($this->root . '/plugin/js', 0o777, true);
        \mkdir($this->root . '/admin/css', 0o777, true);

        \file_put_contents($this->root . '/core/css/app.css', 'body{margin:0}');
        \file_put_contents($this->root . '/core/css/build.9c81f4a2.css', 'body{margin:1}');
        \file_put_contents($this->root . '/core/manifest.json', '{"css/build.css": "css/build.9c81f4a2.css"}');
        \file_put_contents($this->root . '/core/img/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        \file_put_contents($this->root . '/core/secret.php', '<?php echo 1;');
        \file_put_contents($this->root . '/plugin/js/example.js', 'export const a = 1;');
        \file_put_contents($this->root . '/admin/css/admin.css', 'nav{}');

        $this->registry = new AssetRegistry();
        $this->registry->publish(AssetKind::Core, null, $this->root . '/core');
        $this->registry->publish(AssetKind::Template, null, $this->root . '/core');
        $this->registry->publish(AssetKind::Template, 'admin', $this->root . '/admin');
        $this->registry->publish(AssetKind::Module, 'Example', $this->root . '/plugin');

        $this->filters = new FilterEngine();
    }

    protected function tearDown(): void
    {
        foreach (['core/css', 'core/img', 'module/js', 'admin/css', 'core'] as $directory) {
            \array_map('unlink', \glob($this->root . '/' . $directory . '/*.*') ?: []);
            @\rmdir($this->root . '/' . $directory);
        }

        foreach (['core', 'plugin', 'admin'] as $directory) {
            @\rmdir($this->root . '/' . $directory);
        }

        @\rmdir($this->root);

        parent::tearDown();
    }

    private function server(bool $debug = false): AssetServer
    {
        return new AssetServer($this->registry, new AssetResolver(), $this->filters, $debug);
    }

    /** @param array<string, string> $headers */
    private function get(string $path, array $headers = [], string $method = 'GET'): Response
    {
        return $this->server()->serve(Request::create($method, $path, ['headers' => $headers]), \strtok($path, '?') ?: $path);
    }

    /**
     * Two buffers, because an asset body is streamed.
     *
     * StreamResponse calls ob_flush() after each chunk, which pushes it to the
     * *enclosing* buffer. With one level that enclosing buffer is the test
     * runner's own output, so the assertion sees an empty string and PHPUnit
     * reports the file contents as stray output. The inner buffer is the one
     * being flushed out of; the outer is the one that collects it, and ending the
     * inner with a flush rather than a clean is what makes a buffered body work
     * through the same helper.
     */
    private function bodyOf(Response $response): string
    {
        \ob_start();
        \ob_start();

        $response->send();

        \ob_end_flush();

        return (string) \ob_get_clean();
    }

    // ---- the URL space ----------------------------------------------------

    public function test_it_only_claims_the_asset_prefix(): void
    {
        $server = $this->server();

        self::assertTrue($server->handles('/assets/core/css/app.css'));
        self::assertTrue($server->handles('/assets'));
        self::assertFalse($server->handles('/customers'));
        self::assertFalse($server->handles('/assetsomething'));
        self::assertFalse($server->handles('/'));
    }

    public function test_it_serves_each_namespace(): void
    {
        foreach ([
            '/assets/core/css/app.css' => 'body{margin:0}',
            '/assets/template/css/app.css' => 'body{margin:0}',
            '/assets/template/admin/css/admin.css' => 'nav{}',
            '/assets/module/Example/js/example.js' => 'export const a = 1;',
        ] as $path => $expected) {
            $response = $this->get($path);

            self::assertSame(200, $response->status(), $path);
            self::assertSame($expected, $this->bodyOf($response), $path);
        }
    }

    /**
     * The one ambiguity in the URL scheme, and the rule that settles it: a
     * second segment naming a registered template means the named form.
     */
    public function test_a_named_template_wins_over_a_directory_of_the_same_name(): void
    {
        self::assertSame(200, $this->get('/assets/template/admin/css/admin.css')->status());

        // "css" is not a registered template, so this stays the unnamed form
        // and reads from the active template's own directory.
        self::assertSame(200, $this->get('/assets/template/css/app.css')->status());
    }

    /**
     * templates/<area>/<theme>/assets/, published as "admin/dark". Two leading
     * segments that name a registered theme win over one, so "admin/dark" is
     * not read as the "admin" theme's dark/ folder.
     */
    public function test_an_area_theme_is_the_longest_match(): void
    {
        $this->registry->publish(AssetKind::Template, 'admin/dark', $this->root . '/plugin');

        $themed = $this->get('/assets/template/admin/dark/js/example.js');

        self::assertSame(200, $themed->status());
        self::assertSame('export const a = 1;', $this->bodyOf($themed));

        // The one-segment theme next to it is untouched.
        self::assertSame(200, $this->get('/assets/template/admin/css/admin.css')->status());
    }

    public function test_a_single_segment_theme_is_served(): void
    {
        $this->registry->publish(AssetKind::Template, 'dark', $this->root . '/admin');

        $response = $this->get('/assets/template/dark/css/admin.css');

        self::assertSame(200, $response->status());
        self::assertSame('nav{}', $this->bodyOf($response));
    }

    /** Explicit means explicit: nothing falls back to templates/assets/. */
    public function test_a_file_missing_from_a_theme_is_a_404_not_a_fallback(): void
    {
        $this->registry->publish(AssetKind::Template, 'admin/dark', $this->root . '/plugin');

        // css/app.css exists in the unnamed template source, not in the theme.
        self::assertSame(200, $this->get('/assets/template/css/app.css')->status());
        self::assertSame(404, $this->get('/assets/template/admin/dark/css/app.css')->status());
    }

    /**
     * The documented cost of longest-first: an area theme named after a
     * folder of the one-segment theme shadows that folder.
     */
    public function test_an_area_theme_shadows_a_folder_of_the_same_name(): void
    {
        self::assertSame(200, $this->get('/assets/template/admin/css/admin.css')->status());

        $this->registry->publish(AssetKind::Template, 'admin/css', $this->root . '/plugin');

        self::assertSame(404, $this->get('/assets/template/admin/css/admin.css')->status());
    }

    public function test_an_unknown_namespace_is_a_404(): void
    {
        self::assertSame(404, $this->get('/assets/nonsense/css/app.css')->status());
        self::assertSame(404, $this->get('/assets/core')->status());
        self::assertSame(404, $this->get('/assets')->status());
    }

    public function test_a_php_file_is_a_404_not_a_download(): void
    {
        self::assertFileExists($this->root . '/core/secret.php');

        $response = $this->get('/assets/core/secret.php');

        self::assertSame(404, $response->status());
        self::assertStringNotContainsString('echo', $this->bodyOf($response));
    }

    public function test_a_traversal_is_a_404_and_says_nothing_useful(): void
    {
        $response = $this->get('/assets/core/../../composer.json');

        self::assertSame(404, $response->status());
        self::assertSame('Not Found', $this->bodyOf($response));
    }

    /**
     * In development the reason is the whole value of the response: the person
     * reading it is the one who made the typo.
     */
    public function test_debug_mode_explains_the_refusal(): void
    {
        $response = $this->server(debug: true)->serve(
            Request::create('GET', '/assets/core/secret.php'),
            '/assets/core/secret.php',
        );

        self::assertSame(404, $response->status());
        self::assertStringContainsString('does not serve', $this->bodyOf($response));
    }

    // ---- headers ----------------------------------------------------------

    public function test_the_content_type_comes_from_the_extension(): void
    {
        self::assertSame('text/css; charset=UTF-8', $this->get('/assets/core/css/app.css')->header('Content-Type'));
        self::assertSame('image/svg+xml; charset=UTF-8', $this->get('/assets/core/img/logo.svg')->header('Content-Type'));
    }

    public function test_sniffing_is_always_disabled(): void
    {
        self::assertSame('nosniff', $this->get('/assets/core/css/app.css')->header('X-Content-Type-Options'));
    }

    /**
     * An SVG is a document that can carry script, and opened directly in a tab
     * it would run on this application's origin. The policy makes it inert.
     */
    public function test_an_svg_is_served_with_a_policy_that_makes_it_inert(): void
    {
        $policy = $this->get('/assets/core/img/logo.svg')->header('Content-Security-Policy');

        self::assertNotNull($policy);
        self::assertStringContainsString("default-src 'none'", $policy);
        self::assertStringContainsString('sandbox', $policy);

        self::assertNull($this->get('/assets/core/css/app.css')->header('Content-Security-Policy'));
    }

    public function test_the_length_and_the_validators_are_present(): void
    {
        $response = $this->get('/assets/core/css/app.css');

        self::assertSame('14', $response->header('Content-Length'));
        self::assertNotNull($response->header('ETag'));
        self::assertNotNull($response->header('Last-Modified'));
    }

    public function test_ranges_are_declined_rather_than_silently_ignored(): void
    {
        self::assertSame('none', $this->get('/assets/core/css/app.css')->header('Accept-Ranges'));
    }

    // ---- caching ----------------------------------------------------------

    public function test_an_unversioned_url_must_be_revalidated(): void
    {
        self::assertSame(
            'public, max-age=0, must-revalidate',
            $this->get('/assets/core/css/app.css')->header('Cache-Control'),
        );
    }

    public function test_a_versioned_url_is_immutable_for_a_year(): void
    {
        $response = $this->server()->serve(
            Request::create('GET', '/assets/core/css/app.css?v=deadbeef'),
            '/assets/core/css/app.css',
        );

        self::assertSame('public, max-age=31536000, immutable', $response->header('Cache-Control'));
    }

    /** A built filename carries its own hash, so it needs no ?v= to be trusted. */
    public function test_a_manifested_filename_is_immutable_without_a_query(): void
    {
        self::assertSame(
            'public, max-age=31536000, immutable',
            $this->get('/assets/core/css/build.9c81f4a2.css')->header('Cache-Control'),
        );
    }

    public function test_a_matching_etag_produces_a_304_with_no_body(): void
    {
        $etag = $this->get('/assets/core/css/app.css')->header('ETag');
        self::assertNotNull($etag);

        $response = $this->get('/assets/core/css/app.css', ['If-None-Match' => $etag]);

        self::assertSame(304, $response->status());
        self::assertSame('', $this->bodyOf($response));
        self::assertSame($etag, $response->header('ETag'));
    }

    public function test_a_stale_etag_produces_the_file(): void
    {
        $response = $this->get('/assets/core/css/app.css', ['If-None-Match' => '"not-it"']);

        self::assertSame(200, $response->status());
    }

    public function test_a_weak_validator_still_matches(): void
    {
        $etag = $this->get('/assets/core/css/app.css')->header('ETag');
        self::assertNotNull($etag);

        self::assertSame(304, $this->get('/assets/core/css/app.css', ['If-None-Match' => 'W/' . $etag])->status());
    }

    public function test_if_modified_since_is_honoured(): void
    {
        $response = $this->get('/assets/core/css/app.css', [
            'If-Modified-Since' => \gmdate('D, d M Y H:i:s', \time() + 60) . ' GMT',
        ]);

        self::assertSame(304, $response->status());
    }

    /**
     * RFC 9110: a present If-None-Match is the answer, and If-Modified-Since is
     * ignored. Otherwise a client with a stale validator and a recent clock
     * would be told nothing changed when it did.
     */
    public function test_an_etag_mismatch_overrides_a_fresh_modification_date(): void
    {
        $response = $this->get('/assets/core/css/app.css', [
            'If-None-Match' => '"not-it"',
            'If-Modified-Since' => \gmdate('D, d M Y H:i:s', \time() + 60) . ' GMT',
        ]);

        self::assertSame(200, $response->status());
    }

    // ---- methods ----------------------------------------------------------

    public function test_only_get_and_head_are_answered(): void
    {
        $response = $this->get('/assets/core/css/app.css', method: 'POST');

        self::assertSame(405, $response->status());
        self::assertSame('GET, HEAD', $response->header('Allow'));
    }

    public function test_head_carries_the_same_headers_as_the_get(): void
    {
        $get = $this->get('/assets/core/css/app.css');
        $head = $this->get('/assets/core/css/app.css', method: 'HEAD');

        self::assertSame($get->headers(), $head->headers());
    }

    // ---- the extension point ----------------------------------------------

    /**
     * The authorisation story for assets, and the reason there is no middleware
     * anywhere in this framework: a gateway's script can be made private with a
     * filter, which composes with everything else without a pipeline.
     */
    public function test_a_filter_can_refuse_an_asset(): void
    {
        $this->filters->add('asset.response', static function (Response $response, string $path): Response {
            return \str_starts_with($path, '/assets/module/') ? new Response('', 403) : $response;
        });

        self::assertSame(403, $this->get('/assets/module/Example/js/example.js')->status());
        self::assertSame(200, $this->get('/assets/core/css/app.css')->status());
    }

    public function test_a_filter_can_add_a_header(): void
    {
        $this->filters->add('asset.response', static fn(Response $response): Response
            => $response->withHeader('Access-Control-Allow-Origin', '*'));

        self::assertSame('*', $this->get('/assets/core/css/app.css')->header('Access-Control-Allow-Origin'));
    }
}
