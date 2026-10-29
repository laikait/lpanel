<?php

declare(strict_types=1);

use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\Security\Csrf;

/**
 * A form, written the way the pages-and-forms guide says to write one: the
 * handler asks Csrf for the token and puts it in the page. Nothing here sets a
 * cookie; the framework does that on the way out.
 */
return static function (ModuleContext $module): void {
    $module->name('Guestbook')->version('1.0.0');

    $module->routes(static function (RouteCollector $routes): void {
        $routes->get('/guestbook', static fn(Request $request, Csrf $csrf): Response => (new Response(
            '<form method="post"><input type="hidden" name="_token" value="' . $csrf->token($request) . '"></form>',
        ))->withContentType('text/html'))->name('guestbook.show');

        $routes->post('/guestbook', static fn(): Response => new Response('signed'))->name('guestbook.sign');
    });
};
