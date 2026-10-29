<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\OpenApi\OpenApiDocs;
use App\Engine\OpenApi\OpenApiException;
use App\Engine\Security\Signer;
use App\Tests\Support\TestCase;

/**
 * Swagger UI at openapi.docs: off unless asked for, behind the same access
 * rule as the description, and working under the default
 * Content-Security-Policy with nothing loaded from anywhere else.
 */
final class OpenApiDocsTest extends TestCase
{
    /**
     * The vendored swagger-ui-dist files, as npm published OpenApiDocs::SWAGGER_UI
     * (the tarball's sha512 was checked against the registry). A hash that
     * changes means the files changed: update them, the version and this list
     * together.
     */
    private const VENDORED = [
        'LICENSE' => 'cfc7749b96f63bd31c3c42b5c471bf756814053e847c10f3eb003417bc523d30',
        'NOTICE' => '0d20d1adef18aee3f40dd258172155521ce702ac445cb5f7b7d60ed32dad2fb2',
        'swagger-ui-bundle.js' => '050bc415ee7048dcd881682678f720264e7da5e373f7461d7c58c755305255f7',
        'swagger-ui-bundle.js.LICENSE.txt' => '63818894e4b04cd0e3180d9cb20761e227a939121e7484f8e1d528227c756f89',
        'swagger-ui.css' => '1ac324f7dcd27e4b9386b4bd6421271ec147e922a22c05ba24b11515e9aa6321',
    ];

    /**
     * @param array<string, mixed> $openapi
     * @param array<string, mixed> $config
     */
    private function app(array $openapi = ['route' => '/openapi.json', 'docs' => '/docs'], array $config = []): Application
    {
        $config['security']['key'] ??= Signer::generate();
        $config['openapi'] = $openapi;

        return $this->application($config)->boot();
    }

    /** @param array<string, string> $headers */
    private function get(Application $app, string $path, array $headers = []): Response
    {
        return $app->handle(Request::create('GET', $path, ['headers' => $headers]));
    }

    public function test_the_page_is_off_unless_asked_for(): void
    {
        self::assertSame(404, $this->get($this->app(['route' => '/openapi.json']), '/docs')->status());
    }

    public function test_a_page_with_no_description_to_show_is_a_boot_failure(): void
    {
        $this->expectException(OpenApiException::class);
        $this->expectExceptionMessage('openapi.route is not set');

        $this->app(['docs' => '/docs']);
    }

    public function test_the_page_and_the_description_cannot_share_a_path(): void
    {
        $this->expectException(OpenApiException::class);
        $this->expectExceptionMessage('openapi.route and openapi.docs both need /docs');

        $this->app(['route' => '/docs', 'docs' => 'docs/']);
    }

    /**
     * docs.js is the framework's own and changes without a Swagger UI
     * release, so its year-long cache is keyed by its contents.
     */
    public function test_the_framework_s_script_is_versioned_by_its_contents(): void
    {
        $hash = \hash_file('sha256', $this->basePath('engine/OpenApi/docs.js'));

        self::assertIsString($hash);
        self::assertSame(\substr($hash, 0, 12), OpenApiDocs::version('docs.js'));
        self::assertSame(OpenApiDocs::SWAGGER_UI, OpenApiDocs::version('swagger-ui-bundle.js'));
    }

    public function test_the_page_points_swagger_ui_at_the_description(): void
    {
        $response = $this->get($this->app(), '/docs');
        $html = $response->body();

        self::assertSame(200, $response->status());
        self::assertStringStartsWith('text/html', (string) $response->header('Content-Type'));
        self::assertStringContainsString('data-spec="/openapi.json"', $html);
        self::assertStringContainsString('href="/docs/swagger-ui.css?v=' . OpenApiDocs::SWAGGER_UI . '"', $html);
        self::assertStringContainsString('<script src="/docs/swagger-ui-bundle.js?v=' . OpenApiDocs::SWAGGER_UI . '"></script>', $html);
        self::assertStringContainsString('<script src="/docs/docs.js?v=' . OpenApiDocs::version('docs.js') . '"></script>', $html);
        self::assertStringContainsString('<meta name="robots" content="noindex">', $html);
    }

    public function test_the_page_has_no_inline_script_and_loads_nothing_from_elsewhere(): void
    {
        $html = $this->get($this->app(), '/docs')->body();

        \preg_match_all('/<script\b[^>]*>(.*?)<\/script>/s', $html, $scripts, \PREG_SET_ORDER);
        self::assertCount(2, $scripts);

        foreach ($scripts as $script) {
            self::assertSame('', $script[1], 'An inline script would be refused by the default CSP.');
            self::assertMatchesRegularExpression('/src="\//', $script[0]);
        }

        self::assertDoesNotMatchRegularExpression('/(src|href)="(https?:)?\/\//', $html);
    }

