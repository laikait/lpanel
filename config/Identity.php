<?php

declare(strict_types=1);

use App\Engine\Config\Env;

/*
 * How root's password is checked (design/adr/0010-root-login-via-authd.md).
 *
 *   LPANEL_ROOT_VERIFIER=authd   production: lpanel-authd over its socket
 *   LPANEL_ROOT_VERIFIER=dev     a developer's machine without PAM; refused
 *                                unless APP_ENV is local or testing
 */
return [
    'root' => [
        'verifier' => Env::string('LPANEL_ROOT_VERIFIER', 'authd'),
        'socket' => Env::string('LPANEL_AUTHD_SOCKET', '/run/lpanel-authd/authd.sock'),
        // For "dev" only: `php laika auth:hash '<a password>'`.
        'dev_password_hash' => Env::string('LPANEL_DEV_ROOT_PASSWORD_HASH', ''),
    ],
];
