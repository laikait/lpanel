<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Auth\AuthGuard;
use App\Engine\Auth\AuthManager;
use App\Engine\Auth\Providers\EmptyProvider;
use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Engine\Core\Application;
use App\Engine\Core\Maintenance;
use App\Engine\Network\Cidr;
use App\Engine\OpenApi\OpenApiRoutes;
use App\Engine\Routing\Router;
use App\Engine\Security\Counters\MemoryStore;
use App\Engine\Security\Csrf;
use App\Engine\Security\Guard;
use App\Engine\Security\RateLimiter;
use App\Engine\Security\RequestLimits;
use App\Engine\Security\SecurityHeaders;
use App\Engine\Security\Signer;
use App\Engine\Security\Webhooks;
use App\Engine\Session\SessionManager;
use App\Engine\Session\Stores\ArrayStore as SessionArrayStore;
use App\Engine\Support\Path;

/**
 * Audit what this deployment actually has switched on.
 *
 * The question it answers is the one that is expensive to answer any other way:
 * a security setting is invisible when it is working and invisible when it is
 * not, so "is HSTS on", "is there a key", "is anything web-readable that should
 * not be" get checked once during setup and never again. This puts them on one
 * screen, reports what the running process really resolved to rather than what
 * a config file says, and exits non-zero when something is wrong -- so it can
 * be a deployment step rather than a thing somebody remembers to run.
 *
 * Three severities, and the distinction is the useful part:
 *
 *   fail   wrong now, in this environment. Exits 1.
 *   warn   a real weakness, deliberately allowed. Exits 0.
 *   ok     checked and fine.
 *
 * Debug mode in production is a fail. A missing APP_KEY is a warn, because an
 * application without one still works and still refuses cross-site requests --
 * it just does so with a weaker guarantee, which is a decision somebody is
 * allowed to have made.
 */
final class SecurityCheckCommand
{
    public const FAIL = 'fail';

    public const WARN = 'warn';

    public const OK = 'ok';

    /** The document root: everything in it can be requested by URL. */
    public const PUBLIC_DIRECTORY = 'public';

    public function __construct(
        private readonly Application $application,
        private readonly Config $config,
        private readonly Signer $signer,
        private readonly Csrf $csrf,
        private readonly RateLimiter $limiter,
        private readonly RequestLimits $limits,
        private readonly SecurityHeaders $headers,
        private readonly Router $router,
        private readonly SessionManager $sessions,
        private readonly AuthManager $auth,
        private readonly Maintenance $maintenance,
        private readonly Webhooks $webhooks,
    ) {}

    public function __invoke(Output $output, bool $verbose = false): int
    {
        $production = $this->config->string('app.env', 'production') === 'production';

        $findings = [
            ...$this->checkMaintenance(),
            ...$this->checkDebug($production),
            ...$this->checkKey($production),
            ...$this->checkCsrf(),
            ...$this->checkLimits(),
            ...$this->checkProxies(),
            ...$this->checkSessions($production),
            ...$this->checkAccess(),
            ...$this->checkHeaders($production),
            ...$this->checkMail($production),
            ...$this->checkCommands($production),
            ...$this->checkMcp(),
            ...$this->checkOpenApi($production),
            ...$this->checkWebhooks(),
            ...$this->checkWebServer(),
            ...$this->checkExtensions(),
        ];

        $output->heading('Security');
        $output->pairs([
            'Environment' => $this->config->string('app.env', 'production') ?? 'production',
            'Signing' => $this->signer->isConfigured() ? 'APP_KEY is set' : 'no APP_KEY (tokens unsigned)',
            'CSRF' => $this->config->bool('security.csrf.enabled', true) ? 'on' : 'off',
            'Counters' => $this->limiter->store()->describe(),
            'Body limit' => $this->limits->describe(),
            'Sessions' => $this->sessions->describe(),
            'Users' => $this->auth->provider()->describe(),
        ]);

        $output->line();

        $failed = 0;
        $warned = 0;

        foreach ($findings as [$severity, $title, $detail]) {
            if ($severity === self::OK && !$verbose) {
                continue;
            }

            match ($severity) {
                self::FAIL => $output->error('  FAIL  ' . $title),
                self::WARN => $output->warning('  WARN  ' . $title),
                default => $output->line('  ok    ' . $title),
            };

            if ($detail !== '' && $severity !== self::OK) {
                $output->line('        ' . $detail);
            }

            $failed += $severity === self::FAIL ? 1 : 0;
            $warned += $severity === self::WARN ? 1 : 0;
        }

        $output->line();

        if ($failed > 0) {
            $output->error(\sprintf('%d problem(s), %d warning(s).', $failed, $warned));

            return 1;
        }

        if ($warned > 0) {
            $output->warning(\sprintf('%d warning(s). Nothing is wrong; something could be stronger.', $warned));

            return 0;
        }

        $output->success('Nothing to report.');

        return 0;
    }

