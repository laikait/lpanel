<?php

declare(strict_types=1);

namespace App\Modules\Panel\Api;

use App\Engine\Routing\Router;

/**
 * Gives every API route its `openapi` meta from ApiCatalogue, so the engine's
 * generator describes the whole API: paths, methods, summaries, tags, path
 * parameters and their patterns, what a body holds, who may call, and the
 * answers the framework and the panel give.
 *
 * Done once at boot, before the first request is matched (a route's meta
 * cannot change after that). Routes that are not under the API are left alone,
 * and a route the catalogue does not know is left undocumented, which the
 * API test treats as a failure.
 */
final class ApiDocs
{
    public const PREFIX = '/admin/api/v1';

    public function apply(Router $router): int
    {
        $catalogue = ApiCatalogue::all();
        $n = 0;

        foreach ($router->routes() as $route) {
            $name = $route->routeName();

            if ($name === null || !\str_starts_with($route->path(), self::PREFIX) || !isset($catalogue[$name])) {
                continue;
            }

            [$summary, $body, $tag] = $catalogue[$name];
            $write = $route->method() !== 'GET';
            $responses = [
                200 => 'Success. The body is {"data": ...}; a change that runs in the background answers 202 with the run, job or order to ask after.',
                503 => 'The server\'s agent is not answering. {"error": "agent_unavailable", "message": "..."}',
            ];

            if ($write) {
                $responses[409] = 'Not now: something conflicting runs, or the thing is in use. {"error": "<code>", "message": "..."}';
                $responses[422] = 'The input was refused. {"error": "validation_failed", "message": "...", "fields": {"name": "..."}}';
                $responses[502] = 'The server tried and could not do it. {"error": "<code>", "message": "..."}';
            }

            if ($route->parameters() !== []) {
                $responses[404] = 'There is no such account, domain or item (or it is not yours to see).';
            }

            $route->meta(['openapi' => [
                'summary' => $summary,
                'description' => $body === '' ? $summary . '.' : $summary . ".\n\nRequest body (JSON): " . $body,
                'tags' => [$tag],
                'operationId' => \str_replace('.', '_', $name),
                'responses' => $responses,
            ]]);
            $n++;
        }

        return $n;
    }
}
