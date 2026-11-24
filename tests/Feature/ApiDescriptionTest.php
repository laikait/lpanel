<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Engine\Routing\Router;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\PanelUsers;
use App\Modules\Panel\Api\ApiCatalogue;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

/**
 * M11: the API describes itself. Every endpoint is in the catalogue (a new one
 * cannot ship undocumented), the OpenAPI document builds and does not change
 * between runs, only someone who may hold a token can read it, and the copy in
 * design/ is the one the routes give.
 */
final class ApiDescriptionTest extends TestCase
{
    use HostingFixtures;

    private Application $app;

    protected function setUp(): void
    {
        $this->app = $this->app(['app' => ['url' => 'https://panel.example.com:7443']]);
        $this->rootPassword($this->app, 'root-password-123');
    }

    public function test_every_api_route_is_described_and_the_catalogue_names_no_route_that_is_gone(): void
    {
        $router = $this->app->container()->get(Router::class);
        $named = [];
        $undescribed = [];

        foreach ($router->routes() as $route) {
            if (!\str_starts_with($route->path(), '/admin/api/v1') || \in_array($route->path(), ['/admin/api/v1/openapi.json', '/admin/api/v1/docs'], true) || \str_starts_with($route->path(), '/admin/api/v1/docs/')) {
                continue;
            }

            $named[(string) $route->routeName()] = true;

            if (!\is_array($route->metaValue('openapi'))) {
                $undescribed[] = $route->method() . ' ' . $route->path() . ' (' . ($route->routeName() ?? 'unnamed') . ')';
            }
        }

        self::assertSame([], $undescribed, 'add these to modules/Panel/Api/ApiCatalogue.php');
        self::assertSame([], \array_values(\array_diff(\array_keys(ApiCatalogue::all()), \array_keys($named))), 'the catalogue names routes that no longer exist');
        self::assertGreaterThan(150, \count($named));
    }

    public function test_the_document_builds_lists_every_operation_once_and_keeps_its_shape(): void
    {
        $doc = $this->document();

        self::assertSame('3.1.0', $doc['openapi']);
        self::assertSame('LPanel API', $doc['info']['title']);
        self::assertSame('https://panel.example.com:7443', $doc['servers'][0]['url']);
        self::assertArrayHasKey('bearerAuth', $doc['components']['securitySchemes']);

        $ids = [];
        $operations = 0;

        foreach ($doc['paths'] as $path => $methods) {
            self::assertStringStartsWith('/admin/api/v1/', $path);

            foreach ($methods as $method => $op) {
                $operations++;
                $ids[] = $op['operationId'];
                self::assertNotSame('', $op['summary'], $method . ' ' . $path);
                self::assertNotEmpty($op['tags'], $method . ' ' . $path);
                self::assertArrayHasKey('401', $op['responses'], 'the framework\'s own answers are in it');
            }
        }

        self::assertSame(\count($ids), \count(\array_unique($ids)), 'operation ids are unique');
        self::assertGreaterThan(150, $operations);

        // One operation, as a client generator would read it.
        $op = $doc['paths']['/admin/api/v1/accounts']['post'];
        self::assertSame('Create an account (a user, a home, a site)', $op['summary']);
        self::assertStringContainsString('{username, domain, password, package', $op['description']);
        self::assertSame(['Accounts'], $op['tags']);
        $param = $doc['paths']['/admin/api/v1/accounts/{username}']['get']['parameters'][0];
        self::assertSame(['username', 'path', true], [$param['name'], $param['in'], $param['required']]);
    }

    public function test_the_document_is_the_same_every_time_and_is_the_one_kept_in_the_repository(): void
    {
        $first = $this->raw();
        self::assertSame($first, $this->raw());

        // design/openapi.json is the committed copy: `php laika openapi:export > design/openapi.json`
        // after changing the API, so a change to it shows in the diff.
        $file = \dirname(__DIR__, 2) . '/design/openapi.json';
        self::assertFileExists($file, 'run: php laika openapi:export > design/openapi.json');
        // Where it is served from (APP_URL) is the machine's, not the API's.
        $kept = \json_decode((string) \file_get_contents($file), true);
        $now = \json_decode($first, true);
        self::assertIsArray($kept);
        self::assertIsArray($now);
        unset($kept['servers'], $now['servers']);
        self::assertSame($kept, $now, 'the API changed: run `php laika openapi:export > design/openapi.json`');
    }

    public function test_only_someone_who_may_hold_a_token_reads_it(): void
    {
        self::assertSame(401, $this->app->handle(\App\Engine\Http\Request::create('GET', '/admin/api/v1/openapi.json', ['headers' => ['Accept' => 'application/json']]))->status());

        $token = $this->app->container()->get(ApiTokens::class)->issue((string) $this->app->container()->get(PanelUsers::class)->root()->identity->id, 'docs')['token'];
        $json = $this->api('GET', '/admin/api/v1/openapi.json', $token);
        self::assertSame(200, $json->status());
        self::assertSame('3.1.0', $this->json($json)['openapi']);

        $docs = $this->app->handle(\App\Engine\Http\Request::create('GET', '/admin/api/v1/docs', ['headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'text/html']]));
        self::assertSame(200, $docs->status());
        self::assertStringContainsString('swagger', \strtolower($docs->body()));
    }

    private function raw(): string
    {
        return $this->app->container()->get(\App\Engine\OpenApi\OpenApiGenerator::class)->json();
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        $doc = \json_decode($this->raw(), true);

        return \is_array($doc) ? $doc : self::fail('not JSON');
    }
}
