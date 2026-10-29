<?php

declare(strict_types=1);

use App\Engine\Http\Response;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;

/**
 * An application's own front page.
 *
 * Declares "/" and nothing else, under a name other than "home", which is all
 * it takes to replace the shared module's default page.
 */
return static function (ModuleContext $module): void {
    $module->name('Landing');

    $module->routes(static function (RouteCollector $routes): void {
        $routes->get('/', static fn(): Response => new Response('the landing page'))->name('landing');
    });
};
