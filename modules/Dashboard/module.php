<?php

declare(strict_types=1);

use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Modules\Dashboard\Http\Home;
use App\Modules\Shared\Ui\Navigation;

/** The front page: where a signed-in person lands, and where a guest is sent to sign in from. */
return static function (ModuleContext $module): void {
    $module
        ->name('Dashboard')
        ->version('0.2.0')
        ->description('The front page for administrators and customers.')
        ->requires('Identity')
        ->requires('Servers')
        ->requires('Operations')
        ->requires('Hosting');

    $module->routes(static function (RouteCollector $routes): void {
        $routes->get('/', Home::class)->name('home');
    });

    $module->onBoot(static function (Navigation $nav): void {
        $nav->add('main', 'dashboard', 'Dashboard', 'home', 'home', order: 0);
    });
};
