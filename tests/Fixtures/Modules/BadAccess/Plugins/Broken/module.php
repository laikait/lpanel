<?php

declare(strict_types=1);

use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;

/**
 * A module with a typo in a capability name, used by exactly one test.
 *
 * "user.lst" is the mistake this fixture exists to reproduce: a route that
 * refuses everybody, including the administrator who holds every role, and
 * looks like a routing fault or a broken login because the one thing it never
 * says is that the capability does not exist.
 */
return static function (ModuleContext $module): void {
    $module->name('Broken Access')->version('0.0.1');

    $module->routes(static function (RouteCollector $routes): void {
        $routes->get('/people', static fn(): array => [])
            ->name('broken.people')
            ->meta(['can' => 'user.lst']);
    });
};
