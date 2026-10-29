<?php

declare(strict_types=1);

use App\Engine\Http\Cookie;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Localization\LocaleResolver;
use App\Engine\Localization\Localization;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\Template\TemplateManager;

/**
 * A module with its own translations, and nothing in this file says so: the
 * lang/ directory next to it is the declaration.
 */
return static function (ModuleContext $module): void {
    $module->name('Billing')->version('1.0.0');

    $module->routes(static function (RouteCollector $routes): void {
        $routes->get('/invoice', static fn(Request $request, TemplateManager $templates): Response => (new Response(
            $templates->render('@Billing/invoice', ['user' => (string) $request->query('user', 'Some User')]),
        ))->withContentType('text/html'))->name('billing.invoice');

        $routes->get('/receipt', static fn(TemplateManager $templates): Response => (new Response(
            $templates->render('@Billing/receipt', ['user' => '<b>Ann</b>']),
        ))->withContentType('text/html'))->name('billing.receipt');

        $routes->get('/untranslated', static fn(): Response => new Response('plain'))->name('billing.untranslated');

        // The language switch, as the localization reference shows it.
        $routes->get('/language/{locale}', static function (string $locale, Localization $localization): Response {
            if (!in_array($locale, $localization->available(), true)) {
                return new Response('unknown language', 404);
            }

            return (new RedirectResponse('/invoice'))->withCookie(
                new Cookie(LocaleResolver::COOKIE, $locale, expires: time() + 31_536_000),
            );
        })->name('billing.language');
    });
};
