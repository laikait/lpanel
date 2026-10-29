<?php

declare(strict_types=1);

use App\Engine\Container\ServiceRegistrar;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Tests\Fixtures\Modules\Plugins\Alpha\Handlers\Recorder;

return static function (ModuleContext $module): void {
    $module
        ->name('Shared')
        ->version('1.0.0')
        ->description('Cross-module services and values.');

    $module->config(['currency' => 'USD']);

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(Recorder::class);
    });

    $module->routes(static function (RouteCollector $routes): void {
        $routes->get('/shared/ping', static fn(): string => 'shared-pong')->name('shared.ping');
    });

    // Listeners that need an injected instance are registered from onBoot,
    // where the container supplies it. Declaring [Recorder::class, 'record']
    // above would mean a STATIC call, which is what PHP array callables are.
    $module->onBoot(static function (Recorder $recorder, HookEngine $hooks, FilterEngine $filters): void {
        $recorder->booted[] = 'Shared';

        $hooks->add('order.recorded', [$recorder, 'record'], 5, 'Shared');

        // Priority 100 runs last, so it stamps the finished response.
        $filters->add('response.instance', [$recorder, 'stamp'], 100, 'Shared');
    });
};
