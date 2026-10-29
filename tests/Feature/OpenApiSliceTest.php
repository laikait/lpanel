<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\AuthException;
use App\Engine\Cli\CommandDispatcher;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\ConsoleKernel;
use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Engine\Error\ErrorHandler;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Request;
use App\Engine\OpenApi\OpenApiException;
use App\Engine\OpenApi\OpenApiGenerator;
use App\Engine\Routing\RouteCollector;
use App\Engine\Routing\Router;
use App\Engine\Security\Signer;
use App\Tests\Support\TestCase;

/**
 * The description of the showcase's real API, through a real application:
 * the routes its module marked, with the schemas its handlers enforce, from
 * the console and -- when asked for -- over HTTP behind the normal guards.
 */
final class OpenApiSliceTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function app(array $config = []): Application
    {
        $config['security']['key'] ??= Signer::generate();

        return $this->application($config)->boot();
    }

    /** @return array<string, mixed> */
    private function document(Application $app): array
    {
        return $app->container()->get(OpenApiGenerator::class)->generate();
    }

    public function test_the_marked_api_is_described_and_nothing_else(): void
    {
        $paths = $this->document($this->app())['paths'];

        self::assertSame(['/api/v1/customers', '/api/v1/customers/{id}'], \array_keys($paths));
        self::assertSame(['get', 'post'], \array_keys($paths['/api/v1/customers']));
    }

    public function test_the_schemas_are_the_ones_the_handlers_enforce(): void
    {
        $document = $this->document($this->app());
        $schemas = $document['components']['schemas'];

        self::assertSame(['Error', 'customer.input', 'customer.resource'], \array_keys($schemas));
        self::assertSame(['name', 'email'], $schemas['customer.input']['required']);
        self::assertSame(['type' => 'string', 'minLength' => 1, 'maxLength' => 120, 'description' => 'The customer name, as it appears on documents.'], $schemas['customer.input']['properties']['name']);
        self::assertStringContainsString('Must be an email address.', $schemas['customer.input']['properties']['email']['description']);

        $list = $document['paths']['/api/v1/customers']['get'];
        self::assertSame(['page', 'per_page'], \array_column($list['parameters'], 'name'));
        self::assertSame(100, $list['parameters'][1]['schema']['maximum']);
        self::assertSame(['200', '429'], \array_map(\strval(...), \array_keys($list['responses'])));

        $show = $document['paths']['/api/v1/customers/{id}']['get'];
        self::assertSame(['type' => 'integer', 'description' => 'The customer id.', 'pattern' => '^(?:\d+)$'], $show['parameters'][0]['schema']);
    }

    public function test_the_endpoint_is_off_unless_configured(): void
    {
        self::assertSame(404, $this->app()->handle(Request::create('GET', '/openapi.json'))->status());
    }

    public function test_the_endpoint_serves_the_document_with_an_etag(): void
    {
        $app = $this->app(['openapi' => ['route' => '/openapi.json']]);
        $response = $app->handle(Request::create('GET', '/openapi.json'));

        self::assertSame(200, $response->status());
        self::assertStringStartsWith('application/json', (string) $response->header('Content-Type'));
        self::assertSame($this->document($app)['paths'], \json_decode($response->body(), true)['paths']);
        self::assertArrayNotHasKey('/openapi.json', $this->document($app)['paths']);

        $etag = (string) $response->header('ETag');
        $again = $app->handle(Request::create('GET', '/openapi.json', ['headers' => ['If-None-Match' => $etag]]));

        self::assertSame(304, $again->status());
        self::assertSame('', $again->body());

        // A compressing proxy hands the client a weak tag; it still matches.
        $weak = $app->handle(Request::create('GET', '/openapi.json', ['headers' => ['If-None-Match' => 'W/' . $etag]]));
        self::assertSame(304, $weak->status());
    }

    public function test_the_endpoint_is_guarded_like_any_route(): void
    {
        $app = $this->app(['openapi' => ['route' => '/openapi.json', 'meta' => ['auth' => true]]]);

        self::assertSame(401, $app->handle(Request::create('GET', '/openapi.json'))->status());
        self::assertSame(200, $app->handle(Request::create('GET', '/openapi.json', [
            'headers' => ['Authorization' => 'Bearer ada-token-do-not-use'],
        ]))->status());
    }

    /** The endpoint is added after the modules' routes were checked, so it is checked here. */
    public function test_a_capability_nothing_declares_is_a_boot_failure_not_a_lockout(): void
    {
        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('"api.docz"');
        $this->expectExceptionMessage('openapi.meta');

        $this->app(['openapi' => ['route' => '/openapi.json', 'meta' => ['can' => 'api.docz']]]);
    }

    public function test_a_declared_capability_guards_the_endpoint(): void
    {
        $app = $this->app(['openapi' => ['route' => '/openapi.json', 'meta' => ['can' => 'user.list']]]);

        self::assertSame(401, $app->handle(Request::create('GET', '/openapi.json'))->status());
    }

    public function test_an_application_route_on_that_path_is_a_boot_failure(): void
    {
        $this->expectException(OpenApiException::class);
        $this->expectExceptionMessage('GET /api/v1/customers');

        $this->app(['openapi' => ['route' => '/api/v1/customers']]);
    }

    public function test_the_export_prints_the_document_and_only_the_document(): void
    {
        $app = $this->app();
        [$status, $output] = $this->console($app, 'openapi:export', '--compact');

        self::assertSame(0, $status);
        self::assertSame($this->document($app), \json_decode($output, true));
    }

    public function test_the_export_fails_on_a_meta_mistake(): void
    {
        $app = $this->app();
        (new RouteCollector($app->container()->get(Router::class), 'Example'))
            ->get('/broken', static fn(): string => '')
            ->meta(['openapi' => ['sumary' => 'typo']]);

        [$status, $output, $errors] = $this->console($app, 'openapi:export');

        self::assertSame(1, $status);
        self::assertSame('', $output);
        self::assertStringContainsString('"sumary" is not an openapi meta key', $errors);
    }

    public function test_security_check_fails_on_an_open_description_in_production(): void
    {
        // Findings are messages about the run, so they are on stderr.
        [, , $errors] = $this->console($this->app(['app' => ['env' => 'production'], 'openapi' => ['route' => '/openapi.json']]), 'security:check');

        self::assertStringContainsString('FAIL  openapi.route serves the API description at /openapi.json to anyone', $errors);

        [, $guarded] = $this->console($this->app(['app' => ['env' => 'production'], 'openapi' => ['route' => '/openapi.json', 'meta' => ['auth' => true]]]), 'security:check', '--verbose');

        self::assertStringContainsString('ok    The API description at /openapi.json requires a login.', $guarded);
    }

    /** ['can' => false] enforces nothing, so it must not pass for a login. */
    public function test_security_check_judges_the_rule_the_guard_enforces(): void
    {
        $app = $this->app(['app' => ['env' => 'production'], 'openapi' => ['route' => '/openapi.json', 'meta' => ['can' => false]]]);

        self::assertSame(200, $app->handle(Request::create('GET', '/openapi.json'))->status());

        [, , $errors] = $this->console($app, 'security:check');
        self::assertStringContainsString('FAIL  openapi.route serves the API description at /openapi.json to anyone', $errors);
    }

    /** @return array{int, string, string} */
    private function console(Application $app, string ...$arguments): array
    {
        $stream = \fopen('php://memory', 'r+');
        $errors = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        self::assertIsResource($errors);

        $status = (new ConsoleKernel(
            $app->container()->get(CommandRegistry::class),
            $app->container()->get(CommandDispatcher::class),
            $app->container()->get(HookEngine::class),
            $app->container()->get(ErrorHandler::class),
            new Output($stream, $errors),
        ))->handle(ExecutionContext::cli(\array_values(['laika', ...$arguments])));

        \rewind($stream);
        \rewind($errors);
        $read = [(string) \stream_get_contents($stream), (string) \stream_get_contents($errors)];
        \fclose($stream);
        \fclose($errors);

        return [$status, ...$read];
    }
}
