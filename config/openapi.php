<?php

declare(strict_types=1);

/*
 * The API's description (OpenAPI 3.1) and its reference page. Both need a
 * login that may hold an API token: an administrator or reseller signed in to
 * the Admin panel, or a request with a bearer token.
 *
 *   /admin/api/v1/openapi.json   the description
 *   /admin/api/v1/docs           Swagger UI over it
 *
 * `php laika openapi:export` prints the same document, so it can be committed
 * and diffed (design/openapi.json is kept this way).
 */
return [
    'title' => 'LPanel API',
    'version' => '1',
    'description' => <<<'TXT'
Everything the Admin panel does for accounts, sites, mail, DNS, databases, backups, security and the server itself.

**Authentication.** Send `Authorization: Bearer <token>`; create a token under API tokens in the Admin panel. A token acts as its owner: what an administrator or reseller may not do in the panel, the API refuses too, and a reseller sees only their own accounts and packages.

**Answers.** A success is JSON `{"data": ...}` (a list also carries `"meta"`). A change that takes time answers `202` with the run, job or order to ask after. A refusal is `{"error": "<code>", "message": "...", "fields": {...}}` with status 404, 409 (not now, or in use), 422 (the input), 502 (the server could not do it) or 503 (its agent is not answering); `401`, `403` and `429` come from the framework in its own error shape.

**Limits.** Each route has its own rate limit per token; `429` says when to try again.
TXT,
    'route' => '/admin/api/v1/openapi.json',
    'docs' => '/admin/api/v1/docs',
    'meta' => ['can' => 'api.tokens'],
    // Only config decides (no runtime override file on a server).
    'runtime' => false,
];