    /** @return list<array{string, string, string}> */
    private function checkDebug(bool $production): array
    {
        $debug = $this->config->bool('app.debug', false);

        if ($debug && $production) {
            return [[
                self::FAIL,
                'Debug mode is on in production.',
                'Stack traces, file paths and configuration reach the browser. Set APP_DEBUG=false.',
            ]];
        }

        return [[self::OK, $debug ? 'Debug mode is on (not production).' : 'Debug mode is off.', '']];
    }

    /**
     * Not a weakness, but the one thing worth seeing first: a deployment
     * that ran `down` and never `up` is a site that answers 503 to everyone.
     *
     * @return list<array{string, string, string}>
     */
    private function checkMaintenance(): array
    {
        $state = $this->maintenance->state();

        if ($state === null) {
            return [[self::OK, 'The application is up.', '']];
        }

        return [[
            self::WARN,
            \sprintf('The application is down for maintenance%s.', $state['since'] > 0 ? ' since ' . \gmdate('Y-m-d H:i', $state['since']) . ' UTC' : ''),
            'Every web request gets 503. php laika up brings it back.',
        ]];
    }

    /**
     * A failure in production, a warning anywhere else.
     *
     * Without a key nothing breaks, which is exactly the problem: three
     * protections quietly stop protecting. CSRF falls back to plain
     * double-submit, so a sibling subdomain that can set a cookie can forge a
     * request; cached values and queued jobs are no longer sealed, so whoever
     * can write the cache or queue store gets their bytes unserialize()d; and
     * Encrypter refuses to run. None of that is visible until it is used.
     *
     * @return list<array{string, string, string}>
     */
    private function checkKey(bool $production): array
    {
        if (!$this->signer->isConfigured()) {
            return [[
                $production ? self::FAIL : self::WARN,
                'No APP_KEY: CSRF tokens are not signed, cache and queue entries are not sealed, nothing can be encrypted.',
                'Everything keeps working, which is why this is easy to miss. A sibling subdomain can plant a '
                . 'matching CSRF cookie and field; anybody able to write the cache or queue store gets what they '
                . 'wrote unserialized; Encrypter refuses to run. Generate one: php laika security:key',
            ]];
        }

        $findings = [[self::OK, 'APP_KEY is set, so tokens are signed and Encrypter can run.', '']];
        $previous = \array_filter(\array_map('trim', \explode(',', $this->config->string('security.previous_keys') ?? '')));

        if ($previous !== []) {
            $findings[] = [
                self::WARN,
                \sprintf('%d retired key(s) in APP_PREVIOUS_KEYS are still accepted for decrypting.', \count($previous)),
                'They exist for rotation. Once what they encrypted has been decrypted and encrypted again, '
                . 'remove them: a leaked old key keeps working for as long as it is listed.',
            ];
        }

        return $findings;
    }

    /** @return list<array{string, string, string}> */
    private function checkCsrf(): array
    {
        if (!$this->config->bool('security.csrf.enabled', true)) {
            return [[
                self::FAIL,
                'CSRF checking is switched off application-wide.',
                'Every cookie-authenticated form in this application can be submitted by any site. '
                . 'Turn it off per route with meta([\'csrf\' => false]) instead.',
            ]];
        }

        // A route that opted out is a decision, not a fault. Listing them is
        // the point: this is the audit somebody would otherwise do by reading
        // every module.php.
        $exempt = [];

        foreach ($this->router->routes() as $route) {
            if ($route->metaValue(Guard::CSRF_META) === false && $this->csrf->protects($route->method())) {
                $exempt[] = $route->method() . ' ' . $route->path();
            }
        }

        $findings = [[self::OK, 'CSRF is checked on every unsafe method by default.', '']];

        if ($exempt !== []) {
            $findings[] = [
                self::OK,
                \sprintf('%d route(s) opt out of CSRF: %s', \count($exempt), \implode(', ', $exempt)),
                '',
            ];
        }

        return $findings;
    }

