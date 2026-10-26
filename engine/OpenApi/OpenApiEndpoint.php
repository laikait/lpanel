<?php

declare(strict_types=1);

namespace App\Engine\OpenApi;

use App\Engine\Http\Request;
use App\Engine\Http\Response;

/**
 * GET openapi.route: the description, for a viewer or a client generator.
 *
 * An ordinary route, registered by OpenApiRoutes after the modules, so the
 * meta in openapi.meta -- `auth`, `can`, `rate_limit` -- is enforced by the
 * same guards as everywhere else, and the response gets the same headers.
 *
 * The ETag is a hash of the bytes, so a viewer that polls gets a 304 until a
 * deployment changes a route -- including when a compressing proxy has
 * weakened the tag (Request::matchesEtag()). Private, because a description
 * behind a login must not be kept by a shared cache.
 *
 * A meta mistake is an OpenApiException here, which is a 500: run
 * `php laika openapi:export` in CI so it is found before a deploy.
 */
final class OpenApiEndpoint
{
    public function __invoke(Request $request, OpenApiGenerator $generator): Response
    {
        $body = $generator->json(pretty: false);
        $etag = '"' . \hash('sha256', $body) . '"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'private, no-cache'];

        if ($request->matchesEtag($etag) === true) {
            return new Response('', 304, $headers);
        }

        return (new Response($body, 200, $headers))->withContentType('application/json');
    }
}
