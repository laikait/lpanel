<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\AccessRegistry;
use App\Engine\Auth\AuthException;
use App\Engine\Auth\AuthGuard;
use App\Engine\Auth\AuthManager;
use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Auth\UserProvider;
use App\Engine\Cli\CommandDispatcher;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\ConsoleKernel;
use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Engine\Error\ErrorHandler;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Route;
use App\Engine\Routing\Router;
use App\Engine\Security\Csrf;
use App\Engine\Security\Signer;
use App\Engine\Session\SessionManager;
use App\Tests\Fixtures\Showcase\Shared\Auth\SessionEndpoints;
use App\Tests\Support\TestCase;

/**
 * Logging in, being refused, and being allowed -- through the real application.
 *
 * The demo's shared module is the "Authentication / User" module the
 * specification asks for: it owns the User, the provider, the login routes and
 * the roles. Nothing in engine/ knows any of that, which is what these tests
 * are really checking.
 *
 * The demo password is "secret" for both accounts, ada is an administrator and
 * grace is not.
 */
final class AuthSliceTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function app(array $config = []): Application
    {
        $config['security']['key'] ??= Signer::generate();
        // Cost 4 everywhere: the suite logs in a good many times and has no
        // interest in proving that bcrypt is slow.
        $config['auth']['password']['options'] ??= ['cost' => 4];

        return $this->application($config)->boot();
    }

    // ---- wiring ------------------------------------------------------------

    public function test_the_auth_pieces_are_injectable(): void
    {
        $container = $this->app()->container();

        self::assertInstanceOf(AuthManager::class, $container->get(AuthManager::class));
        self::assertInstanceOf(Authorizer::class, $container->get(Authorizer::class));
        self::assertInstanceOf(AccessRegistry::class, $container->get(AccessRegistry::class));
    }

    /** A module bound it; the framework never had one of its own. */
    public function test_the_user_provider_comes_from_a_module(): void
    {
        $provider = $this->app()->container()->get(UserProvider::class);

        self::assertStringContainsString('shared module', $provider->describe());
    }

    public function test_the_access_model_is_declared_by_modules(): void
    {
        $access = $this->app()->container()->get(AccessRegistry::class);

        self::assertTrue($access->hasPermission('user.list'));
        self::assertSame('Shared', $access->permissions()['user.list']->module);
        self::assertSame(['user.*'], $access->grantsFor(['administrator']));
    }

    // ---- being nobody ------------------------------------------------------

    public function test_a_public_route_does_not_ask_who_you_are(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/customers.json'));

        self::assertSame(200, $response->status());
        // Nothing identified anybody, so nothing acquired a session either.
        foreach ($response->cookies() as $cookie) {
            self::assertNotSame(SessionManager::DEFAULT_COOKIE, $cookie->name);
        }
    }

    public function test_a_route_that_needs_a_login_answers_401(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/me'));

        self::assertSame(401, $response->status());
        self::assertSame('Bearer', $response->header('WWW-Authenticate'));
    }

    /**
     * 403, not 401: the framework knows who this is and the answer is still no.
     *
     * The difference decides whether a client should retry with credentials.
     */
    public function test_a_known_account_without_the_capability_gets_403(): void
    {
        $app = $this->app();
        [$session] = $this->login($app, 'grace');

        $response = $app->handle(Request::create('GET', '/users', [
            'cookies' => [SessionManager::DEFAULT_COOKIE => $session],
        ]));

        self::assertSame(403, $response->status());
    }

    public function test_an_account_with_the_capability_gets_through(): void
    {
        $app = $this->app();
        [$session] = $this->login($app, 'ada');

        $response = $app->handle(Request::create('GET', '/users', [
            'cookies' => [SessionManager::DEFAULT_COOKIE => $session],
        ]));

        self::assertSame(200, $response->status());
        self::assertNotEmpty($this->data($response)['data']);
    }

    // ---- logging in --------------------------------------------------------

    public function test_the_right_credentials_log_in(): void
    {
        $app = $this->app();
        $response = $this->post($app, '/login', ['username' => 'ada', 'password' => 'secret']);

        self::assertSame(200, $response->status());

        $body = $this->data($response);

        self::assertTrue($body['authenticated']);
        self::assertSame('ada', $body['name']);
        self::assertSame(['administrator'], $body['roles']);
        self::assertContains('user.list', $body['capabilities']);
    }

    public function test_the_wrong_password_is_refused_without_saying_why(): void
    {
        // Debug off, because the claim is about what a client is told. In debug
        // the body carries a stack trace whose line numbers differ between the
        // two calls, which is a fact about the test rather than about the
        // framework -- and a debug build is not the one facing the internet.
        $app = $this->app(['app' => ['debug' => false]]);
        $wrong = $this->post($app, '/login', ['username' => 'ada', 'password' => 'not it']);
        $unknown = $this->post($app, '/login', ['username' => 'nobody', 'password' => 'not it']);

        self::assertSame(401, $wrong->status());
        self::assertSame(401, $unknown->status());
        self::assertSame($wrong->body(), $unknown->body(), 'the two must be indistinguishable');
    }

    public function test_a_login_without_a_password_is_a_bad_request(): void
    {
        self::assertSame(400, $this->post($this->app(), '/login', ['username' => 'ada'])->status());
    }

    /** The reason Session::regenerate() exists, seen end to end. */
    public function test_logging_in_replaces_the_session_id(): void
    {
        $app = $this->app();
        $planted = $app->container()->get(SessionManager::class);
        $planted->onRequest(Request::create('GET', '/'));
        $before = $planted->session()->id();
        $planted->save();

        $response = $this->post($app, '/login', ['username' => 'ada', 'password' => 'secret'], $before);

        self::assertSame(200, $response->status());
        self::assertNotSame($before, $this->sessionCookie($response), 'the planted id must not survive the login');
    }

    /** And the CSRF token goes with it. See Security\Guard::onSessionRegenerated(). */
    public function test_logging_in_also_rotates_the_csrf_token(): void
    {
        $app = $this->app();
        $token = $app->container()->get(Csrf::class)->token(Request::create('GET', '/'));

        $response = $this->post($app, '/login', ['username' => 'ada', 'password' => 'secret'], null, $token);

        $issued = null;

        foreach ($response->cookies() as $cookie) {
            if ($cookie->name === Csrf::COOKIE) {
                $issued = $cookie->value;
            }
        }

        self::assertNotNull($issued);
        self::assertNotSame($token, $issued);
    }

    public function test_the_login_stays_logged_in(): void
    {
        $app = $this->app();
        [$session] = $this->login($app, 'ada');

        $response = $app->handle(Request::create('GET', '/me', [
            'cookies' => [SessionManager::DEFAULT_COOKIE => $session],
        ]));

        self::assertSame(200, $response->status());
        self::assertSame('ada', $this->data($response)['name']);
    }

    public function test_logging_out_ends_it(): void
    {
        $app = $this->app();
        [$session, $token] = $this->login($app, 'ada');

        $out = $app->handle(Request::create('POST', '/logout', [
            'cookies' => [SessionManager::DEFAULT_COOKIE => $session, Csrf::COOKIE => $token],
            'body' => [Csrf::FIELD => $token],
        ]));

        self::assertSame(200, $out->status());

        $after = $app->handle(Request::create('GET', '/me', [
            'cookies' => [SessionManager::DEFAULT_COOKIE => $this->sessionCookie($out) ?? $session],
        ]));

        self::assertSame(401, $after->status());
    }

    /**
     * A route that opted out of CSRF does not take the session cookie as a
     * login. Otherwise any site could post to it as whoever is logged in --
     * the check is off, and the browser attaches the cookie on its own. A
     * bearer token still works, because a browser never sends one unasked.
     */
    public function test_a_csrf_exempt_route_ignores_the_session_cookie(): void
    {
        $app = $this->app();
        $app->container()->get(Router::class)->add(
            (new Route('POST', '/api/v1/whoami', [SessionEndpoints::class, 'me']))->meta(['auth' => true, 'csrf' => false]),
        );
        [$session] = $this->login($app, 'ada');

        $bySession = $app->handle(Request::create('POST', '/api/v1/whoami', [
            'cookies' => [SessionManager::DEFAULT_COOKIE => $session],
        ]));
        $byToken = $app->handle(Request::create('POST', '/api/v1/whoami', [
            'headers' => ['Authorization' => 'Bearer ada-token-do-not-use'],
        ]));
        $elsewhere = $app->handle(Request::create('GET', '/me', [
            'cookies' => [SessionManager::DEFAULT_COOKIE => $session],
        ]));

        self::assertSame(401, $bySession->status(), 'a cross-site POST would have been logged in');
        self::assertSame(200, $byToken->status());
        self::assertSame('ada', $this->data($byToken)['name']);
        self::assertSame(200, $elsewhere->status(), 'the session itself was harmed');
    }

    public function test_with_csrf_off_everywhere_the_session_still_counts(): void
    {
        $app = $this->app(['security' => ['csrf' => ['enabled' => false]]]);
        $app->container()->get(Router::class)->add(
            (new Route('POST', '/api/v1/whoami', [SessionEndpoints::class, 'me']))->meta(['auth' => true, 'csrf' => false]),
        );
        [$session] = $this->login($app, 'ada');

        $response = $app->handle(Request::create('POST', '/api/v1/whoami', [
            'cookies' => [SessionManager::DEFAULT_COOKIE => $session],
        ]));

        self::assertSame(200, $response->status());
    }

    // ---- tokens ------------------------------------------------------------

    public function test_a_bearer_token_authenticates_without_a_session(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/me', [
            'headers' => ['Authorization' => 'Bearer ada-token-do-not-use'],
        ]));

        self::assertSame(200, $response->status());
        self::assertSame('ada', $this->data($response)['name']);
    }

    public function test_a_wrong_token_is_not_a_login(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/me', [
            'headers' => ['Authorization' => 'Bearer not-a-real-token'],
        ]));

        self::assertSame(401, $response->status());
    }

    public function test_a_token_request_needs_no_cookie_and_gets_no_session(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/me', [
            'headers' => ['Authorization' => 'Bearer ada-token-do-not-use'],
        ]));

        foreach ($response->cookies() as $cookie) {
            self::assertNotSame(SessionManager::DEFAULT_COOKIE, $cookie->name);
        }
    }

    // ---- the login form is still a form ------------------------------------

    /**
     * Logging in is an unsafe request from a guest, so it is CSRF-checked like
     * any other -- it is exactly the request a forged form would want to make.
     */
    public function test_the_login_route_is_csrf_checked(): void
    {
        $response = $this->app()->handle(Request::create('POST', '/login', [
            'body' => ['username' => 'ada', 'password' => 'secret'],
        ]));

        self::assertSame(403, $response->status());
    }

    /** A password endpoint with no limit is an offline attack conducted online. */
    public function test_the_login_route_is_rate_limited(): void
    {
        $app = $this->app();
        $statuses = [];

        for ($i = 0; $i < 7; ++$i) {
            $statuses[] = $this->post($app, '/login', ['username' => 'ada', 'password' => 'wrong'])->status();
        }

        self::assertSame(401, $statuses[4], 'the fifth attempt is still answered');
        self::assertSame(429, $statuses[5], 'the sixth is not');
    }

    // ---- the identity a handler sees ---------------------------------------

    public function test_a_handler_asking_for_an_identity_gets_this_requests_one(): void
    {
        $app = $this->app();
        $manager = $app->container()->get(AuthManager::class);

        $app->handle(Request::create('GET', '/me', [
            'headers' => ['Authorization' => 'Bearer ada-token-do-not-use'],
        ]));

        self::assertSame($manager->identity()->id, $app->container()->get(Identity::class)->id);
    }

    // ---- the check that happens at boot ------------------------------------

    /**
     * A route asking for a capability nobody declared stops the application.
     *
     * The failure it replaces is a quiet one: such a route refuses everybody,
     * including the administrator with every role, and looks like a routing
     * fault or a broken login. Here it is a boot failure that names the route
     * and the misspelling, found by whoever wrote it.
     */
    public function test_a_route_requiring_an_undeclared_capability_refuses_to_boot(): void
    {
        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('user.lst');

        $this->application([
            'modules' => ['paths' => [self::SHOWCASE . '/Shared', 'tests/Fixtures/Modules/BadAccess/Plugins']],
        ])->boot();
    }

    /** And the real application passes it, which is the other half. */
    public function test_every_route_in_this_application_asks_for_something_real(): void
    {
        $app = $this->app();
        $access = $app->container()->get(AccessRegistry::class);

        foreach ($app->container()->get(Router::class)->routes() as $route) {
            foreach (AuthGuard::capabilitiesFor($route) as $capability) {
                self::assertTrue(
                    $access->hasPermission($capability),
                    \sprintf('%s %s asks for undeclared "%s"', $route->method(), $route->path(), $capability),
                );
            }
        }
    }

    // ---- the console -------------------------------------------------------

    public function test_auth_access_prints_the_whole_model(): void
    {
        [$status, $output] = $this->console($this->app(), 'auth:access');

        self::assertSame(ConsoleKernel::SUCCESS, $status);
        self::assertStringContainsString('user.list', $output);
        self::assertStringContainsString('administrator', $output);
        // Flattened, which is what a check actually sees.
        self::assertStringContainsString('GET /users', $output);
    }

    public function test_auth_hash_prints_a_usable_hash(): void
    {
        [$status, $output] = $this->console($this->app(), 'auth:hash', 'hunter2', '--bare');

        self::assertSame(ConsoleKernel::SUCCESS, $status);
        self::assertTrue(\password_verify('hunter2', \trim($output)));
    }

    public function test_security_check_notices_routes_that_require_nobody(): void
    {
        [, $output] = $this->console($this->app(), 'security:check');

        self::assertStringContainsString('change something and require nobody', $output);
        self::assertStringContainsString('POST /login', $output);
    }

    public function test_route_list_says_what_each_route_requires(): void
    {
        [, $output] = $this->console($this->app(), 'route:list');

        self::assertStringContainsString('ACCESS', $output);
        self::assertStringContainsString('user.list', $output);
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * Log in the way a browser does, and hand back its two cookies.
     *
     * @return array{string, string} the session id and the CSRF token
     */
    private function login(Application $app, string $username): array
    {
        $token = $app->container()->get(Csrf::class)->token(Request::create('GET', '/'));

        $response = $this->post($app, '/login', [
            'username' => $username,
            'password' => 'secret',
        ], null, $token);

        self::assertSame(200, $response->status(), 'the fixture login must succeed');

        $session = $this->sessionCookie($response);

        self::assertNotNull($session);

        return [$session, $this->csrfCookie($response) ?? $token];
    }

    /** @param array<string, string> $body */
    private function post(
        Application $app,
        string $path,
        array $body,
        ?string $session = null,
        ?string $token = null,
    ): Response {
        $token ??= $app->container()->get(Csrf::class)->token(Request::create('GET', '/'));
        $cookies = [Csrf::COOKIE => $token];

        if ($session !== null) {
            $cookies[SessionManager::DEFAULT_COOKIE] = $session;
        }

        return $app->handle(Request::create('POST', $path, [
            'cookies' => $cookies,
            'body' => $body + [Csrf::FIELD => $token],
        ]));
    }

    private function sessionCookie(Response $response): ?string
    {
        foreach ($response->cookies() as $cookie) {
            if ($cookie->name === SessionManager::DEFAULT_COOKIE) {
                return $cookie->value;
            }
        }

        return null;
    }

    private function csrfCookie(Response $response): ?string
    {
        foreach ($response->cookies() as $cookie) {
            if ($cookie->name === Csrf::COOKIE) {
                return $cookie->value;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        /** @var mixed $decoded */
        $decoded = \json_decode($response->body(), true, 16, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return array{int, string} */
    private function console(Application $app, string ...$arguments): array
    {
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        $status = (new ConsoleKernel(
            $app->container()->get(CommandRegistry::class),
            $app->container()->get(CommandDispatcher::class),
            $app->container()->get(HookEngine::class),
            $app->container()->get(ErrorHandler::class),
            new Output($stream),
        ))->handle(ExecutionContext::cli(\array_values(['laika', ...$arguments])));

        \rewind($stream);
        $output = (string) \stream_get_contents($stream);
        \fclose($stream);

        return [$status, $output];
    }
}