    public function test_the_default_csp_covers_the_page_and_allows_what_it_loads(): void
    {
        $policy = (string) $this->get($this->app(config: ['app' => ['debug' => false]]), '/docs')->header('Content-Security-Policy');

        self::assertStringContainsString("script-src 'self'", $policy);
        self::assertStringContainsString("style-src 'self' 'unsafe-inline'", $policy);
    }

    public function test_the_page_inside_a_subdirectory_uses_the_base_path(): void
    {
        // As under XAMPP: the request knows it arrived at /lphp/docs.
        $html = $this->app()->handle(Request::create('GET', '/docs', ['basePath' => '/lphp']))->body();

        self::assertStringContainsString('data-spec="/lphp/openapi.json"', $html);
        self::assertStringContainsString('src="/lphp/docs/swagger-ui-bundle.js?v=', $html);
    }

    public function test_the_title_is_escaped(): void
    {
        $html = $this->get($this->app(['route' => '/openapi.json', 'docs' => '/docs', 'title' => '<b>Shop</b>']), '/docs')->body();

        self::assertStringContainsString('<title>&lt;b&gt;Shop&lt;/b&gt; - API reference</title>', $html);
    }

    public function test_the_files_are_served_with_their_types_and_cached_by_version(): void
    {
        $app = $this->app();

        foreach (['swagger-ui.css' => 'text/css', 'swagger-ui-bundle.js' => 'text/javascript', 'docs.js' => 'text/javascript'] as $file => $type) {
            $response = $this->get($app, '/docs/' . $file);

            self::assertSame(200, $response->status(), $file);
            self::assertStringStartsWith($type, (string) $response->header('Content-Type'), $file);
            self::assertSame('nosniff', $response->header('X-Content-Type-Options'), $file);
            self::assertSame('public, max-age=31536000, immutable', $response->header('Cache-Control'), $file);
        }

        self::assertSame(\file_get_contents($this->basePath('engine/OpenApi/ui/swagger-ui-bundle.js')), $this->get($app, '/docs/swagger-ui-bundle.js')->body());
    }

    public function test_the_page_and_its_files_share_the_description_s_access_rule(): void
    {
        $app = $this->app(['route' => '/openapi.json', 'docs' => '/docs', 'meta' => ['auth' => true]]);
        $token = ['Authorization' => 'Bearer ada-token-do-not-use'];

        foreach (['/docs', '/docs/swagger-ui-bundle.js', '/docs/docs.js', '/openapi.json'] as $path) {
            self::assertSame(401, $this->get($app, $path)->status(), $path);
            self::assertSame(200, $this->get($app, $path, $token)->status(), $path);
        }

        self::assertSame('private, max-age=31536000, immutable', $this->get($app, '/docs/docs.js', $token)->header('Cache-Control'));
    }

    public function test_the_page_is_never_described_by_the_description(): void
    {
        $document = \json_decode($this->get($this->app(), '/openapi.json')->body(), true);

        self::assertIsArray($document);
        self::assertSame(['/api/v1/customers', '/api/v1/customers/{id}'], \array_keys($document['paths']));
    }

    public function test_an_application_route_on_a_docs_path_is_a_boot_failure(): void
    {
        $this->expectException(OpenApiException::class);
        $this->expectExceptionMessage('GET /api/v1/customers');

        $this->app(['route' => '/openapi.json', 'docs' => '/api/v1/customers']);
    }

    public function test_the_ui_does_not_call_the_public_validator_or_keep_tokens(): void
    {
        $script = (string) \file_get_contents($this->basePath('engine/OpenApi/docs.js'));

        self::assertStringContainsString('validatorUrl: null', $script);
        self::assertStringContainsString('persistAuthorization: false', $script);
    }

    public function test_the_vendored_files_are_exactly_the_published_release(): void
    {
        $directory = $this->basePath('engine/OpenApi/ui');
        $present = \array_values(\array_diff((array) \scandir($directory), ['.', '..']));
        \sort($present);

        self::assertSame(\array_keys(self::VENDORED), $present, 'engine/OpenApi/ui holds the release files and nothing else.');

        foreach (self::VENDORED as $file => $hash) {
            self::assertSame($hash, \hash_file('sha256', $directory . '/' . $file), $file . ' is not the file npm published.');
        }
    }
}
