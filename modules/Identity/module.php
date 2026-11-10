<?php

declare(strict_types=1);

use App\Engine\Auth\AccessCollector;
use App\Engine\Auth\UserProvider;
use App\Engine\Cli\CommandCollector;
use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Engine\Config\Env;
use App\Engine\Container\Container;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\Scheduler\ScheduleCollector;
use App\Engine\Session\SessionManager;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\Commands\CreateLogin;
use App\Modules\Identity\Commands\DevSeed;
use App\Modules\Identity\Http\LoginAsPages;
use App\Modules\Identity\Http\SecurityPage;
use App\Modules\Identity\Http\SignIn;
use App\Modules\Identity\Http\StaffPages;
use App\Modules\Identity\Http\TokenPages;
use App\Modules\Identity\IdentityListeners;
use App\Modules\Identity\Kind;
use App\Modules\Identity\LoginAs;
use App\Modules\Identity\PanelUsers;
use App\Modules\Identity\Root\AuthdVerifier;
use App\Modules\Identity\Root\DevRootVerifier;
use App\Modules\Identity\Root\RootPasswordVerifier;
use App\Modules\Identity\Root\RootSignIn;
use App\Modules\Identity\SessionUsers;

/**
 * Who people are: every login, signing in and out, two-factor, root's
 * password through authd, and the tiers' roles.
 *
 * Roles are the kinds (Identity\Kind). What each may do is declared here in
 * one place; reseller ACLs and sub-user grants narrow them further (M1).
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Identity')
        ->version('0.1.0')
        ->description('Logins, signing in, two-factor, root via authd, and the tiers\' roles.');

    $module->requires('Panel', '^0.1')->requires('Audit', '^0.1');

    $module->config([
        'root' => [
            // "authd" on a server. "dev" checks LPANEL_DEV_ROOT_PASSWORD and
            // refuses everything unless APP_ENV is "local".
            'verifier' => Env::string('LPANEL_ROOT_VERIFIER', 'authd'),
            'socket' => '/run/lpanel-authd/authd.sock',
        ],
    ]);

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(PanelUsers::class);
        // The framework's provider: PanelUsers plus session epochs and "log in as".
        $services->singleton(UserProvider::class, static fn(Container $c): UserProvider => new SessionUsers(
            $c->get(PanelUsers::class),
            static fn(): SessionManager => $c->get(SessionManager::class),
        ));
        $services->singleton(LoginAs::class);
        $services->bind(LoginAsPages::class);
        $services->singleton(RootPasswordVerifier::class, static function (Container $c): RootPasswordVerifier {
            $config = $c->get(Config::class);

            return $config->get('Identity.root.verifier') === 'dev'
                ? new DevRootVerifier((string) $config->get('app.env'), (string) Env::string('LPANEL_DEV_ROOT_PASSWORD', ''))
                : new AuthdVerifier((string) $config->get('Identity.root.socket'));
        });
        $services->singleton(RootSignIn::class);
        $services->singleton(IdentityListeners::class);
        $services->bind(SignIn::class);
        $services->bind(SecurityPage::class);
        $services->bind(StaffPages::class);
        $services->singleton(ApiTokens::class);
        $services->bind(TokenPages::class);
        $services->bind(CreateLogin::class);
        $services->bind(DevSeed::class);
    });

    $module->access(static function (AccessCollector $access): void {
        $access->capability('account.self', 'Change your own password and two-factor settings.');
        $access->capability('staff.manage', 'Create, disable and reset panel administrator logins.');

        $access->capability('api.tokens', 'Make API tokens that act as this login.');

        // Capabilities named here are declared by the modules that check them
        // (Audit, Agent, Packages, Accounts, Resellers, Subusers). "*.all" sees
        // every reseller's things; without it a login sees only what it owns.
        // Narrowed further by filters on authorization.decision: a reseller's
        // ACL (Resellers), a package's features and a sub-user's grants
        // (Subusers), and each login's own interface (here).
        $hosting = ['accounts.manage', 'accounts.create', 'accounts.modify', 'accounts.suspend', 'accounts.terminate', 'accounts.login_as', 'packages.manage'];
        $access->role('root', ['*']);
        $access->role('admin', [
            'admin.panel', 'account.self', 'api.tokens', 'audit.view', 'audit.own', 'system.view',
            ...$hosting, 'packages.all', 'accounts.all', 'resellers.manage', 'resellers.login_as',
        ]);
        $access->role('reseller', ['admin.panel', 'account.self', 'api.tokens', 'audit.own', ...$hosting]);
        $access->role('user', ['user.panel', 'account.self', 'audit.own', 'feature.*', 'subusers.manage']);
        $access->role('subuser', ['user.panel', 'account.self', 'audit.own', 'feature.*']);
    });

    $module->routes(static function (RouteCollector $routes): void {
        foreach (['admin' => '/admin', 'user' => '/panel'] as $area => $prefix) {
            $routes->get($prefix . '/login', [SignIn::class, 'show'])->name($area . '.login');
            $routes->post($prefix . '/login', [SignIn::class, 'submit'])->meta(['rate_limit' => '20/1m']);
            $routes->get($prefix . '/login/code', [SignIn::class, 'showCode'])->name($area . '.login.code');
            $routes->post($prefix . '/login/code', [SignIn::class, 'submitCode'])->meta(['rate_limit' => '20/1m']);
            $routes->post($prefix . '/logout', [SignIn::class, 'logout'])->name($area . '.logout');

            $routes->group($prefix . '/security', static function (RouteCollector $routes): void {
                $routes->get('', [SecurityPage::class, 'show'])->name('');
                $routes->post('/password', [SecurityPage::class, 'changePassword'])->name('.password');
                $routes->post('/two-factor/begin', [SecurityPage::class, 'beginTwoFactor'])->name('.2fa.begin');
                $routes->post('/two-factor/confirm', [SecurityPage::class, 'confirmTwoFactor'])->name('.2fa.confirm')->meta(['rate_limit' => '10/1m']);
                $routes->post('/two-factor/cancel', [SecurityPage::class, 'cancelTwoFactor'])->name('.2fa.cancel');
                $routes->post('/two-factor/disable', [SecurityPage::class, 'disableTwoFactor'])->name('.2fa.disable')->meta(['rate_limit' => '10/1m']);
                $routes->post('/two-factor/codes', [SecurityPage::class, 'newRecoveryCodes'])->name('.2fa.codes')->meta(['rate_limit' => '10/1m']);
                $routes->post('/sessions', [SecurityPage::class, 'signOutElsewhere'])->name('.sessions');
            }, name: $area . '.security', meta: ['can' => $area === 'admin' ? 'admin.panel' : 'user.panel', 'no_impersonation' => true]);
        }

        // "Log in as" (LoginAs). Into the Admin panel the session changes
        // login; into the User panel a one-minute ticket crosses ports.
        $routes->post('/admin/login-as/return', [LoginAsPages::class, 'stop'])->name('admin.login_as.return')->meta(['can' => 'admin.panel']);
        $routes->post('/admin/login-as/{id}', [LoginAsPages::class, 'start'])->name('admin.login_as')->where('id', '\d+')->meta(['can' => 'admin.panel']);
        $routes->get('/panel/login-as', [LoginAsPages::class, 'redeem'])->name('user.login_as')->meta(['rate_limit' => '20/1m']);
        $routes->post('/panel/login-as/end', [LoginAsPages::class, 'end'])->name('user.login_as.end')->meta(['can' => 'user.panel']);

        $routes->group('/admin/staff', static function (RouteCollector $routes): void {
            $routes->get('', [StaffPages::class, 'index'])->name('');
            $routes->post('', [StaffPages::class, 'create'])->name('.create');
            $routes->post('/{id}/active', [StaffPages::class, 'setActive'])->name('.active')->where('id', '\d+');
            $routes->post('/{id}/password', [StaffPages::class, 'resetPassword'])->name('.password')->where('id', '\d+');
        }, name: 'admin.staff', meta: ['can' => 'staff.manage']);

        $routes->group('/admin/tokens', static function (RouteCollector $routes): void {
            $routes->get('', [TokenPages::class, 'index'])->name('');
            $routes->post('', [TokenPages::class, 'create'])->name('.create');
            $routes->post('/{id}/revoke', [TokenPages::class, 'revoke'])->name('.revoke')->where('id', '\d+');
        }, name: 'admin.tokens', meta: ['can' => 'api.tokens', 'no_impersonation' => true]);
    });

    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('identity:create', CreateLogin::class)
            ->describe('Create a panel login; the password is read from standard input.')
            ->argument('kind', 'admin, reseller, user or subuser.')
            ->argument('username', 'The login name.')
            ->option('name', 'The person\'s name.')
            ->option('email', 'Their email address.')
            ->option('parent', 'The owning reseller (for a user) or account owner (for a sub-user).');
        $commands->add('identity:token', static function (Output $output, PanelUsers $users, ApiTokens $tokens, AuditLog $audit, string $username, string $name, ?string $days = null): int {
            $row = $users->find($username);

            if ($row === null || Kind::tryFrom((string) $row['kind'])?->area() !== 'admin') {
                $output->error('Name an Admin panel login: root, an administrator or a reseller.');

                return 2;
            }

            $made = $tokens->issue((string) $row['id'], $name, $days === null ? null : max(1, (int) $days));
            $audit->record('api_token.create', null, null, $name, AuditLog::OK, ['id' => $made['id'], 'login' => $row['username'], 'via' => 'cli']);
            $output->line($made['token']);

            return 0;
        })->describe('Make an API token for an Admin panel login and print it (shown only this once).')
            ->argument('username', 'The login the token acts as.')
            ->argument('name', 'What the token is for, e.g. "billing system".')
            ->option('days', 'Expire after this many days (default: never).');
        $commands->add('identity:prune-tickets', static function (Output $output, LoginAs $loginAs): int {
            $output->line(sprintf('%d unused "log in as" tickets removed.', $loginAs->prune()));

            return 0;
        })->describe('Remove "log in as" tickets that expired unused.');
        $commands->add('dev:seed', DevSeed::class)
            ->describe('Create one login of each kind, on a developer\'s machine only.');
    });

    $module->schedules(static function (ScheduleCollector $schedules): void {
        $schedules->command('identity:prune-tickets')->hourly();
    });

    $module->filter('panel.nav', static function (array $items): array {
        $items[] = ['area' => 'admin', 'section' => 'Panel.section_people', 'key' => 'staff', 'label' => 'Identity.staff', 'icon' => 'users', 'route' => 'admin.staff', 'can' => 'staff.manage', 'order' => 10];
        $items[] = ['area' => 'admin', 'section' => 'Panel.section_security', 'key' => 'security', 'label' => 'Identity.security_short', 'icon' => 'shield', 'route' => 'admin.security', 'can' => 'account.self', 'order' => 80];
        $items[] = ['area' => 'admin', 'section' => 'Panel.section_security', 'key' => 'tokens', 'label' => 'Identity.tokens', 'icon' => 'code', 'route' => 'admin.tokens', 'can' => 'api.tokens', 'order' => 85];
        $items[] = ['area' => 'user', 'section' => 'Panel.section_security', 'key' => 'security', 'label' => 'Identity.security_short', 'icon' => 'shield', 'route' => 'user.security', 'can' => 'account.self', 'order' => 80];

        return $items;
    });

    $module->onBoot(static function (IdentityListeners $listeners, PanelUsers $users, HookEngine $hooks, FilterEngine $filters): void {
        $hooks->add('dispatch.before', [$listeners, 'guardArea'], 6, 'Identity', 1);
        $filters->add('authorization.decision', [$listeners, 'ownInterfaceOnly'], 10, 'Identity', 3);
        $filters->add('error.response', [$listeners, 'signInInstead'], 10, 'Identity', 3);
        $filters->add('panel.dashboard', [$listeners, 'dashboard'], 20, 'Identity', 3);
        $hooks->add('auth.rehash', [$users, 'storeHash'], 10, 'Identity');
        $hooks->add('auth.login', [$listeners, 'signedIn'], 10, 'Identity', 1);
    });
};
