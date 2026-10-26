<?php

declare(strict_types=1);

use App\Engine\Auth\AccessCollector;
use App\Engine\Auth\Identity;
use App\Engine\Auth\Password;
use App\Engine\Auth\UserProvider;
use App\Engine\Config\Config;
use App\Engine\Container\Container;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Hook\HookEngine;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Modules\Identity\Auth\DevRootVerifier;
use App\Modules\Identity\Auth\PanelUserProvider;
use App\Modules\Identity\Auth\RootVerifier;
use App\Modules\Identity\Auth\SocketRootVerifier;
use App\Modules\Identity\Http\LoginPage;
use App\Modules\Identity\Http\SecurityPage;
use App\Modules\Identity\Http\SessionApi;
use App\Modules\Identity\Http\UsersApi;
use App\Modules\Identity\Users\Roles;
use App\Modules\Identity\Users\UserStore;

/**
 * Who people are and what they may do: panel users and their roles, root's
 * login through lpanel-authd (ADR-0010), the optional second factor, and the
 * login pages.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Identity')
        ->version('0.1.0')
        ->description('Panel users, roles, root login via lpanel-authd, two-factor sign-in.')
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
        $access->capability('users.manage', 'Create panel users (administrators: root only).');

        $access->role(Roles::ROOT, ['*'], [], 'The server\'s root account. Held by root alone, never stored.');
        $access->role(Roles::ADMIN, ['panel.admin', 'users.*', 'audit.read'], [], 'Runs the panel for root.');
        $access->role(Roles::CUSTOMER, ['panel.customer'], [], 'A hosting customer.');
    });

    $module->routes(static function (RouteCollector $routes): void {
        $routes->get('/login', [LoginPage::class, 'show'])->name('login.show');
        $routes->post('/login', [LoginPage::class, 'submit'])->name('login.submit')->meta(['rate_limit' => '10/1m']);
        $routes->get('/login/code', [LoginPage::class, 'showCode'])->name('login.code');
        $routes->post('/login/code', [LoginPage::class, 'submitCode'])->name('login.code.submit')->meta(['rate_limit' => '10/1m']);
        $routes->post('/logout', [LoginPage::class, 'logout'])->name('logout')->meta(['auth' => true]);

        $routes->get('/account/security', [SecurityPage::class, 'show'])->name('account.security')->meta(['auth' => true]);
        $routes->post('/account/security/2fa/begin', [SecurityPage::class, 'begin'])->name('account.2fa.begin')->meta(['auth' => true]);
        $routes->post('/account/security/2fa/confirm', [SecurityPage::class, 'confirm'])->name('account.2fa.confirm')->meta(['auth' => true, 'rate_limit' => '10/1m']);
        $routes->post('/account/security/2fa/disable', [SecurityPage::class, 'disable'])->name('account.2fa.disable')->meta(['auth' => true, 'rate_limit' => '10/1m']);

        // Cookie-authenticated, so CSRF stays on (ARCHITECTURE.md §4). The
        // bearer-token group, /api/token/v1, arrives with API tokens in M2b.
        $routes->group('/api/v1', static function (RouteCollector $routes): void {
            $routes->get('/session', SessionApi::class)->name('session');
            $routes->get('/users', [UsersApi::class, 'index'])->name('users.index')->meta(['can' => 'users.read']);
            $routes->post('/users', [UsersApi::class, 'store'])->name('users.store')->meta(['can' => 'users.manage']);
        }, name: 'api.v1.', meta: ['api' => true]);
    });

    $module->onBoot(static function (RootVerifier $root, UserStore $users, HookEngine $hooks): void {
        // Built at boot, so a "dev" verifier outside local/testing stops the
        // application from starting instead of failing at root's first login.
        unset($root);
        $hooks->add('auth.login', static fn(Identity $identity) => $users->recordLogin($identity->id), 20, 'Identity');
    });
};
