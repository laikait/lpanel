<?php

declare(strict_types=1);

use App\Engine\Container\ServiceRegistrar;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Tests\Fixtures\Modules\Nav\Data\NavRepository;
use App\Tests\Fixtures\Modules\Nav\Http\NavAdminPages;

/**
 * Navigation menus, by location: header, footer, admin sidebar, user panel --
 * one nav_items table, one repository, told apart by a `location` column.
 * See docs/guides/navigation.md.
 *
 * Deletable on purpose: nothing else requires this module, and nav_items has
 * no foreign key pointing in or out, so removing this folder from an
 * application leaves every other module exactly as it was.
 *
 * A handler passes NavRepository::tree() into its own template data
 * explicitly, the same way it passes any other value: templates never reach
 * into the container on their own (see TemplateView), so there is no global
 * `nav()` Twig function here.
 *
 * The six /nav routes below are an admin UI for nav_items, and they carry no
 * `auth` meta at all -- see NavAdminPages and docs/guides/navigation.md
 * before pointing this at a public host.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Nav')
        ->version('0.1.0')
        ->description('Navigation menus (header, footer, admin sidebar, user panel), by location.');

    $module->requires('Shared');

    $module->config([
        // How long a rendered menu is cached, per location and role set.
        'cache_ttl' => 300,
    ]);

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(NavRepository::class);
        $services->bind(NavAdminPages::class);
    });

    $module->routes(static function (RouteCollector $routes): void {
        // No 'auth' key anywhere in this group, on purpose: this UI is meant
        // to be usable with no login step. CSRF still applies -- it is
        // opt-out, not opt-in -- and every write is rate-limited, the same
        // shape as the shipped /login route (rate-limited, no auth meta).
        $routes->get('/nav', [NavAdminPages::class, 'index'])
            ->name('nav.index')->meta(['rate_limit' => '60/1m']);

        $routes->get('/nav/create', [NavAdminPages::class, 'create'])
            ->name('nav.create')->meta(['rate_limit' => '60/1m']);

        $routes->post('/nav', [NavAdminPages::class, 'store'])
            ->name('nav.store')->meta(['rate_limit' => '20/1m']);

        $routes->get('/nav/{id}/edit', [NavAdminPages::class, 'edit'])
            ->where('id', '\d+')->name('nav.edit')->meta(['rate_limit' => '60/1m']);

        $routes->post('/nav/{id}', [NavAdminPages::class, 'update'])
            ->where('id', '\d+')->name('nav.update')->meta(['rate_limit' => '20/1m']);

        $routes->post('/nav/{id}/delete', [NavAdminPages::class, 'delete'])
            ->where('id', '\d+')->name('nav.delete')->meta(['rate_limit' => '20/1m']);

        $routes->post('/nav/{id}/move', [NavAdminPages::class, 'move'])
            ->where('id', '\d+')->name('nav.move')->meta(['rate_limit' => '20/1m']);
    });
};
