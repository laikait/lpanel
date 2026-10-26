<?php

declare(strict_types=1);

namespace App\Engine\Core;

use App\Engine\Asset\AssetServer;
use App\Engine\Dispatch\Dispatcher;
use App\Engine\Error\ErrorHandler;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\HttpException;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\MCP\Transport\HttpTransport;
use App\Engine\Routing\MatchStatus;
use App\Engine\Routing\Router;

/**
 * Request in, response out.
 *
 * This is the one kernel. A browser request and a REST request are the same
 * thing arriving with different Accept headers; nothing here branches on which
 * it is, and there is no separate API framework.
 *
 * handle() is pure: it never echoes, never exits, never reads a superglobal and
 * never sends a header. That is what makes the whole request lifecycle testable
 * without a web server, and it is why every feature test in this project can
 * assert on a Response object rather than on captured output.
 *
 * Lifecycle extension points, in order:
 *
 *   request.instance    filter  the Request itself
 *   request.received    hook
 *   router.path         filter  the path about to be matched
 *   asset.response      filter  an asset response, when the path was one
 *   ... a 503, while `php laika down` (Core\Maintenance) ...
 *   ... the MCP endpoint, when the path is it (MCP\Transport\HttpTransport) ...
 *   route.match         filter  the RouteMatch
 *   ... dispatch (see Dispatcher) ...
 *   request.failed      hook    on any Throwable
 *   error.response      filter  the error Response
 *   response.instance   filter  the final Response, always last
 */
final class HttpKernel
{
    public function __construct(
        private readonly Router $router,
        private readonly Dispatcher $dispatcher,
        private readonly AssetServer $assets,
        private readonly HookEngine $hooks,
        private readonly FilterEngine $filters,
        private readonly ErrorHandler $errors,
        private readonly HttpTransport $mcp,
        private readonly Maintenance $maintenance,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            /** @var Request $request */
            $request = $this->filters->apply('request.instance', $request);

            $this->hooks->do('request.received', $request);

            /** @var string $path */
            $path = $this->filters->apply('router.path', $request->path(), $request);

            // Assets are answered before routing, which is what the
            // specification's "index.php -> Asset Manager" means. It is not a
            // catch-all route: a route that swallowed /assets/... would have to
            // match any depth of path, and the router's trie is deliberately
            // built without that. It is also a cheap prefix test, so a normal
            // request pays one str_starts_with for it.
            if ($this->assets->handles($path)) {
                /** @var Response $served */
                $served = $this->filters->apply('response.instance', $this->assets->serve($request, $path), $request);

                return $served;
            }

            // Maintenance mode after assets -- the 503 page keeps its
            // stylesheet -- and before anything that runs the application.
            // While up this is one is_file(). Down, it throws a 503, answers
            // the bypass link, or lets an allowed visitor through.
            $bypass = $this->maintenance->intercept($request);

            if ($bypass !== null) {
                $response = $bypass;
            } elseif ($this->mcp->handles($path)) {
                // MCP is not a route either: one exact path, answered by the
                // protocol's own rules rather than by route meta and dispatch.
                // Off unless configured, and then one string comparison.
                $response = $this->mcp->handle($request);
            } else {
                $match = $this->filters->apply(
                    'route.match',
                    $this->router->match($request->method(), $path),
                    $request,
                );

                $response = match ($match->status) {
                    MatchStatus::Matched => $this->dispatcher->dispatch($request, $match),
                    MatchStatus::MethodNotAllowed => throw HttpException::methodNotAllowed($match->allowedMethods),
                    MatchStatus::NotFound => throw HttpException::notFound($path),
                };
            }
        } catch (\Throwable $e) {
            $this->hooks->do('request.failed', $e, $request);

            /** @var Response $response */
            $response = $this->filters->apply(
                'error.response',
                $this->errors->toResponse($e, $request),
                $e,
                $request,
            );
        }

        /** @var Response $response */
        $response = $this->filters->apply('response.instance', $response, $request);

        return $response;
    }
}