    /** @return list<array{string, string, string}> */
    private function checkLimits(): array
    {
        $findings = [];

        if ($this->limiter->store() instanceof MemoryStore) {
            $findings[] = [
                self::FAIL,
                'Rate-limit counts are held in memory.',
                'A web request is a process that ends, so every count is forgotten before the next '
                . 'request arrives. This is not a lenient limit; it is no limit. Set '
                . 'security.counters to "file".',
            ];
        } else {
            $findings[] = [self::OK, 'Rate-limit counts are shared: ' . $this->limiter->store()->describe(), ''];
        }

        $limited = 0;

        foreach ($this->router->routes() as $route) {
            $limited += \is_string($route->metaValue(Guard::RATE_LIMIT_META)) ? 1 : 0;
        }

        $findings[] = $limited === 0
            ? [
                self::WARN,
                'No route declares a rate limit.',
                'Nothing is throttled. Add meta([\'rate_limit\' => \'60/1m\']) to the routes that '
                . 'cost something to call -- login, search, anything that writes.',
            ]
            : [self::OK, \sprintf('%d route(s) declare a rate limit.', $limited), ''];

        $findings[] = [self::OK, 'Request bodies are capped at ' . $this->limits->describe() . '.', ''];

        $findings[] = $this->limits->trustedHosts() === []
            ? [
                self::WARN,
                'security.trusted_hosts is empty, so any Host header is accepted.',
                'A client can then choose the host in every link or email built from the request. '
                . 'List the names this application serves: [\'example.com\', \'*.example.com\'].',
            ]
            : [self::OK, 'Only these hosts are served: ' . \implode(', ', $this->limits->trustedHosts()) . '.', ''];

        return $findings;
    }

    /**
     * The HTTP MCP endpoint against DNS rebinding.
     *
     * Its Origin check is only as good as what it compares with. With APP_URL
     * that is the application's real origin; without it, the request's own
     * Host -- which a rebinding attacker chooses, and makes agree with Origin.
     * trusted_hosts refuses that Host before the endpoint is reached. With
     * neither, a web page can talk to an MCP server on this machine.
     *
     * @return list<array{string, string, string}>
     */
    private function checkMcp(): array
    {
        if (!$this->config->bool('mcp.enabled', true) || !$this->config->bool('mcp.transports.http', false)) {
            return [[self::OK, 'The HTTP MCP endpoint is off.', '']];
        }

        $appUrl = $this->config->string('app.url') ?? '';

        if ($appUrl === '' && $this->limits->trustedHosts() === []) {
            return [[
                self::FAIL,
                'The HTTP MCP endpoint is on with neither APP_URL nor security.trusted_hosts set.',
                'A web page can then reach it through DNS rebinding: its Origin and Host both name the '
                . 'attacker, and agree. Set APP_URL (Origin is then checked against it), or trusted_hosts.',
            ]];
        }

        return [[self::OK, 'The HTTP MCP endpoint checks Origin against ' . ($appUrl !== '' ? 'APP_URL.' : 'the trusted hosts.'), '']];
    }

    /**
     * The API description over HTTP.
     *
     * It is a map of every documented endpoint, its parameters and what it
     * accepts -- useful to a client, and as useful to somebody looking for the
     * one endpoint with a weak check. Serving it is a decision about who may
     * read it, so in production it has to say: openapi.meta with auth or can.
     *
     * @return list<array{string, string, string}>
     */
    private function checkOpenApi(bool $production): array
    {
        $path = OpenApiRoutes::path($this->config->string('openapi.route'));

        if ($path === null) {
            return [[self::OK, 'The API description is not served over HTTP.', '']];
        }

        $docs = OpenApiRoutes::path($this->config->string('openapi.docs'));
        $where = $path . ($docs !== null ? ' (and its page at ' . $docs . ')' : '');

        if (OpenApiRoutes::isGuarded($this->config->array('openapi.meta'))) {
            return [[self::OK, \sprintf('The API description at %s requires a login.', $where), '']];
        }

        return [[
            $production ? self::FAIL : self::WARN,
            \sprintf('openapi.route serves the API description at %s to anyone.', $where),
            'Run `php laika openapi:auth --enable` (or set openapi.meta to ["auth" => true]), or turn it off with '
            . '`php laika openapi:docs --disable` and use `php laika openapi:export` instead.',
        ]];
    }

