<?php

declare(strict_types=1);

namespace App\Engine\Core;

use App\Engine\Asset\AssetServer;
use App\Engine\Auth\AccessRegistry;
use App\Engine\Cli\ConsoleKernel;
use App\Engine\Config\Config;
use App\Engine\Container\Container;
use App\Engine\Error\ErrorHandler;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Module\ModuleManager;
use App\Engine\Observability\TraceKind;
use App\Engine\Observability\Tracer;
use App\Engine\OpenApi\OpenApiRoutes;
use App\Engine\Routing\Router;
use App\Engine\Security\RequestLimits;
use App\Engine\Support\Path;
use App\Engine\Template\ThemeRegistry;

/**
 * The application.
 *
 * boot() brings the modules up. run() hands off to the kernel for whichever
 * context we are in and returns a process exit code.
 *
 * This is the only class in the framework that touches the outside world:
 * superglobals go in here and output comes out here, so that everything else
 * stays a function from values to values.
 */
final class Application
{
    public const VERSION = '3.1.3';

    private bool $booted = false;

    public function __construct(
        private readonly Container $container,
        private readonly ExecutionContext $context,
        private readonly string $basePath,
    ) {}

    public function container(): Container
    {
        return $this->container;
    }

    public function context(): ExecutionContext
    {
        return $this->context;
    }

    public function config(): Config
    {
        return $this->container->get(Config::class);
    }

    public function basePath(string $relative = ''): string
    {
        return $relative === '' ? $this->basePath : Path::join($this->basePath, $relative);
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    /** Discover, load, register and boot every module. Idempotent. */
    public function boot(): self
    {
        if ($this->booted) {
            return $this;
        }

        $this->booted = true;

        $this->container->get(ModuleManager::class)->run();

        // After the modules, whose ids a theme may not take, and before
        // app.booted, so a listener there already sees every theme.
        $this->container->get(ThemeRegistry::class)->load();

        // openapi.route and openapi.docs, after the modules too, so the
        // collision check sees every route. See OpenApiRoutes.
        OpenApiRoutes::register($this->container->get(Router::class), $this->config(), $this->container->get(AccessRegistry::class));

        $hooks = $this->container->get(HookEngine::class);
        $hooks->do('app.booted', $this);
        $hooks->do('app.ready', $this);

        return $this;
    }

    /** Boot as much as this context needs, then run its kernel. Returns the exit code. */
    public function run(): int
    {
        return $this->context->isCli() ? $this->runConsole() : $this->runHttp();
    }

    /**
     * Bring up as much of the application as this request needs.
     *
     * For almost every request that is everything. For a request under /assets/
     * it is discovery alone: the specification's "index.php -> Asset Manager"
     * path, and its "/assets/... should not initialize billing", both of which
     * mean no module.php runs, no service is bound and no onBoot callback fires
     * to deliver a stylesheet. See ModuleManager::prepareAssets().
     *
     * The decision is made on the raw path, before any filter has run, because
     * the filters that could change it belong to modules that have not loaded.
     * A module that rewrites router.path into /assets/ still works -- the
     * kernel answers assets after routing's filters too -- it just pays for the
     * full boot it needed to do the rewriting.
     *
     * What an asset request consequently does not see: app.booted, app.ready,
     * and any module's listener. The engine's own listeners -- the request size
     * limit, the security headers -- are attached by the bootstrap and still run.
     */
    private function prepareFor(Request $request): void
    {
        if ($this->booted) {
            return;
        }

        if ($this->container->get(AssetServer::class)->handles($request->path())) {
            $this->container->get(ModuleManager::class)->prepareAssets();

            // A theme's assets are published by registering it, so the asset
            // path needs the themes as much as it needs the modules' folders.
            $this->container->get(ThemeRegistry::class)->load();

            return;
        }

        $this->boot();
    }

    private function runHttp(): int
    {
        // resolved(), not has(): the question is whether something already
        // supplied a Request, not whether the container could build one. It
        // cannot -- Request has a private constructor and its named
        // constructors are the only supported way in.
        $request = $this->container->resolved(Request::class)
            ? $this->container->get(Request::class)
            : Request::fromGlobals(
                $this->configuredBasePath(),
                $this->trustedProxies(),
                // The body is read no further than the limit; RequestLimits
                // answers 413 for the rest. See Request::readBody().
                $this->config()->int('security.max_request_bytes', RequestLimits::DEFAULT_BYTES),
            );

        $response = $this->serve($request);

        // HEAD must carry the same headers as the GET it mirrors, and no body.
        $response->send(omitBody: $request->isMethod('HEAD'));

        $this->container->get(HookEngine::class)->do('response.sent', $response, $request);

        $this->terminate(0);

        return 0;
    }

    private function runConsole(): int
    {
        $tracer = $this->container->get(Tracer::class);

        // Booting inside the trace, so that module timing belongs to the
        // command that paid for it.
        $status = $tracer->within($tracer->begin(TraceKind::Console), function (): int {
            $this->boot();

            return $this->container->get(ConsoleKernel::class)->handle($this->context);
        });

        $this->terminate($status);

        return $status;
    }

    /**
     * Finish up after the response has been written.
     *
     * fastcgi_finish_request() is called first where it exists, so that
     * anything listening on app.terminating runs after the client already has
     * its response rather than delaying it. For a backend application doing
     * post-response bookkeeping, that is the difference between a fast endpoint
     * and a slow one.
     */
    public function terminate(int $status): void
    {
        if (\function_exists('fastcgi_finish_request')) {
            \fastcgi_finish_request();
        }

        $this->container->get(HookEngine::class)->do('app.terminating', $status);
    }

    /**
     * The response the kernel produced, without sending it.
     *
     * Boots exactly as run() would for the same request, so an asset request
     * handled here loads no module either -- a test that proves otherwise is
     * proving something about production.
     */
    public function handle(Request $request): Response
    {
        return $this->serve($request);
    }

    /**
     * Boot as much as the request needs and run the kernel, inside the
     * request's trace.
     *
     * The trace covers booting as well as handling, so that module timing and
     * everything logged while modules load belong to the request that paid for
     * it. It ends when the kernel returns: sending the bytes and response.sent
     * happen after, and are the web server's time rather than the application's.
     */
    private function serve(Request $request): Response
    {
        $tracer = $this->container->get(Tracer::class);

        return $tracer->within($tracer->beginRequest($request), function () use ($request): Response {
            $this->prepareFor($request);

            $this->container->instance(Request::class, $request);
            $this->container->get(Router::class)->setBasePath($request->basePath());

            // So that a fatal between here and the last byte still knows
            // whether a browser or a program is waiting. PHP's shutdown handler
            // takes no arguments, so the request has to be left somewhere it
            // can find it.
            $this->container->get(ErrorHandler::class)->serving($request);

            return $this->container->get(HttpKernel::class)->handle($request);
        });
    }

    private function configuredBasePath(): ?string
    {
        /** @var mixed $configured */
        $configured = $this->config()->get('http.base_path');

        return \is_string($configured) ? $configured : null;
    }

    /** @return list<string> */
    private function trustedProxies(): array
    {
        /** @var mixed $proxies */
        $proxies = $this->config()->get('http.trusted_proxies', []);

        if (!\is_array($proxies)) {
            return [];
        }

        return \array_values(\array_filter($proxies, \is_string(...)));
    }
}
