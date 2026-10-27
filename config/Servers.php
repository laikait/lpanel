<?php

declare(strict_types=1);

use App\Engine\Config\Env;

/*
 * Pairing servers with LPA agents (design/AGENT_PROTOCOL.md).
 *
 *   LPANEL_AGENT_URL        https://panel.example.com:8443 -- panel Nginx, which agents reach
 *   LPANEL_TLS_CERTIFICATE  that Nginx's certificate file; the pin agents check is worked out from it
 *   LPANEL_AGENT_PIN        or the pin itself, sha256/...
 *   LPANEL_OPERATION_KEY    the operation-signing key file (php laika servers:keygen);
 *                           /etc/lpanel/keys/operations.key in production
 */
return [
    'agent_url' => Env::string('LPANEL_AGENT_URL', '') ?? '',
    'panel_pin' => Env::string('LPANEL_AGENT_PIN', '') ?? '',
    'tls_certificate' => Env::string('LPANEL_TLS_CERTIFICATE', '') ?? '',
    'operation_key' => Env::string('LPANEL_OPERATION_KEY', '') ?: \dirname(__DIR__) . '/system/Keys/operations.key',
];
