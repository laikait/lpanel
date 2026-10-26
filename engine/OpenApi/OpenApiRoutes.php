<?php

declare(strict_types=1);

namespace App\Engine\OpenApi;

use App\Engine\Auth\AccessRegistry;
use App\Engine\Auth\AuthException;
use App\Engine\Auth\AuthGuard;
use App\Engine\Config\Config;
use App\Engine\Routing\Route;
use App\Engine\Routing\Router;

/**
 * The routes openapi.route and openapi.docs ask for, added after the modules.
 *
 * Ordinary GET routes, so openapi.meta -- auth, can, rate_limit -- is enforced
 * by the same guards as any other route, and every response gets the same
 * headers, the Content-Security-Policy included. Each is marked openapi =>
 * false, so the description never lists its own plumbing.
 *
 * Router::add() keeps a second route for the same path without a word, and
 * which one answers would then depend on registration order; so a path an
 * application route already uses is a boot failure instead.
 */
final class OpenApiRoutes
{
    /**
     * @throws OpenApiException for a path already in use, or docs without a description to show
     * @throws AuthException    for a capability in openapi.meta that nothing declares
     */
    public static function register(Router $router, Config $config, AccessRegistry $access): void
    {
        $spec = self::path($config->string('openapi.route'));
        $docs = self::path($config->string('openapi.docs'));

        if ($spec === null) {
            if ($docs !== null) {
                throw OpenApiException::docsWithoutRoute($docs);
            }

            return;
        }

        $routes = [$spec => OpenApiEndpoint::class];

        if ($docs !== null) {
            $page = [
                $docs => [OpenApiDocs::class, 'page'],
                $docs . '/swagger-ui.css' => [OpenApiDocs::class, 'css'],
                $docs . '/swagger-ui-bundle.js' => [OpenApiDocs::class, 'bundle'],
                $docs . '/docs.js' => [OpenApiDocs::class, 'script'],
            ];

            // openapi.route = '/docs' and openapi.docs = 'docs/' would put the
            // page where the description is, and the page would load itself.
            if (isset($page[$spec])) {
                throw OpenApiException::docsOverRoute($spec);
            }

            $routes = [...$routes, ...$page];
        }

        foreach ($router->routes() as $route) {
            $taken = '/' . \trim($route->path(), '/');

            if (\in_array($route->method(), ['GET', 'HEAD'], true) && isset($routes[$taken])) {
                throw OpenApiException::routeTaken($taken);
            }
        }

        $meta = [...$config->array('openapi.meta'), OpenApiGenerator::META => false];

        // The module routes were checked while they registered; these are
        // added after, so a capability nothing declares would refuse everybody
        // here and look like a routing fault. Say so at boot instead.
        foreach (AuthGuard::capabilitiesFor((new Route('GET', $spec, 'x'))->meta($meta)) as $capability) {
            if (!$access->hasPermission($capability)) {
                throw AuthException::undeclaredCapability($capability, 'openapi.meta');
            }
        }

        $names = [$spec => 'openapi'];

        if ($docs !== null) {
            $names[$docs] = 'openapi.docs';
        }

        foreach ($routes as $path => $handler) {
            $route = $router->add(new Route('GET', $path, $handler))->meta($meta);

            if (isset($names[$path])) {
                $route->name($names[$path]);
            }
        }
    }

    /**
     * Whether openapi.meta lets only somebody signed in through: auth, or a
     * capability. What security:check asks of a production description.
     *
     * @param array<array-key, mixed> $meta
     */
    public static function isGuarded(array $meta): bool
    {
        // The guard's own rule, so ['can' => false] or ['can' => ['']] -- which
        // enforce nothing -- are not reported as a login they do not require.
        return AuthGuard::isProtected((new Route('GET', '/', null))->meta($meta));
    }

    /** "/docs" from "docs", "/docs/" or " /docs "; null for unset or empty. */
    public static function path(?string $configured): ?string
    {
        if ($configured === null || \trim($configured, '/ ') === '') {
            return null;
        }

        return '/' . \trim($configured, '/ ');
    }
}
