<?php

declare(strict_types=1);

use App\Engine\Auth\AccessCollector;
use App\Engine\Auth\Identity;
use App\Engine\Auth\Password;
use App\Engine\Auth\UserProvider;
use App\Engine\Cli\CommandCollector;
use App\Engine\Config\Config;
use App\Engine\Container\Container;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\Routing\Router;
use App\Modules\Identity\Auth\DevRootVerifier;
use App\Modules\Identity\Auth\PanelUserProvider;
use App\Modules\Identity\Auth\RootVerifier;
use App\Modules\Identity\Auth\SocketRootVerifier;
use App\Modules\Identity\Commands\DevSeed;
use App\Modules\Identity\Http\LoginPage;
use App\Modules\Identity\Http\PasswordPage;
use App\Modules\Identity\Http\SecurityPage;
use App\Modules\Identity\Http\SessionApi;
use App\Modules\Identity\Http\TokensPage;
use App\Modules\Identity\Http\UsersApi;
use App\Modules\Identity\Http\UsersPage;
use App\Modules\Identity\Tokens\TokenStore;
use App\Modules\Identity\Users\Roles;
use App\Modules\Identity\Users\UserAdmin;
use App\Modules\Identity\Users\UserStore;
use App\Modules\Shared\Ui\Navigation;

