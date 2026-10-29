<?php

declare(strict_types=1);

use App\Engine\Container\Container;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Data\ArraySource;
use App\Engine\Data\DataSource;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\SqlSource;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\Template\TemplateManager;
use App\Engine\Storage\Storage;
use App\Modules\Shared\Auth\SocialSignIn;
use App\Modules\Shared\Health\HealthCheck;
use App\Modules\Shared\Storage\FileDownload;

/**
 * The shared module.
 *
 * Shared is for things genuinely used across modules. It is not a dumping
 * ground: a model or service belongs here only when more than one module really
 * needs it, and it registers first so that everything else can rely on it.
 *
 * A fresh installation ships it with five things: the front page, a health
 * check, the route behind a local disk's signed file links, the routes for
 * signing in with Google and the rest once one is configured, and the choice
 * of where repositories store their data.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Shared')
        ->version('0.1.0')
        ->description('The front page, /health, signed file links, social sign-in, and where repositories store their data.');

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
        // unchanged -- which is the claim the DataSource interface makes, and
        // the reason it is worth having.
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
        // The front page, so a fresh installation answers "/" with a page
        // rather than a 404. It is a route like any other, owned by a module
        // like any other, because there is no global routes file to put it in.
        //
        // It is meant to be replaced, and replacing it needs nothing from this
        // file: every other module registers after shared, and the router keeps
        // the last route declared for a method and path, so a plugin that
        // declares "/" answers it instead. (It must not also call its route
        // "home" -- names are unique, and that one is taken here.)
        //
        // "home" is handed to the template as the current request addresses the
        // front page, so the link is right under Apache in a subdirectory too.
        $routes->get('/', static fn (Request $request, TemplateManager $templates): Response => (new Response(
            $templates->render('home', ['home' => $request->basePath() . '/']),
        ))->withContentType('text/html'))->name('home');

        // For a load balancer or an uptime monitor: 200 when this instance can
        // serve, 503 when it cannot. Replaceable the same way as "/".
        $routes->get('/health', HealthCheck::class)->name('health')->meta(['api' => true]);

        // Where a local disk's temporaryUrl() points: a signed link, refused
        // with 403 when changed and 410 once expired, before the handler runs.
        $routes->get('/files/{disk}', FileDownload::class)->name(Storage::ROUTE)->meta(['signed' => true]);

        // Sign in with the providers under auth.social.providers; a 404 for
        // any other. The callback takes POST too, because Apple posts it from
        // its own site -- without a CSRF token, so the check is off there and
        // the sign-in's own state check is what protects it.
        $routes->get('/auth/{provider}', [SocialSignIn::class, 'redirect'])->name('social.redirect')->meta(['rate_limit' => '30/1m']);
        $routes->get('/auth/{provider}/callback', [SocialSignIn::class, 'callback'])->name('social.callback')->meta(['rate_limit' => '30/1m']);
        $routes->post('/auth/{provider}/callback', [SocialSignIn::class, 'callback'])->meta(['rate_limit' => '30/1m', 'csrf' => false]);
    });
};
