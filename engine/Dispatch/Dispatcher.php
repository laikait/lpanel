<?php

declare(strict_types=1);

namespace App\Engine\Dispatch;

use App\Engine\Container\Container;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Route;
use App\Engine\Routing\RouteMatch;

/**
 * Runs a matched route and turns what it returns into a response.
 *
 * This is where the extension points for a request live. The naming convention
 * throughout the framework is that hooks are "<subject>.<what-happened>" and
 * filters are named after the value they carry:
 *
 *   route.matched        hook    Route, Request
 *   dispatch.handler     filter  the handler declaration
 *   dispatch.parameters  filter  the captured route parameters
 *   dispatch.before      hook    Route, Request
 *   dispatch.result      filter  whatever the handler returned, before conversion
 *   dispatch.response    filter  the finished Response, with its Route
 *   dispatch.after       hook    Response, Route, Request
 *
 * There is no middleware pipeline. Cross-cutting behaviour listens on
 * dispatch.before and reads Route::metadata(), which composes without every
 * route having to know a pipeline exists.
 */
final class Dispatcher
{
    public function __construct(
        private readonly Container $container,
        private readonly HookEngine $hooks,
        private readonly FilterEngine $filters,
        private readonly HandlerResolver $resolver = new HandlerResolver(),
    ) {}

    public function dispatch(Request $request, RouteMatch $match): Response
    {
        $route = $match->route ?? throw new DispatchException('Cannot dispatch a match that has no route.');

        $this->hooks->do('route.matched', $route, $request);

        /** @var mixed $handler */
        $handler = $this->filters->apply('dispatch.handler', $route->handler(), $route, $request);

        /** @var array<string, string|int|float|bool|null> $parameters */
        $parameters = $this->filters->apply('dispatch.parameters', $match->parameters, $route, $request);

        $this->hooks->do('dispatch.before', $route, $request);

        $callable = $this->resolver->resolve($handler);

        /** @var mixed $result */
        $result = $this->container->call(
            $callable,
            $this->resolver->arguments($callable, $parameters, $request),
        );

        /** @var mixed $result */
        $result = $this->filters->apply('dispatch.result', $result, $route, $request);

        $response = $this->toResponse($result, $route);

        // The only point in the lifecycle where the finished Response and the
        // Route that produced it both exist. That combination is what
        // cross-cutting response behaviour needs, and it is how this framework
        // does without middleware: a filter here reads Route::metadata() and
        // decorates accordingly -- an API version header, a Deprecation
        // header, a cache policy that belongs to one group of routes.
        //
        // response.instance, later in the kernel, sees every response including
        // the error ones, but by then the route is gone.
        /** @var Response $response */
        $response = $this->filters->apply('dispatch.response', $response, $route, $request);

        $this->hooks->do('dispatch.after', $response, $route, $request);

        return $response;
    }

    /**
     * Convert a handler's return value into a response.
     *
     * The supported shapes are few and explicit. Anything else is a failure
     * rather than a guess: silently stringifying an unexpected value is how a
     * framework ends up serving "Array" to a customer.
     */
    private function toResponse(mixed $result, Route $route): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (\is_string($result)) {
            return (new Response($result))->withContentType('text/html');
        }

        if (\is_array($result) || $result instanceof \JsonSerializable) {
            return new JsonResponse($result);
        }

        if ($result === null) {
            return new Response('', 204);
        }

        throw DispatchException::unsupportedResult(
            \get_debug_type($result),
            \sprintf('%s %s', $route->method(), $route->path()),
        );
    }
}
