<?php

declare(strict_types=1);

namespace App\Tests\Unit\OpenApi;

use App\Engine\OpenApi\OpenApiException;
use App\Engine\OpenApi\OpenApiGenerator;
use App\Engine\Routing\RouteCollector;
use App\Engine\Routing\Router;
use App\Engine\Schema\Field;
use App\Engine\Schema\Schema;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Routes and their openapi meta as an OpenAPI 3.1 document: only what asked
 * to be described, nothing guessed, and the same bytes every time.
 */
final class OpenApiGeneratorTest extends TestCase
{
    private Router $router;

    private RouteCollector $routes;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->routes = new RouteCollector($this->router, 'Billing');
    }

    private static function handler(): \Closure
    {
        return static fn(): string => 'ok';
    }

    /** @return array<string, mixed> */
    private function document(?string $url = null): array
    {
        return (new OpenApiGenerator($this->router, 'Billing API', '2.0.0', 'Invoices.', $url))->generate();
    }

    /** @return array<string, mixed> */
    private function operation(string $path, string $method = 'get'): array
    {
        $paths = $this->document()['paths'];
        self::assertIsArray($paths);
        self::assertArrayHasKey($path, $paths, 'paths: ' . \implode(', ', \array_keys($paths)));

        return $paths[$path][$method];
    }

    private static function invoice(): Schema
    {
        return Schema::of('invoice', Field::int('id'), Field::string('number')->length(1, 20));
    }

    // ---- the document ---------------------------------------------------------

    public function test_the_document_has_what_openapi_requires(): void
    {
        $this->routes->get('/invoices', self::handler())->meta(['openapi' => true]);

        $document = $this->document();

        self::assertSame('3.1.0', $document['openapi']);
        self::assertSame(['title' => 'Billing API', 'version' => '2.0.0', 'description' => 'Invoices.'], $document['info']);
        self::assertSame([['url' => '/']], $document['servers']);
        self::assertArrayHasKey('/invoices', $document['paths']);
        self::assertArrayNotHasKey('components', $document);
    }

    public function test_an_application_with_nothing_described_is_still_a_valid_document(): void
    {
        $this->routes->get('/', self::handler());

        $json = (new OpenApiGenerator($this->router, 'API'))->json(pretty: false);

        self::assertSame('{"openapi":"3.1.0","info":{"title":"API","version":"1.0.0"},"servers":[{"url":"/"}],"paths":{}}', $json);
    }

    public function test_the_server_is_app_url_and_never_a_host_header(): void
    {
        $this->routes->get('/invoices', self::handler())->meta(['openapi' => true]);

        self::assertSame([['url' => 'https://example.com/shop']], $this->document('https://example.com/shop/')['servers']);

        $this->router->setBasePath('/lphp');
        self::assertSame([['url' => '/lphp']], $this->document()['servers']);
    }

    public function test_the_same_routes_give_the_same_bytes_in_any_order(): void
    {
        $this->routes->post('/b', self::handler())->meta(['openapi' => true]);
        $this->routes->get('/a', self::handler())->meta(['openapi' => true]);
        $this->routes->get('/b', self::handler())->meta(['openapi' => true]);
        $first = (new OpenApiGenerator($this->router, 'API'))->json();

        $other = new Router();
        $routes = new RouteCollector($other, 'Billing');
        $routes->get('/b', self::handler())->meta(['openapi' => true]);
        $routes->get('/a', self::handler())->meta(['openapi' => true]);
        $routes->post('/b', self::handler())->meta(['openapi' => true]);

        self::assertSame($first, (new OpenApiGenerator($other, 'API'))->json());
        self::assertSame(['/a', '/b'], \array_keys($this->document()['paths']));
        self::assertSame(['get', 'post'], \array_keys($this->document()['paths']['/b']));
    }

    // ---- what is included -------------------------------------------------------

    public function test_only_routes_that_ask_are_described(): void
    {
        $this->routes->get('/', self::handler());
        $this->routes->post('/login', self::handler())->meta(['rate_limit' => '5/1m']);
        $this->routes->get('/invoices', self::handler())->meta(['openapi' => true]);
        $this->routes->get('/internal', self::handler())->meta(['openapi' => false]);

        self::assertSame(['/invoices'], \array_keys($this->document()['paths']));
    }

    public function test_a_route_can_leave_a_group_that_opted_in(): void
    {
        $this->routes->group('/api', function (RouteCollector $routes): void {
            $routes->get('/invoices', self::handler());
            $routes->get('/debug', self::handler())->meta(['openapi' => false]);
            $routes->get('/invoices/{id}', self::handler())->meta(['openapi' => ['summary' => 'One']]);
        }, meta: ['openapi' => true]);

        $paths = $this->document()['paths'];

        self::assertSame(['/api/invoices', '/api/invoices/{id}'], \array_keys($paths));
        self::assertSame('One', $paths['/api/invoices/{id}']['get']['summary']);
    }

    public function test_head_is_never_listed_beside_its_get(): void
    {
        $this->routes->get('/invoices', self::handler())->meta(['openapi' => true]);
        $this->routes->head('/invoices', self::handler())->meta(['openapi' => true]);

        self::assertSame(['get'], \array_keys($this->document()['paths']['/invoices']));
    }

    // ---- an operation -----------------------------------------------------------

    public function test_tags_default_to_the_module_and_the_id_to_the_route_name(): void
    {
        $this->routes->get('/invoices', self::handler())->name('invoices.index')->meta(['openapi' => true]);
        $this->routes->get('/credits', self::handler())->meta(['openapi' => ['tags' => ['Credit'], 'operationId' => 'listCredits']]);
        $this->routes->get('/refunds/{id}', self::handler())->meta(['openapi' => true]);

        self::assertSame(['tags' => ['Billing'], 'operationId' => 'invoices_index'], $this->operation('/invoices'));
        self::assertSame(['Credit'], $this->operation('/credits')['tags']);
        self::assertSame('listCredits', $this->operation('/credits')['operationId']);
        self::assertSame('get_refunds_id', $this->operation('/refunds/{id}')['operationId']);
    }

    public function test_path_parameters_come_from_the_path_and_its_where(): void
    {
        $this->routes->get('/invoices/{id}/lines/{line}', self::handler())
            ->where('line', '\d+')
            ->meta(['openapi' => ['path' => ['id' => Field::int('id')->describedAs('The invoice.')]]]);

        self::assertSame([
            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer', 'description' => 'The invoice.'], 'description' => 'The invoice.'],
            ['name' => 'line', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^(?:\d+)$']],
        ], $this->operation('/invoices/{id}/lines/{line}')['parameters']);
    }

    public function test_an_optional_parameter_is_two_paths(): void
    {
        $this->routes->get('/reports/{year?}', self::handler())->name('reports')->meta(['openapi' => true]);

        $paths = $this->document()['paths'];

        self::assertSame(['/reports', '/reports/{year}'], \array_keys($paths));
        self::assertSame('reports', $paths['/reports/{year}']['get']['operationId']);
        self::assertSame('reports_without_year', $paths['/reports']['get']['operationId']);
        self::assertArrayNotHasKey('parameters', $paths['/reports']['get']);
    }

    public function test_query_and_header_schemas_become_parameters(): void
    {
        $this->routes->get('/invoices', self::handler())->meta(['openapi' => [
            'query' => Schema::of('paging', Field::int('page')->default(1)->range(1, null), Field::string('q')),
            'headers' => Schema::of('tenant', Field::string('X-Tenant')),
        ]]);

        self::assertSame([
            ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
            ['name' => 'q', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
            ['name' => 'X-Tenant', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
        ], $this->operation('/invoices')['parameters']);
    }

    /**
     * A body describes; it does not validate. Whether the handler answers 400
     * or 415 is for its responses to say, so neither is promised for it.
     */
    public function test_a_body_is_a_reference_and_promises_no_response_the_handler_may_not_give(): void
    {
        $this->routes->post('/invoices', self::handler())->meta(['csrf' => false, 'openapi' => ['body' => self::invoice()]]);

        $document = $this->document();
        $operation = $document['paths']['/invoices']['post'];

        self::assertSame(['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/invoice']]]], $operation['requestBody']);
        self::assertArrayNotHasKey('responses', $operation);
        self::assertSame(['invoice'], \array_keys($document['components']['schemas']));
    }

    public function test_the_error_shape_is_the_error_document_s(): void
    {
        $this->routes->get('/me', self::handler())->meta(['auth' => true, 'openapi' => true]);

        $document = $this->document();

        self::assertSame(['$ref' => '#/components/schemas/Error'], $document['paths']['/me']['get']['responses'][401]['content']['application/json']['schema']);
        self::assertSame(['error'], $document['components']['schemas']['Error']['required']);
    }

    public function test_a_value_json_cannot_hold_is_an_openapi_mistake_not_a_crash(): void
    {
        $this->routes->get('/rates', self::handler())->meta(['openapi' => [
            'query' => Schema::of('rates', Field::float('ratio')->default(\NAN)),
        ]]);

        $this->expectException(OpenApiException::class);
        $this->expectExceptionMessage('NAN or INF');

        (new OpenApiGenerator($this->router, 'API'))->json();
    }

    public function test_every_response_form(): void
    {
        $this->routes->get('/invoices/{id}', self::handler())->meta(['openapi' => ['responses' => [
            200 => self::invoice(),
            201 => [self::invoice()],
            202 => ['data' => self::invoice()],
            203 => ['data' => [self::invoice()]],
            404 => 'No such invoice',
            'default' => ['description' => 'Something else', 'schema' => self::invoice()],
        ]]]);

        $responses = $this->operation('/invoices/{id}')['responses'];
        $ref = ['$ref' => '#/components/schemas/invoice'];

        self::assertSame(['200', '201', '202', '203', '404', 'default'], \array_map(\strval(...), \array_keys($responses)));
        self::assertSame(['description' => 'OK', 'content' => ['application/json' => ['schema' => $ref]]], $responses[200]);
        self::assertSame(['type' => 'array', 'items' => $ref], $responses[201]['content']['application/json']['schema']);
        self::assertSame(
            ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => $ref]],
            $responses[202]['content']['application/json']['schema'],
        );
        self::assertSame(
            ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'array', 'items' => $ref], 'meta' => ['type' => 'object']]],
            $responses[203]['content']['application/json']['schema'],
        );
        self::assertSame(['description' => 'No such invoice'], $responses[404]);
        self::assertSame('Something else', $responses['default']['description']);
    }

    public function test_a_login_route_is_bearer_secured_with_its_401_and_403(): void
    {
        $this->routes->get('/me', self::handler())->meta(['auth' => true, 'openapi' => true]);
        $this->routes->get('/invoices', self::handler())->meta(['can' => 'invoice.list', 'rate_limit' => '60/1m', 'openapi' => true]);
        $this->routes->get('/public', self::handler())->meta(['openapi' => true]);

        $document = $this->document();

        self::assertSame([['bearerAuth' => []]], $document['paths']['/me']['get']['security']);
        self::assertSame(['401'], \array_map(\strval(...), \array_keys($document['paths']['/me']['get']['responses'])));
        self::assertSame(['401', '403', '429'], \array_map(\strval(...), \array_keys($document['paths']['/invoices']['get']['responses'])));
        self::assertArrayNotHasKey('security', $document['paths']['/public']['get']);
        self::assertArrayNotHasKey('responses', $document['paths']['/public']['get']);
        self::assertSame(['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']], $document['components']['securitySchemes']);
    }

    public function test_a_declared_response_wins_over_a_derived_one(): void
    {
        $this->routes->get('/me', self::handler())->meta(['auth' => true, 'openapi' => ['responses' => [401 => 'Sign in first']]]);

        self::assertSame(['description' => 'Sign in first'], $this->operation('/me')['responses'][401]);
    }

    public function test_security_can_be_overridden(): void
    {
        $this->routes->get('/session', self::handler())->meta(['auth' => true, 'openapi' => ['security' => false]]);

        self::assertArrayNotHasKey('security', $this->operation('/session'));
    }

    public function test_deprecation_comes_from_the_route_s_own_meta(): void
    {
        $this->routes->group('/v0', function (RouteCollector $routes): void {
            $routes->get('/invoices', self::handler());
        }, meta: ['openapi' => true, 'deprecated' => '2026-01-01']);

        self::assertTrue($this->operation('/v0/invoices')['deprecated']);
    }

    public function test_nothing_about_the_handler_reaches_the_document(): void
    {
        $this->routes->get('/invoices', [self::class, 'handler'])->meta(['openapi' => true]);

        $json = (new OpenApiGenerator($this->router, 'API'))->json();

        self::assertStringNotContainsString('OpenApiGeneratorTest', $json);
        self::assertStringNotContainsString(\str_replace('\\', '/', \dirname(__DIR__, 3)), \str_replace('\\\\', '/', $json));
    }

    // ---- mistakes -----------------------------------------------------------------

    /** @return iterable<string, array{array<string, mixed>|string, string}> */
    public static function mistakes(): iterable
    {
        yield 'not an array' => ['yes', 'must be true, false or an array'];
        yield 'unknown key' => [['sumary' => 'x'], '"sumary" is not an openapi meta key'];
        yield 'body not a schema' => [['body' => ['name' => 'string']], 'openapi "body" must be a Schema'];
        yield 'bad status' => [['responses' => [700 => 'x']], '"700" is not a response status'];
        yield 'unknown path parameter' => [['path' => ['nope' => Field::int('nope')]], '"nope", which is not a parameter'];
        yield 'tags not strings' => [['tags' => 'Billing'], 'openapi "tags" must be a list of strings'];
        yield 'security not bool' => [['security' => ['bearer']], 'openapi "security" must be'];
    }

    /** @param array<string, mixed>|string $meta */
    #[DataProvider('mistakes')]
    public function test_a_mistake_names_the_route_and_the_key(array|string $meta, string $message): void
    {
        $this->routes->get('/invoices/{id}', self::handler())->name('invoices.show')->meta(['openapi' => $meta]);

        $this->expectException(OpenApiException::class);
        $this->expectExceptionMessage($message);
        $this->expectExceptionMessage('GET /invoices/{id} (invoices.show)');

        $this->document();
    }

    public function test_two_routes_for_one_operation_are_refused(): void
    {
        $this->routes->get('/invoices', self::handler())->meta(['openapi' => true]);
        $this->routes->get('/invoices/', self::handler())->meta(['openapi' => true]);

        $this->expectException(OpenApiException::class);
        $this->expectExceptionMessage('GET /invoices');

        $this->document();
    }

    public function test_two_operations_with_one_id_are_refused(): void
    {
        $this->routes->get('/a', self::handler())->meta(['openapi' => ['operationId' => 'same']]);
        $this->routes->get('/b', self::handler())->meta(['openapi' => ['operationId' => 'same']]);

        $this->expectException(OpenApiException::class);
        $this->expectExceptionMessage('"same"');

        $this->document();
    }
}
