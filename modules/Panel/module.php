<?php

declare(strict_types=1);

use App\Engine\Auth\AccessCollector;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\Template\TemplateHelperCollector;
use App\Modules\Panel\Http\Home;
use App\Modules\Panel\Ui\Format;
use App\Modules\Panel\Ui\Icons;
use App\Modules\Panel\Ui\PanelPage;

/**
 * The two interfaces' shell: layouts, stylesheet, icons, navigation and the
 * dashboards.
 *
 * The Admin panel lives under /admin (root admins, panel admins, resellers) and
 * the User panel under /panel (account owners, sub-users). On a server each has
 * its own port; the panel's nginx sends each port to its prefix.
 *
 * Other modules extend it through two filters:
 *   panel.nav        ($items, $area)            sidebar entries
 *   panel.dashboard  ($cards, $area, Identity)  dashboard cards
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Panel')
        ->version('0.1.0')
        ->description('The Admin panel and User panel shells, navigation and dashboards.');

    $module->requires('Shared', '^0.1');

    $module->config([
        'version' => '0.1.0-dev',
        'brand' => ['name' => 'LPanel'],
    ]);

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(PanelPage::class);
        $services->singleton(Icons::class);
        $services->bind(Home::class);
    });

    $module->templates(static function (TemplateHelperCollector $templates): void {
        $templates->function('icon', [Icons::class, 'svg'], true);
        $templates->filter('bytes', [Format::class, 'bytes']);
        $templates->filter('duration', [Format::class, 'duration']);
        $templates->filter('when', [Format::class, 'when']);
        $templates->function('percent', [Format::class, 'percent']);
    });

    $module->access(static function (AccessCollector $access): void {
        $access->capability('admin.panel', 'Use the Admin panel.');
        $access->capability('user.panel', 'Use the User panel.');
    });

    $module->routes(static function (RouteCollector $routes): void {
        $routes->get('/', [Home::class, 'root'])->name('home');
        $routes->get('/admin', [Home::class, 'admin'])->name('admin.dashboard')->meta(['can' => 'admin.panel']);
        $routes->get('/panel', [Home::class, 'user'])->name('user.dashboard')->meta(['can' => 'user.panel']);
    });

    $module->filter('panel.nav', static function (array $items): array {
        $items[] = ['area' => 'admin', 'section' => '', 'key' => 'dashboard', 'label' => 'Panel.dashboard', 'icon' => 'gauge', 'route' => 'admin.dashboard', 'can' => 'admin.panel', 'order' => 0];
        $items[] = ['area' => 'user', 'section' => '', 'key' => 'dashboard', 'label' => 'Panel.dashboard', 'icon' => 'gauge', 'route' => 'user.dashboard', 'can' => 'user.panel', 'order' => 0];

        return $items;
    });
};
