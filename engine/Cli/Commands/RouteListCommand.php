<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Auth\AuthGuard;
use App\Engine\Cli\Output;
use App\Engine\Routing\Route;
use App\Engine\Routing\Router;

/**
 * Every registered route and the module that put it there.
 *
 * The --module option is not decoration: it is the framework's own command
 * proving that an option declared in one place arrives as a typed parameter
 * here, with no $this->option('module') anywhere in sight.
 */
final class RouteListCommand
{
    public function __construct(private readonly Router $router) {}

    public function __invoke(Output $output, ?string $module = null, bool $names = false): int
    {
        $routes = $this->router->routes();

        if ($module !== null) {
            $routes = \array_values(\array_filter(
                $routes,
                static fn(Route $route): bool => $route->module() === $module,
            ));
        }

        if ($names) {
            $routes = \array_values(\array_filter(
                $routes,
                static fn(Route $route): bool => $route->routeName() !== null,
            ));
        }

        if ($routes === []) {
            $output->line($module === null ? 'No routes are registered.' : \sprintf('No routes belong to "%s".', $module));

            return 0;
        }

        \usort(
            $routes,
            static fn(Route $a, Route $b): int => [$a->path(), $a->method()] <=> [$b->path(), $b->method()],
        );

        $output->table(
            ['METHOD', 'PATH', 'NAME', 'MODULE', 'ACCESS', 'HANDLER'],
            \array_map(
                static fn(Route $route): array => [
                    $route->method(),
                    $route->path(),
                    $route->routeName() ?? '-',
                    $route->module() ?? '-',
                    self::describeAccess($route),
                    self::describeHandler($route->handler()),
                ],
                $routes,
            ),
        );

        return 0;
    }

    /**
     * What a route requires before it will run.
     *
     * Worth a column of its own because requiring a login is opt-in, which
     * means the interesting routes are the ones that say "public" here and
     * change something. Reading it down the page is how somebody notices --
     * and security:check turns the same question into a warning.
     */
    private static function describeAccess(Route $route): string
    {
        $capabilities = AuthGuard::capabilitiesFor($route);

        if ($capabilities !== []) {
            return \implode(' + ', $capabilities);
        }

        return $route->metaValue(AuthGuard::AUTH_META) === true ? 'login' : 'public';
    }

    private static function describeHandler(mixed $handler): string
    {
        if (\is_string($handler)) {
            return $handler;
        }

        if (\is_array($handler) && \count($handler) === 2) {
            $target = $handler[0];
            $class = \is_object($target) ? $target::class : (\is_string($target) ? $target : '?');

            return $class . '::' . (\is_string($handler[1]) ? $handler[1] : '?') . '()';
        }

        if ($handler instanceof \Closure) {
            return 'Closure';
        }

        return \get_debug_type($handler);
    }
}