    /**
     * Webhooks need a cache that remembers a delivery past its request.
     * Bootstrap gives them a file store of their own when the application's
     * cache cannot, so this fails only for a Webhooks wired some other way --
     * where receive() would refuse every delivery.
     *
     * @return list<array{string, string, string}>
     */
    private function checkWebhooks(): array
    {
        $sources = $this->config->get('security.webhooks');

        if (!\is_array($sources) || $sources === []) {
            return [[self::OK, 'No webhooks are configured.', '']];
        }

        if (!$this->webhooks->remembersAcrossRequests()) {
            return [[
                self::FAIL,
                'Webhooks are configured, but the cache forgets a delivery when its request ends.',
                'Every delivery would look new, so a captured one could be replayed; receive() refuses instead. '
                . 'Set CACHE_STORE=file (or database).',
            ]];
        }

        return [[self::OK, 'Webhook deliveries are remembered, so a replay is a duplicate.', '']];
    }

    /**
     * http.trusted_proxies decides whose X-Forwarded-For is believed, and so
     * which address every rate limit, audit line and country lookup is about.
     *
     * An entry that cannot be read is skipped at request time rather than
     * failing every request, which makes this the one place a typo is seen.
     *
     * @return list<array{string, string, string}>
     */
    private function checkProxies(): array
    {
        /** @var mixed $configured */
        $configured = $this->config->get('http.trusted_proxies', []);
        $entries = \is_array($configured) ? $configured : [$configured];

        if ($entries === []) {
            return [[self::OK, 'No proxy is trusted, so X-Forwarded-For is ignored.', '']];
        }

        $unreadable = [];
        $everyone = [];

        foreach ($entries as $entry) {
            $block = \is_string($entry) ? Cidr::tryParse(\trim($entry)) : null;

            if ($block === null) {
                $unreadable[] = \is_scalar($entry) ? (string) $entry : \get_debug_type($entry);
            } elseif ($block->prefix() === 0) {
                $everyone[] = $block->toString();
            }
        }

        $findings = [];

        if ($unreadable !== []) {
            $findings[] = [
                self::FAIL,
                \sprintf('http.trusted_proxies has entries that are not addresses: %s', \implode(', ', $unreadable)),
                'They are ignored, so requests through those proxies are seen as coming from the proxy. '
                . 'Write each entry as an address or a CIDR block: "10.0.0.5", "10.0.0.0/8", "2400:cb00::/32".',
            ];
        }

        if ($everyone !== []) {
            $findings[] = [
                self::FAIL,
                \sprintf('http.trusted_proxies trusts every address: %s', \implode(', ', $everyone)),
                'Any client can then choose its own address with X-Forwarded-For, and so its own rate-limit '
                . 'bucket and its own country. List only the proxies in front of this application.',
            ];
        }

        if ($findings === []) {
            $findings[] = [self::OK, 'X-Forwarded-For is believed from: ' . \implode(', ', $entries), ''];
        }

        return $findings;
    }

