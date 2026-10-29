<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dispatch;

use App\Engine\Container\Container;
use App\Engine\Dispatch\Dispatcher;
use App\Engine\Dispatch\DispatchException;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Route;
use App\Engine\Routing\RouteCollector;
use App\Engine\Routing\RouteMatch;
use App\Engine\Routing\Router;
use App\Tests\Fixtures\Dispatch\Greeting;
use App\Tests\Fixtures\Dispatch\InvokableHandler;
use App\Tests\Fixtures\Dispatch\MethodHandler;
use App\Tests\Support\TestCase;

final class DispatcherTest extends TestCase
{
    private Container $container;

    private HookEngine $hooks;

    private FilterEngine $filters;

    private Dispatcher $dispatcher;

    private Router $router;

    private RouteCollector $routes;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->hooks = new HookEngine();
        $this->filters = new FilterEngine();
        $this->dispatcher = new Dispatcher($this->container, $this->hooks, $this->filters);
        $this->router = new Router();
        $this->routes = new RouteCollector($this->router, 'Example');
    }

    private function dispatch(string $method, string $path): Response
    {
        $request = Request::create($method, $path);
        $match = $this->router->match($method, $path);

        self::assertTrue($match->isMatched(), \sprintf('%s %s did not match a route', $method, $path));

        return $this->dispatcher->dispatch($request, $match);
    }

    // ---- handler forms ----------------------------------------------------

    public function test_a_closure_handler_runs(): void
    {
        $this->routes->get('/ping', static fn(): Response => new Response('pong'));

        self::assertSame('pong', $this->dispatch('GET', '/ping')->body());
    }

    public function test_an_invokable_class_is_resolved_with_its_dependencies(): void
    {
        $this->container->instance(Greeting::class, new Greeting('hello'));
        $this->routes->get('/greet', InvokableHandler::class);

        self::assertSame('hello', $this->dispatch('GET', '/greet')->body());
    }

    public function test_a_class_and_method_handler_runs(): void
    {
        $this->container->instance(Greeting::class, new Greeting('hi'));
        $this->routes->get('/greet', [MethodHandler::class, 'greet']);

        self::assertSame('hi', $this->dispatch('GET', '/greet')->body());
    }

    public function test_a_class_double_colon_method_string_handler_runs(): void
    {
        $this->routes->get('/static', MethodHandler::class . '::staticGreet');

        self::assertSame('static', $this->dispatch('GET', '/static')->body());
    }

    public function test_an_already_constructed_invokable_object_runs(): void
    {
        $this->routes->get('/greet', new InvokableHandler(new Greeting('instance')));

        self::assertSame('instance', $this->dispatch('GET', '/greet')->body());
    }

    /**
     * A traditional controller base class is deliberately not required, and
     * nothing about the dispatcher assumes one exists.
     */
    public function test_no_handler_form_requires_a_base_class(): void
    {
        self::assertFalse((new \ReflectionClass(InvokableHandler::class))->getParentClass());
        self::assertFalse((new \ReflectionClass(MethodHandler::class))->getParentClass());
    }

    // ---- route parameters -------------------------------------------------

    public function test_route_parameters_arrive_as_named_arguments(): void
    {
        $this->routes->get('/customers/{id}', [MethodHandler::class, 'showString']);

        self::assertSame('id=42', $this->dispatch('GET', '/customers/42')->body());
    }

    public function test_parameters_are_matched_by_name_not_by_position(): void
    {
        $this->routes->get('/{first}/{second}', [MethodHandler::class, 'reversedOrder']);

        // The handler declares ($second, $first); binding is by name, so the
        // path segments still land in the right arguments.
        self::assertSame('second=b first=a', $this->dispatch('GET', '/a/b')->body());
    }

    public function test_an_int_parameter_is_coerced(): void
    {
        $this->routes->get('/customers/{id}', [MethodHandler::class, 'showInt']);

        self::assertSame('int:42', $this->dispatch('GET', '/customers/42')->body());
    }

    public function test_a_negative_int_parameter_is_coerced(): void
    {
        $this->routes->get('/offset/{value}', [MethodHandler::class, 'offset']);

        self::assertSame('int:-5', $this->dispatch('GET', '/offset/-5')->body());
    }

    public function test_a_float_parameter_is_coerced(): void
    {
        $this->routes->get('/amount/{value}', [MethodHandler::class, 'amount']);

        self::assertSame('float:12.5', $this->dispatch('GET', '/amount/12.5')->body());
    }

    public function test_a_bool_parameter_is_coerced(): void
    {
        $this->routes->get('/flag/{value}', [MethodHandler::class, 'flag']);

        self::assertSame('bool:true', $this->dispatch('GET', '/flag/true')->body());
        self::assertSame('bool:false', $this->dispatch('GET', '/flag/0')->body());
    }

    /**
     * The whole point of coercing narrowly: a non-numeric value for an int
     * parameter is a client mistake and must read as one, not as a 500 with a
     * TypeError in it.
     */
    public function test_a_value_of_the_wrong_type_is_a_bad_request_not_a_type_error(): void
    {
        $this->routes->get('/customers/{id}', [MethodHandler::class, 'showInt']);

        try {
            $this->dispatch('GET', '/customers/abc');
            self::fail('expected a 400');
        } catch (HttpException $e) {
            self::assertSame(400, $e->status());
            self::assertStringContainsString('"id"', $e->getMessage());
            self::assertStringContainsString('must be an integer', $e->getMessage());
            self::assertStringContainsString('abc', $e->getMessage());
        }
    }

    public function test_an_unparseable_bool_is_also_a_bad_request(): void
    {
        $this->routes->get('/flag/{value}', [MethodHandler::class, 'flag']);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('must be a boolean');

        $this->dispatch('GET', '/flag/perhaps');
    }

    /**
     * A route parameter literally called "request" must not be able to displace
     * the Request object, because the Request is bound by type.
     */
    public function test_a_route_parameter_named_request_cannot_hijack_the_request(): void
    {
        $this->routes->get('/spoof/{request}', [MethodHandler::class, 'echoesRequestPath']);

        self::assertSame('/spoof/evil', $this->dispatch('GET', '/spoof/evil')->body());
    }

    public function test_a_handler_may_ignore_parameters_the_route_captures(): void
    {
        $this->routes->get('/customers/{id}/notes/{note}', [MethodHandler::class, 'showString']);

        self::assertSame('id=7', $this->dispatch('GET', '/customers/7/notes/3')->body());
    }

    // ---- result conversion ------------------------------------------------

    public function test_a_string_becomes_an_html_response(): void
    {
        $this->routes->get('/x', static fn(): string => '<p>hello</p>');

        $response = $this->dispatch('GET', '/x');

        self::assertSame(200, $response->status());
        self::assertSame('<p>hello</p>', $response->body());
        self::assertSame('text/html; charset=UTF-8', $response->contentType());
    }

    public function test_an_array_becomes_a_json_response(): void
    {
        $this->routes->get('/x', static fn(): array => ['name' => 'Ada']);

        $response = $this->dispatch('GET', '/x');

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(['name' => 'Ada'], $response->data());
    }

    public function test_a_json_serializable_becomes_a_json_response(): void
    {
        $this->routes->get('/x', static fn(): \JsonSerializable => new class implements \JsonSerializable {
            /** @return array<string, string> */
            public function jsonSerialize(): array
            {
                return ['serialised' => 'yes'];
            }
        });

        self::assertInstanceOf(JsonResponse::class, $this->dispatch('GET', '/x'));
    }

    public function test_null_becomes_a_no_content_response(): void
    {
        $this->routes->get('/x', static fn(): null => null);

        $response = $this->dispatch('GET', '/x');

        self::assertSame(204, $response->status());
        self::assertSame('', $response->body());
    }

    public function test_a_response_passes_through_untouched(): void
    {
        $original = new JsonResponse(['a' => 1], 201);
        $this->routes->get('/x', static fn(): Response => $original);

        self::assertSame($original, $this->dispatch('GET', '/x'));
    }

    /**
     * Guessing at an unexpected return type is how a framework ends up serving
     * the string "Array" to a customer. Failing loudly is the better trade.
     */
    public function test_an_unsupported_return_type_fails_loudly(): void
    {
        $this->routes->get('/x', static fn(): int => 42);

        try {
            $this->dispatch('GET', '/x');
            self::fail('expected a dispatch failure');
        } catch (DispatchException $e) {
            self::assertStringContainsString('returned int', $e->getMessage());
            self::assertStringContainsString('GET /x', $e->getMessage());
        }
    }

    // ---- handler resolution failures --------------------------------------

    public function test_a_class_without_invoke_is_reported_clearly(): void
    {
        $this->routes->get('/x', Greeting::class);

        $this->expectException(DispatchException::class);
        $this->expectExceptionMessage('no __invoke() method');

        $this->dispatch('GET', '/x');
    }

    public function test_an_unknown_handler_class_is_reported_clearly(): void
    {
        $this->routes->get('/x', 'App\\Nope\\Missing');

        $this->expectException(DispatchException::class);
        $this->expectExceptionMessage('no such class');

        $this->dispatch('GET', '/x');
    }

    public function test_an_unknown_method_is_reported_clearly(): void
    {
        $this->routes->get('/x', [MethodHandler::class, 'noSuchMethod']);

        $this->expectException(DispatchException::class);
        $this->expectExceptionMessage('no such method');

        $this->dispatch('GET', '/x');
    }

    // ---- hooks and filters ------------------------------------------------

    public function test_the_lifecycle_hooks_fire_in_order(): void
    {
        $seen = [];

        foreach (['route.matched', 'dispatch.before', 'dispatch.after'] as $hook) {
            $this->hooks->add($hook, static function () use (&$seen, $hook): void {
                $seen[] = $hook;
            });
        }

        $this->routes->get('/x', static fn(): string => 'ok');
        $this->dispatch('GET', '/x');

        self::assertSame(['route.matched', 'dispatch.before', 'dispatch.after'], $seen);
    }

    public function test_the_route_and_request_reach_the_lifecycle_hooks(): void
    {
        $captured = null;

        $this->hooks->add('route.matched', static function (Route $route, Request $request) use (&$captured): void {
            $captured = [$route->path(), $request->path(), $route->module()];
        });

        $this->routes->get('/x', static fn(): string => 'ok');
        $this->dispatch('GET', '/x');

        self::assertSame(['/x', '/x', 'Example'], $captured);
    }

    public function test_the_dispatch_after_hook_receives_the_finished_response(): void
    {
        $status = null;

        $this->hooks->add('dispatch.after', static function (Response $response) use (&$status): void {
            $status = $response->status();
        });

        $this->routes->get('/x', static fn(): null => null);
        $this->dispatch('GET', '/x');

        self::assertSame(204, $status);
    }

    public function test_the_handler_filter_can_substitute_the_handler(): void
    {
        $this->filters->add(
            'dispatch.handler',
            static fn(): \Closure => static fn(): string => 'substituted',
        );

        $this->routes->get('/x', static fn(): string => 'original');

        self::assertSame('substituted', $this->dispatch('GET', '/x')->body());
    }

    public function test_the_parameters_filter_can_rewrite_the_parameters(): void
    {
        $this->filters->add('dispatch.parameters', static function (array $parameters): array {
            $parameters['id'] = '99';

            return $parameters;
        });

        $this->routes->get('/customers/{id}', [MethodHandler::class, 'showInt']);

        self::assertSame('int:99', $this->dispatch('GET', '/customers/1')->body());
    }

    public function test_the_result_filter_runs_before_conversion(): void
    {
        $this->filters->add('dispatch.result', static fn(mixed $result): array => ['wrapped' => $result]);

        $this->routes->get('/x', static fn(): string => 'raw');

        $response = $this->dispatch('GET', '/x');

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(['wrapped' => 'raw'], $response->data());
    }

    public function test_the_route_reaches_the_filters_as_context(): void
    {
        $seen = null;

        $this->filters->add('dispatch.result', static function (mixed $result, Route $route) use (&$seen): mixed {
            $seen = $route->metaValue('audit');

            return $result;
        });

        $this->routes->get('/x', static fn(): string => 'ok')->meta(['audit' => true]);
        $this->dispatch('GET', '/x');

        self::assertTrue($seen);
    }

    /**
     * Route metadata plus a lifecycle hook is the framework's answer to
     * cross-cutting concerns. This is what replaces middleware.
     */
    public function test_metadata_and_a_hook_together_replace_middleware(): void
    {
        $this->hooks->add('dispatch.before', static function (Route $route): void {
            if ($route->metaValue('auth') === true) {
                throw new HttpException(401, 'Authentication required.');
            }
        });

        $this->routes->get('/public', static fn(): string => 'open');
        $this->routes->get('/private', static fn(): string => 'secret')->meta(['auth' => true]);

        self::assertSame('open', $this->dispatch('GET', '/public')->body());

        try {
            $this->dispatch('GET', '/private');
            self::fail('expected the auth hook to reject the request');
        } catch (HttpException $e) {
            self::assertSame(401, $e->status());
        }
    }

    public function test_dispatching_a_match_without_a_route_is_refused(): void
    {
        $this->expectException(DispatchException::class);
        $this->expectExceptionMessage('no route');

        $this->dispatcher->dispatch(Request::create('GET', '/x'), RouteMatch::notFound());
    }
}
