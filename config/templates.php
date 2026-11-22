<?php

declare(strict_types=1);

/*
 * The panels' templates. Each lives in its own folder with everything it
 * shows: layout, pages, error pages, emails and assets.
 *
 *   templates/admin/<name>/   the Admin panel (:7443)
 *   templates/user/<name>/    the User panel (:7444)
 *
 * Which one a panel uses is the Panel module's `template` setting
 * (modules/Panel/module.php); every template in use is listed here.
 */
return [
    'themes' => ['admin/nova', 'user/nova'],
    // Error pages raised before a route matched (an unknown URL, maintenance).
    'error_themes' => ['/admin' => 'admin/nova', '/panel' => 'user/nova'],
];