    /**
     * The session cookie is the credential. Everything here is about the cookie
     * rather than about the data behind it.
     *
     * @return list<array{string, string, string}>
     */
    private function checkSessions(bool $production): array
    {
        $findings = [];

        if ($this->sessions->store() instanceof SessionArrayStore) {
            $findings[] = [
                self::FAIL,
                'Sessions are held in memory.',
                'A web request is a process that ends, so nobody would stay logged in past one '
                . 'response. Set session.store to "file", or to "database" if more than one machine '
                . 'serves this application.',
            ];
        } else {
            $findings[] = [self::OK, 'Sessions are stored: ' . $this->sessions->describe(), ''];
        }

        $cookie = $this->sessions->cookie(\str_repeat('0', 64));

        if ($production && !$cookie->secure) {
            $findings[] = [
                self::WARN,
                'The session cookie is not marked Secure.',
                'It follows the request scheme, so it is Secure over HTTPS and not over plain HTTP. '
                . 'On a production site that is reachable both ways, one plain-HTTP request sends the '
                . 'credential in clear. Set session.cookie.secure to true.',
            ];
        } else {
            $findings[] = [self::OK, 'The session cookie is Secure where it can be.', ''];
        }

        $findings[] = $cookie->sameSite === 'None'
            ? [
                self::WARN,
                'The session cookie is SameSite=None.',
                'It will be sent on requests made by any other site, which is what CSRF exists to '
                . 'survive. Only correct for a deliberate cross-site integration.',
            ]
            : [self::OK, 'The session cookie is SameSite=' . $cookie->sameSite . '.', ''];

        if ($this->sessions->absolute() === 0) {
            $findings[] = [
                self::WARN,
                'Sessions have no absolute lifetime.',
                \sprintf(
                    'They expire after %d seconds of inactivity, but a session kept warm by a '
                    . 'background request never expires at all. Set session.absolute to a number of '
                    . 'seconds a login should never outlive.',
                    $this->sessions->idle(),
                ),
            ];
        } else {
            $findings[] = [self::OK, \sprintf('Sessions expire after %d seconds.', $this->sessions->absolute()), ''];
        }

        return $findings;
    }

    /**
     * Who may reach what.
     *
     * The warning here is the compensating control for requiring a login being
     * opt-in. Defaulting every route to private would be safer in the abstract
     * and would be switched off on the first day, because it makes the home
     * page private too -- so instead the framework makes the gap visible.
     *
     * A route that CHANGES something and requires nobody is the shape worth
     * listing: it may be deliberate (a login form, a webhook, a public
     * subscribe box) and it may be the endpoint somebody added in a hurry.
     *
     * @return list<array{string, string, string}>
     */
    private function checkAccess(): array
    {
        $findings = [];
        $unprotected = [];
        $protected = 0;

        foreach ($this->router->routes() as $route) {
            if (AuthGuard::isProtected($route)) {
                ++$protected;

                continue;
            }

            if (!\in_array($route->method(), Csrf::SAFE_METHODS, true)) {
                $unprotected[] = $route->method() . ' ' . $route->path();
            }
        }

        $findings[] = $protected === 0
            ? [
                self::WARN,
                'No route requires a login.',
                'Every endpoint is reachable by anybody. If that is the intention, nothing is '
                . 'wrong; if it is not, add meta([\'auth\' => true]) or meta([\'can\' => ...]).',
            ]
            : [self::OK, \sprintf('%d route(s) require a login or a capability.', $protected), ''];

        if ($unprotected !== []) {
            $findings[] = [
                self::WARN,
                \sprintf('%d route(s) change something and require nobody.', \count($unprotected)),
                \implode(', ', $unprotected) . '. Some of these are meant to be open -- a login '
                . 'form has to be. Check that all of them are.',
            ];
        } else {
            $findings[] = [self::OK, 'Every route that changes something requires somebody.', ''];
        }

        if ($this->auth->provider() instanceof EmptyProvider) {
            $findings[] = [
                self::WARN,
                'No UserProvider is registered.',
                'Nobody can log in, so every protected route answers 401. Bind one in a module: '
                . '$services->singleton(UserProvider::class, YourProvider::class).',
            ];
        } else {
            $findings[] = [self::OK, 'Users come from: ' . $this->auth->provider()->describe(), ''];
        }

        return $findings;
    }

    /**
     * The log transport is the safe default and the wrong one in production:
     * every password reset is written to a log and never arrives.
     *
     * @return list<array{string, string, string}>
     */
    private function checkMail(bool $production): array
    {
        $transport = $this->config->string('mail.transport', 'log') ?? 'log';

        if ($production && \in_array($transport, ['log', 'array'], true)) {
            return [[
                self::WARN,
                \sprintf('mail.transport is "%s", so no email is sent.', $transport),
                'Messages are written to the log (or kept in memory) instead. If this application sends '
                . 'email, set MAIL_TRANSPORT=smtp or sendmail; if it sends none, nothing is wrong.',
            ]];
        }

        return [[self::OK, \sprintf('Mail goes out through %s.', $transport), '']];
    }

