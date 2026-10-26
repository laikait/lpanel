<?php

declare(strict_types=1);

namespace App\Engine\Routing;

use App\Engine\Support\Unicode;

/**
 * Route storage, matching and URL generation.
 *
 * Matching is two-tiered: an O(1) hash lookup for fully static paths, which is
 * the overwhelming majority of requests in a backend application, and a segment
 * trie for everything with parameters.
 *
 * The trie is preferred over the chunked-combined-regex approach for four
 * reasons, all of which matter more as the route table grows:
 *
 *  - It is what route caching wants. The compiled structure is nested plain
 *    arrays, so it var_export()s to a file and comes back through require()
 *    with no reconstruction step.
 *  - Per-segment constraints stay cheap: one short preg_match against one
 *    segment, and only where a constraint was actually declared.
 *  - There is no pathological backtracking. A large PCRE alternation over
 *    hundreds of routes is a genuine production risk; walking segments is
 *    linear in path depth and independent of how many routes exist.
 *  - It is debuggable. "Why did my route not match?" is answered by printing
 *    a nested array.
 *
 * Compilation is lazy, so a CLI run or a request that hits the static tier
 * never pays for building the trie.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $named = [];

    /** @var array<string, Route> "METHOD /path" => route */
    private array $static = [];

    /** @var array<string, array<string, mixed>> method => trie root */
    private array $trie = [];

    private bool $compiled = false;

    public function __construct(private string $basePath = '') {}

    /**
     * The prefix url() prepends. Set once the request is known, since the same
     * application answers at "/framework" under Apache and at "" under php -S.
     */
    public function setBasePath(string $basePath): void
    {
        $this->basePath = \rtrim($basePath, '/');
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function add(Route $route): Route
    {
        if (!\in_array($route->method(), Route::METHODS, true)) {
            throw RoutingException::unsupportedMethod($route->method());
        }

        $this->routes[] = $route;
        $this->compiled = false;

        return $route;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    public function count(): int
    {
        return \count($this->routes);
    }

    public function route(string $name): ?Route
    {
        $this->compile();

        return $this->named[$name] ?? null;
    }

    /** @return array<string, Route> */
    public function namedRoutes(): array
    {
        $this->compile();

        return $this->named;
    }

    // ---- matching ---------------------------------------------------------

    public function match(string $method, string $path): RouteMatch
    {
        $this->compile();

        $method = \strtoupper($method);
        $path = $this->normalize($path);

        $result = $this->matchMethod($method, $path);

        if ($result !== null) {
            return $result;
        }

        // HEAD is GET without a body. Falling back keeps every GET route
        // answerable by HEAD without the author declaring it twice.
        if ($method === 'HEAD') {
            $result = $this->matchMethod('GET', $path);

            if ($result !== null) {
                return $result;
            }
        }

        // Nothing matched for this method. Distinguishing 404 from 405 requires
        // asking whether any other method would have matched.
        $allowed = $this->allowedMethodsFor($path, $method);

        return $allowed === [] ? RouteMatch::notFound() : RouteMatch::methodNotAllowed($allowed);
    }

    private function matchMethod(string $method, string $path): ?RouteMatch
    {
        $route = $this->static[$method . ' ' . $path] ?? null;

        if ($route !== null) {
            return RouteMatch::matched($route);
        }

        $root = $this->trie[$method] ?? null;

        if ($root === null) {
            return null;
        }

        return $this->walk($root, Route::segments($path), []);
    }

    /**
     * @param array<string, mixed>  $node
     * @param list<string>          $segments
     * @param array<string, string> $parameters
     */
    private function walk(array $node, array $segments, array $parameters): ?RouteMatch
    {
        if ($segments === []) {
            $route = $node['route'] ?? null;

            if ($route instanceof Route) {
                return RouteMatch::matched($route, [...$route->defaultValues(), ...$parameters]);
            }

            // A trailing optional parameter is allowed to be absent.
            $optional = $node['optional'] ?? null;

            if (\is_array($optional) && ($optional['route'] ?? null) instanceof Route) {
                /** @var Route $route */
                $route = $optional['route'];

                return RouteMatch::matched($route, [...$route->defaultValues(), ...$parameters]);
            }

            return null;
        }

        $segment = $segments[0];
        $rest = \array_slice($segments, 1);

        // A literal always beats a parameter at the same depth. Static routes
        // win over dynamic ones, deterministically and without ordering rules.
        $literals = $node['literals'] ?? [];

        if (\is_array($literals) && isset($literals[$segment]) && \is_array($literals[$segment])) {
            $matched = $this->walk($literals[$segment], $rest, $parameters);

            if ($matched !== null) {
                return $matched;
            }
        }

        foreach (($node['params'] ?? []) as $parameter) {
            if (!\is_array($parameter)) {
                continue;
            }

            $pattern = $parameter['pattern'] ?? null;

            // As UTF-8, so \p{L} means a letter in any script rather than a
            // byte. A segment that is not valid UTF-8 fails every constraint.
            if (\is_string($pattern) && \preg_match('#^(?:' . $pattern . ')$#u', $segment) !== 1) {
                continue;
            }

            $child = $parameter['node'] ?? null;

            if (!\is_array($child)) {
                continue;
            }

            /** @var string $name */
            $name = $parameter['name'];
            $matched = $this->walk($child, $rest, [...$parameters, $name => $segment]);

            if ($matched !== null) {
                return $matched;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function allowedMethodsFor(string $path, string $attempted): array
    {
        $allowed = [];

        foreach (Route::METHODS as $method) {
            if ($method === $attempted) {
                continue;
            }

            if ($this->matchMethod($method, $path) !== null) {
                $allowed[] = $method;
            }
        }

        // Any GET route also answers HEAD, so advertise it.
        if (\in_array('GET', $allowed, true) && !\in_array('HEAD', $allowed, true) && $attempted !== 'HEAD') {
            $allowed[] = 'HEAD';
        }

        \sort($allowed);

        return $allowed;
    }

    // ---- URL generation ---------------------------------------------------

    /**
     * Build the URL for a named route.
     *
     * Parameters that the path does not declare become the query string, which
     * is what makes url('customers.index', ['page' => 2]) do the obvious thing.
     *
     * @param array<string, string|int|float> $parameters
     */
    public function url(string $name, array $parameters = []): string
    {
        $route = $this->route($name) ?? throw RoutingException::unknownName($name);

        $values = [...$route->defaultValues(), ...$parameters];
        $segments = [];
        $consumed = [];

        foreach (Route::segments($this->normalize($route->path())) as $segment) {
            if (!\str_starts_with($segment, '{')) {
                // Only the bytes outside ASCII, so a URL for /পণ্য is as
                // encoded as its parameters while /v1:batch stays as written.
                $segments[] = (string) \preg_replace_callback(
                    '/[\x80-\xFF]+/',
                    static fn(array $bytes): string => \rawurlencode($bytes[0]),
                    $segment,
                );

                continue;
            }

            $inner = \substr($segment, 1, -1);
            $optional = \str_ends_with($inner, '?');
            $parameter = $optional ? \substr($inner, 0, -1) : $inner;

            if (!isset($values[$parameter])) {
                if ($optional) {
                    continue;
                }

                throw RoutingException::missingParameter($name, $parameter);
            }

            $value = Unicode::nfc((string) $values[$parameter]);
            $pattern = $route->constraint($parameter);

            // Generating a URL the router would then refuse to match is a bug
            // worth surfacing at the call site rather than at the next request.
            if ($pattern !== null && \preg_match('#^(?:' . $pattern . ')$#u', $value) !== 1) {
                throw RoutingException::parameterRejected($name, $parameter, $value, $pattern);
            }

            $segments[] = \rawurlencode($value);
            $consumed[] = $parameter;
        }

        $path = '/' . \implode('/', $segments);
        $url = $this->basePath . ($path === '/' && $this->basePath !== '' ? '' : $path);

        $query = \array_diff_key($values, \array_flip($consumed), $route->defaultValues());

        if ($query !== []) {
            $url .= '?' . \http_build_query($query);
        }

        return $url === '' ? '/' : $url;
    }

    // ---- compilation ------------------------------------------------------

    /**
     * Build the static map and the trie.
     *
     * Called lazily from the read paths, and cheap to call repeatedly: it
     * returns immediately unless a route was added since the last compile.
     */
    public function compile(): void
    {
        if ($this->compiled) {
            return;
        }

        $this->static = [];
        $this->trie = [];
        $this->named = [];

        foreach ($this->routes as $route) {
            $this->registerName($route);
            $this->insert($route);
            $route->freeze();
        }

        $this->compiled = true;
    }

    private function registerName(Route $route): void
    {
        $name = $route->routeName();

        if ($name === null) {
            return;
        }

        if (isset($this->named[$name]) && $this->named[$name] !== $route) {
            throw RoutingException::duplicateName($name, $route->module(), $this->named[$name]->module());
        }

        $this->named[$name] = $route;
    }

    private function insert(Route $route): void
    {
        $path = $this->normalize($route->path());
        $parameters = $route->parameters();

        if ($parameters === []) {
            $this->static[$route->method() . ' ' . $path] = $route;

            return;
        }

        $node = &$this->trie[$route->method()];
        $node ??= [];

        $segments = Route::segments($path);
        $lastIndex = \count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if (!\str_starts_with($segment, '{')) {
                $node['literals'][$segment] ??= [];
                $node = &$node['literals'][$segment];

                continue;
            }

            $inner = \substr($segment, 1, -1);
            $optional = \str_ends_with($inner, '?');
            $name = $optional ? \substr($inner, 0, -1) : $inner;
            $pattern = $route->constraint($name);

            if ($optional && $index === $lastIndex) {
                // The "absent" branch hangs off the parent so that walking can
                // finish there when the segment simply is not present.
                $node['optional'] = ['route' => $route];
            }

            $key = $name . '|' . ($pattern ?? '');
            $position = null;

            foreach (($node['params'] ?? []) as $existingIndex => $existing) {
                if (\is_array($existing) && ($existing['key'] ?? null) === $key) {
                    $position = $existingIndex;

                    break;
                }
            }

            if ($position === null) {
                $node['params'][] = ['key' => $key, 'name' => $name, 'pattern' => $pattern, 'node' => []];
                $position = \array_key_last($node['params']);
            }

            $node = &$node['params'][$position]['node'];
        }

        $node['route'] = $route;
        unset($node);
    }

    /**
     * One spelling of a path, for both sides of a comparison: the route as
     * declared and the path as requested are each NFC, so a route file saved
     * by an editor that decomposes accents still matches.
     */
    private function normalize(string $path): string
    {
        $path = '/' . \ltrim(Unicode::nfc($path), '/');

        return $path === '/' ? $path : \rtrim($path, '/');
    }
}