/**
 * Who people are and what they may do: panel users and their roles, root's
 * login through lpanel-authd (ADR-0010), the optional second factor, password
 * reset by email, API tokens, and the screens for all of it.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Identity')
        ->version('0.2.0')
        ->description('Panel users, roles, root login via lpanel-authd, two-factor sign-in, password reset, API tokens.')
        ->requires('Audit');

    $module->config(['root' => [
        // "authd" in production. "dev" only where app.env is local or testing.
        'verifier' => 'authd',
        'socket' => '/run/lpanel-authd/authd.sock',
        // For "dev": a hash from `php laika auth:hash`. Never a production password.
        'dev_password_hash' => '',
    ]]);

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(UserStore::class);
        $services->singleton(TokenStore::class);
        $services->singleton(UserAdmin::class);
        $services->singleton(PanelUserProvider::class);
        $services->singleton(UserProvider::class, static fn(Container $c): UserProvider => $c->get(PanelUserProvider::class));

        $services->singleton(RootVerifier::class, static function (Container $c): RootVerifier {
            $config = $c->get(Config::class);
            $kind = $config->string('Identity.root.verifier', 'authd');

            if ($kind === 'dev') {
                $env = $config->string('app.env', 'production');

                if (!\in_array($env, DevRootVerifier::ENVIRONMENTS, true)) {
                    throw new \RuntimeException(\sprintf(
                        'Identity.root.verifier is "dev", which checks root against a hash in config, but app.env is "%s". '
                        . 'It is allowed only in %s. Use "authd" (lpanel-authd) everywhere else.',
                        (string) $env,
                        \implode(' and ', DevRootVerifier::ENVIRONMENTS),
                    ));
                }

                return new DevRootVerifier($c->get(Password::class), (string) $config->string('Identity.root.dev_password_hash', ''));
            }

            if ($kind !== 'authd') {
                throw new \RuntimeException(\sprintf('Identity.root.verifier must be "authd" or "dev", not "%s".', (string) $kind));
            }

            return new SocketRootVerifier((string) $config->string('Identity.root.socket', '/run/lpanel-authd/authd.sock'));
        });
    });

    $module->access(static function (AccessCollector $access): void {
        $access->capability('panel.admin', 'Use the administrator side of the panel.');
        $access->capability('panel.customer', 'Use the customer side of the panel.');
        $access->capability('users.read', 'List panel users.');
        $access->capability('users.manage', 'Create and change panel users (administrators: root only).');

        $access->role(Roles::ROOT, ['*'], [], 'The server\'s root account. Held by root alone, never stored.');
        $access->role(Roles::ADMIN, ['panel.admin', 'users.*', 'audit.read', 'servers.*', 'accounts.*', 'websites.*'], [], 'Runs the panel for root.');
        $access->role(Roles::CUSTOMER, ['panel.customer', 'accounts.own', 'websites.own', 'websites.configure'], [], 'A hosting customer.');
    });

    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('dev:seed', DevSeed::class)
            ->describe('Create sample admin and customer accounts (local and testing only).');
    });

    $module->routes(static function (RouteCollector $routes): void {
        $uid ='[0-9A-HJKMNP-TV-Z]{26}';

        $routes->get('/login', [LoginPage::class, 'show'])->name('login.show');
        $routes->post('/login', [LoginPage::class, 'submit'])->name('login.submit')->meta(['rate_limit' => '10/1m']);
        $routes->get('/login/code', [LoginPage::class, 'showCode'])->name('login.code');
        $routes->post('/login/code', [LoginPage::class, 'submitCode'])->name('login.code.submit')->meta(['rate_limit' => '10/1m']);
        $routes->post('/logout', [LoginPage::class, 'logout'])->name('logout')->meta(['auth' => true]);

        // auth.passwords.route is "password.reset": the link in the email points here.
        $routes->get('/password/forgot', [PasswordPage::class, 'forgot'])->name('password.forgot');
        $routes->post('/password/forgot', [PasswordPage::class, 'sendLink'])->name('password.forgot.submit')->meta(['rate_limit' => '5/1m']);
        $routes->get('/password/reset', [PasswordPage::class, 'show'])->name('password.reset')->meta(['rate_limit' => '20/1m']);
        $routes->post('/password/reset', [PasswordPage::class, 'reset'])->name('password.reset.submit')->meta(['rate_limit' => '10/1m']);

        $routes->get('/account/security', [SecurityPage::class, 'show'])->name('account.security')->meta(['auth' => true]);
        $routes->post('/account/security/password', [SecurityPage::class, 'password'])->name('account.password')->meta(['auth' => true, 'rate_limit' => '5/1m']);
        $routes->post('/account/security/2fa/begin', [SecurityPage::class, 'begin'])->name('account.2fa.begin')->meta(['auth' => true]);
        $routes->post('/account/security/2fa/confirm', [SecurityPage::class, 'confirm'])->name('account.2fa.confirm')->meta(['auth' => true, 'rate_limit' => '10/1m']);
        $routes->post('/account/security/2fa/disable', [SecurityPage::class, 'disable'])->name('account.2fa.disable')->meta(['auth' => true, 'rate_limit' => '10/1m']);

        $routes->get('/account/tokens', [TokensPage::class, 'index'])->name('account.tokens')->meta(['auth' => true]);
        $routes->post('/account/tokens', [TokensPage::class, 'store'])->name('account.tokens.store')->meta(['auth' => true, 'rate_limit' => '10/1m']);
        $routes->post('/account/tokens/{id}/revoke', [TokensPage::class, 'revoke'])->name('account.tokens.revoke')->where('id', $uid)->meta(['auth' => true]);

        $routes->get('/users', [UsersPage::class, 'index'])->name('users.index')->meta(['can' => 'users.read']);
        $routes->get('/users/new', [UsersPage::class, 'create'])->name('users.create')->meta(['can' => 'users.manage']);
        $routes->post('/users', [UsersPage::class, 'store'])->name('users.store')->meta(['can' => 'users.manage']);
        $routes->get('/users/{id}', [UsersPage::class, 'show'])->name('users.show')->where('id', $uid)->meta(['can' => 'users.read']);
        $routes->post('/users/{id}', [UsersPage::class, 'update'])->name('users.update')->where('id', $uid)->meta(['can' => 'users.manage']);
        $routes->post('/users/{id}/status', [UsersPage::class, 'status'])->name('users.status')->where('id', $uid)->meta(['can' => 'users.manage']);
        $routes->post('/users/{id}/password', [UsersPage::class, 'password'])->name('users.password')->where('id', $uid)->meta(['can' => 'users.manage']);
        $routes->post('/users/{id}/reset-link', [UsersPage::class, 'resetLink'])->name('users.reset_link')->where('id', $uid)->meta(['can' => 'users.manage', 'rate_limit' => '5/1m']);
        $routes->post('/users/{id}/two-factor/reset', [UsersPage::class, 'resetTwoFactor'])->name('users.two_factor_reset')->where('id', $uid)->meta(['can' => 'users.manage']);
        $routes->post('/users/{id}/sessions/end', [UsersPage::class, 'endSessions'])->name('users.sessions_end')->where('id', $uid)->meta(['can' => 'users.manage']);

        // Cookie-authenticated, so CSRF stays on (ARCHITECTURE.md §4).
        $routes->group('/api/v1', static function (RouteCollector $routes) use ($uid): void {
            $routes->get('/session', SessionApi::class)->name('session');
            $routes->get('/users', [UsersApi::class, 'index'])->name('users.index')->meta(['can' => 'users.read']);
            $routes->get('/users/{id}', [UsersApi::class, 'show'])->name('users.show')->where('id', $uid)->meta(['can' => 'users.read']);
            $routes->post('/users', [UsersApi::class, 'store'])->name('users.store')->meta(['can' => 'users.manage']);
        }, name: 'api.v1.', meta: ['api' => true]);

        // Bearer tokens only. CSRF off is safe here and only here: with it
        // off the framework ignores the session cookie, so a browser cannot
        // be made to call these as whoever is signed in.
        $routes->group('/api/token/v1', static function (RouteCollector $routes) use ($uid): void {
            $routes->get('/session', SessionApi::class)->name('session');
            $routes->get('/users', [UsersApi::class, 'index'])->name('users.index')->meta(['can' => 'users.read']);
            $routes->get('/users/{id}', [UsersApi::class, 'show'])->name('users.show')->where('id', $uid)->meta(['can' => 'users.read']);
            $routes->post('/users', [UsersApi::class, 'store'])->name('users.store')->meta(['can' => 'users.manage']);
        }, name: 'api.token.v1.', meta: ['api' => true, 'csrf' => false, 'auth' => true, 'rate_limit' => '120/1m']);
    });

    $module->onBoot(static function (
        RootVerifier $root,
        UserStore $users,
        PanelUserProvider $provider,
        HookEngine $hooks,
        FilterEngine $filters,
        Navigation $nav,
        Router $router,
    ): void {
        // Built at boot, so a "dev" verifier outside local/testing stops the
        // application from starting instead of failing at root's first login.
        unset($root);
        $hooks->add('request.received', [$provider, 'onRequest'], 1, 'Identity');
        $hooks->add('auth.login', [$provider, 'rememberEpoch'], 5, 'Identity');
        $hooks->add('auth.login', static fn(Identity $identity) => $users->recordLogin($identity->id), 20, 'Identity');

        // A page that needs a signed-in person sends a guest to sign in,
        // instead of showing a bare 401. APIs keep their 401.
        $filters->add('error.response', static function (Response $response, \Throwable $e, Request $request) use ($router): Response {
            $page = $request->isMethod('GET') && !$request->expectsJson() && !\str_starts_with($request->path(), '/api/');

            return $e instanceof HttpException && $e->status() === 401 && $page
                ? new RedirectResponse($router->url('login.show'), 303)
                : $response;
        }, 10, 'Identity');

        $nav->add('admin', 'users', 'Users', 'users.index', 'users', can: 'users.read', order: 10);
        $nav->add('account', 'security', 'Sign-in security', 'account.security', 'shield', order: 10);
        $nav->add('account', 'tokens', 'API tokens', 'account.tokens', 'key', order: 20);
    });
};
