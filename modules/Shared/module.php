<?php

declare(strict_types=1);

use App\Engine\Container\Container;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Data\ArraySource;
use App\Engine\Data\DataSource;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\SqlSource;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Modules\Shared\Health\HealthCheck;

/**
 * The shared module.
 *
 * Shared is for things genuinely used across LPanel's modules. It is not a
 * dumping ground: a model or service belongs here only when more than one
 * module really needs it, and it registers first so that everything else can
 * rely on it.
 *
 * Right now it holds two things: where repositories store their data, and the
 * health check. The front page belongs to the module that owns the dashboard.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Shared')
        ->version('0.1.0')
        ->description('/health, and where repositories store their data.');

    // GET /health's thresholds, as Shared.health.* (config/Shared.php to change them).
    $module->config(['health' => [
        // A queue holding more jobs than this is a warning, never a failure.
        'queue_backlog' => 1000,
        // How long a mail or storage check's answer is reused; 0 asks every time.
        'cache_seconds' => 300,
        // The disks the storage check writes to; empty means the default disk.
        'disks' => [],
    ]]);

    $module->services(static function (ServiceRegistrar $services): void {
        // Where every repository in the application reads and writes.
        //
        // This is the only place in the application that knows which kind of
        // storage it has. Configure DB_DSN and every repository, query, read
        // model, relation and page runs against a database instead of memory,
        // unchanged.
        //
        // The factory is handed the container rather than reaching for one, and
        // it runs on first use, so an application that never reads never opens
        // a connection.
        $services->singleton(DataSource::class, static function (Container $container): DataSource {
            $connections = $container->get(ConnectionManager::class);

            return $connections->isConfigured()
                ? new SqlSource($connections->connection())
                : new ArraySource();
        });
    });

    $module->routes(static function (RouteCollector $routes): void {
        // For a load balancer or an uptime monitor: 200 when this instance can
        // serve, 503 when it cannot.
        $routes->get('/health', HealthCheck::class)->name('health')->meta(['api' => true]);
    });
};
