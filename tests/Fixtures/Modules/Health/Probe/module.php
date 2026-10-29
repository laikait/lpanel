<?php

declare(strict_types=1);

use App\Engine\Http\JsonResponse;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;

/** An application's own health check, replacing the one Shared ships. */
return static function (ModuleContext $module): void {
    $module->name('Probe')->version('1.0.0');

    $module->routes(static function (RouteCollector $routes): void {
        $routes->get('/health', static fn(): JsonResponse => new JsonResponse(['status' => 'custom']))->name('probe.health');
    });
};