    /**
     * Operating-system commands with no allowlist: any executable, from
     * anywhere in the application, including a code path a request can reach.
     * On by default because sendmail, system:cron and system:systemd go through
     * it; listing what this deployment actually runs is what makes it narrow.
     *
     * @return list<array{string, string, string}>
     */
    private function checkCommands(bool $production): array
    {
        if (!$this->config->bool('system.enabled', true)) {
            return [[self::OK, 'Operating-system commands are switched off.', '']];
        }

        if ($this->config->get('system.commands.allowed') !== null || $this->config->get('system.commands.scripts') !== null) {
            return [[self::OK, 'Operating-system commands are limited to an allowlist.', '']];
        }

        return [[
            $production ? self::WARN : self::OK,
            'Operating-system commands may run any executable (system.commands.allowed is null).',
            'List the absolute paths this deployment runs -- /usr/sbin/sendmail, /usr/bin/crontab, '
            . '/usr/bin/systemctl -- in system.commands.allowed, or set system.enabled to false if it '
            . 'runs none. A code path that builds a command from a request then cannot reach anything else.',
        ]];
    }

    /** @return list<array{string, string, string}> */
    private function checkHeaders(bool $production): array
    {
        $headers = $this->headers->describe();
        $findings = [[
            self::OK,
            \sprintf('%d security header(s) on every response: %s', \count($headers), \implode(', ', \array_keys($headers))),
            '',
        ]];

        if (!$this->headers->hasPolicy()) {
            $findings[] = [
                self::WARN,
                'There is no Content-Security-Policy.',
                'It is the single most effective defence against XSS. Either security.headers.csp is set to "" '
                . '(none), or debug is on, which leaves out the default policy. Remove the empty setting, or '
                . 'set a policy naming this application\'s own script and style sources.',
            ];
        }

        if ($production && !\array_key_exists('Strict-Transport-Security', $headers)) {
            $findings[] = [
                self::WARN,
                'HSTS is not enabled.',
                'Without it a first visit over plain HTTP can be intercepted. Set '
                . 'security.headers.hsts_days once HTTPS is certain -- a browser that has seen '
                . 'this header refuses HTTP for the whole period and cannot be told otherwise.',
            ];
        }

        return $findings;
    }

    /**
     * The rule that matters most and is easiest to get wrong.
     *
     * The document root is public/, so what has to hold is that public/ contains
     * nothing but the front controller and assets: a second PHP file there is a
     * second way in that nothing routes, authenticates or rate-limits. What this
     * cannot see is the web server's own configuration -- a DocumentRoot left at
     * the project directory, or AllowOverride None making the forwarding file
     * inert -- which is why the last line says how to check with a request.
     *
     * @return list<array{string, string, string}>
     */
    private function checkWebServer(): array
    {
        $public = Path::join($this->application->basePath(), self::PUBLIC_DIRECTORY);

        if (!\is_file(Path::join($public, 'index.php'))) {
            return [[self::FAIL, 'public/index.php is missing.', 'The document root has no front controller.']];
        }

        $scripts = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($public, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = $file instanceof \SplFileInfo ? \substr(Path::normalize($file->getPathname()), \strlen(Path::normalize($public)) + 1) : '';

            if ($relative !== 'index.php' && \preg_match('/\.(php\d?|phtml|phar)$/i', $relative) === 1) {
                $scripts[] = $relative;
            }
        }

        return [
            $scripts === []
                ? [self::OK, 'public/ holds no PHP but the front controller.', '']
                : [
                    self::FAIL,
                    \sprintf('public/ holds PHP besides index.php: %s', \implode(', ', $scripts)),
                    'The web server executes these directly, outside routing, authentication and rate limits.',
                ],
            [
                self::WARN,
                'This check reads files; it cannot prove the web server serves only public/.',
                'A DocumentRoot left at the project directory, or AllowOverride None, fails silently. Confirm with: '
                . 'curl -i http://your-host/composer.json -- it must not return the file.',
            ],
        ];
    }

    /** @return list<array{string, string, string}> */
    private function checkExtensions(): array
    {
        if (\function_exists('finfo_open')) {
            return [[self::OK, 'fileinfo is available, so uploads are checked against their contents.', '']];
        }

        return [[
            self::WARN,
            'The fileinfo extension is missing.',
            'UploadPolicy can still enforce the extension allowlist, but cannot tell a .jpg that is '
            . 'really a PHP script from one that is really a JPEG.',
        ]];
    }
}
