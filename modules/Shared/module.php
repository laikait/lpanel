<?php

declare(strict_types=1);

use App\Engine\Container\Container;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Data\ArraySource;
use App\Engine\Data\DataSource;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\SqlSource;
use App\Engine\Http\JsonResponse;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;

/**
 * LPanel's shared module: only what every other module relies on.
 *
 * It decides where repositories store their data and answers /health for the
 * installer and the service watchdog. Pages, layouts and assets live in Panel,
 * because the shared module cannot publish assets of its own.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Shared')
        ->version('0.1.0')
        ->description('Where repositories store their data, and /health.');

    $module->services(static function (ServiceRegistrar $services): void {
        // A database when DB_DSN is set, memory otherwise (tests that need no SQL).
        $services->singleton(DataSource::class, static function (Container $container): DataSource {
            $connections = $container->get(ConnectionManager::class);

            return $connections->isConfigured()
                ? new SqlSource($connections->connection())
                : new ArraySource();
        });
    });

    $module->routes(static function (RouteCollector $routes): void {
        // For the installer and the service watchdog: 200 while PHP answers.
        $routes->get('/health', static fn(): JsonResponse => new JsonResponse(['status' => 'ok']))
            ->name('health')
            ->meta(['api' => true]);
    });
};
