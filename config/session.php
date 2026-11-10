<?php

declare(strict_types=1);

/*
 * One session cookie per interface. Browsers keep cookies by host, not by
 * port, so the Admin panel (:7443) and the User panel (:7444) on the same
 * hostname would otherwise share one session, and signing in to one would
 * sign the browser out of the other. "Log in as" an account owner needs the
 * administrator's session to survive.
 *
 * Worked out from the request path on every request, so this file must not be
 * frozen with config:cache.
 */
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$area = preg_match('#/(admin|panel)(/|$)#', $path, $m) === 1 ? $m[1] : 'admin';

return [
    'cookie' => [
        'name' => $area === 'panel' ? 'lpanel_user' : 'lpanel_admin',
    ],
];
