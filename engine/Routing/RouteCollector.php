<?php

declare(strict_types=1);

namespace App\Engine\Routing;

/**
 * The route registration API a module receives.
 *
 * There is no global route file. A module declares the routes for the capability
 * it owns, and the collector stamps each one with that module so the console can
 * say where a route came from and hooks can attribute behaviour.
 *
 * Groups compose: a nested group's prefix, name prefix and metadata all build on
 * the enclosing one.
 */
final class RouteCollector
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private readonly Router $router,
        private readonly ?string $module = null,
        private readonly string $prefix = '',
        private readonly string $namePrefix = '',
        private readonly array $meta = [],
    ) {}

    public function get(string $path, mixed $handler): Route
    {
        return $this->register('GET', $path, $handler);
    }

    public function post(string $path, mixed $handler): Route
    {
        return $this->register('POST', $path, $handler);
    }

    public function put(string $path, mixed $handler): Route
    {
        return $this->register('PUT', $path, $handler);
    }

    public function patch(string $path, mixed $handler): Route
    {
        return $this->register('PATCH', $path, $handler);
    }

    public function delete(string $path, mixed $handler): Route
    {
        return $this->register('DELETE', $path, $handler);
    }

    public function options(string $path, mixed $handler): Route
    {
        return $this->register('OPTIONS', $path, $handler);
    }

    public function head(string $path, mixed $handler): Route
    {
        return $this->register('HEAD', $path, $handler);
    }

    /**
     * Register the same handler for several methods.
     *
     * Returns the first route so that ->name() and friends still chain; the
     * others receive the same configuration through the shared path.
     *
     * @param list<string> $methods
     *
     * @return list<Route>
     */
    public function match(array $methods, string $path, mixed $handler): array
    {
        $routes = [];

        foreach ($methods as $method) {
            $routes[] = $this->register(\strtoupper($method), $path, $handler);
        }

        return $routes;
    }

    /** @return list<Route> */
    public function any(string $path, mixed $handler): array
    {
        return $this->match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $path, $handler);
    }

    /**
     * @param \Closure(RouteCollector): void $routes
     * @param array<string, mixed>           $meta
     */
    public function group(string $prefix, \Closure $routes, string $name = '', array $meta = []): void
    {
        $routes(new self(
            $this->router,
            $this->module,
            $this->joinPrefix($this->prefix, $prefix),
            $this->namePrefix . $name,
            [...$this->meta, ...$meta],
        ));
    }

    private function register(string $method, string $path, mixed $handler): Route
    {
        // The route carries the group's name prefix, so that ->name('index')
        // inside a group prefixed "api.v1." becomes "api.v1.index" without the
        // module author having to remember to repeat it.
        $route = new Route(
            $method,
            $this->joinPrefix($this->prefix, $path),
            $handler,
            $this->module,
            $this->namePrefix,
        );

        if ($this->meta !== []) {
            $route->meta($this->meta);
        }

        return $this->router->add($route);
    }

    private function joinPrefix(string $left, string $right): string
    {
        $left = \rtrim($left, '/');
        $right = '/' . \trim($right, '/');

        $joined = $left . ($right === '/' ? '' : $right);

        return $joined === '' ? '/' : $joined;
    }
}
