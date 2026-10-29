<?php

declare(strict_types=1);

use App\Engine\Auth\AccessCollector;
use App\Engine\Auth\UserProvider;
use App\Engine\Container\Container;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Data\ArraySource;
use App\Engine\Data\DataSource;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\SqlSource;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\Template\TemplateManager;
use App\Tests\Fixtures\Showcase\Shared\Auth\AccountProvider;
use App\Tests\Fixtures\Showcase\Shared\Auth\SessionEndpoints;
use App\Tests\Fixtures\Showcase\Shared\Data\UserRepository;
use App\Tests\Fixtures\Showcase\Shared\Filters\ApiFilters;
use App\Tests\Fixtures\Showcase\Shared\Filters\ResponseFilters;
use App\Tests\Fixtures\Showcase\Shared\Model\User;

/**
 * The shared module.
 *
 * Shared is for things genuinely used across modules. It is not a dumping
 * ground: a model or service belongs here only when more than one module really
 * needs it, and it registers first so that everything else can rely on it.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Shared')
        ->version('0.1.0')
        ->description('Cross-module services, values and response conventions.');

    $module->config([
        'currency' => 'USD',
        'locale' => 'en_GB',
    ]);

    $module->services(static function (ServiceRegistrar $services): void {
        // Where every repository in the application reads and writes.
        //
        // This is the only place in the application that knows which kind of
        // storage it has. Configure DB_DSN and every repository, query, read
        // model, relation and page above this line runs against a database
        // instead, unchanged -- which is the claim the DataSource interface
        // makes, and the reason it is worth having.
        //
        // The factory is handed the container rather than reaching for one, and
        // it runs on first use, so an application that never reads never opens
        // a connection.
        $services->singleton(DataSource::class, static function (Container $container): DataSource {
            $connections = $container->get(ConnectionManager::class);

            return $connections->isConfigured()
                ? new SqlSource($connections->connection())
                : new ArraySource();
        });

        // A shared repository, available to every module that asks for it.
        // Shared registers first, so a plugin can depend on this at boot.
        $services->singleton(UserRepository::class);

        // What a user IS, for this application. The framework asked for two
        // methods and has no idea what is behind them -- which is what the
        // specification means by authentication being modular rather than
        // embedded in the kernel. Point this at LDAP and nothing in engine/
        // changes, because nothing in engine/ ever knew.
        $services->singleton(UserProvider::class, AccountProvider::class);
        $services->bind(SessionEndpoints::class);
    });

    // Who may do what. Roles are declared here because the shared module is
    // the one entitled to say what a job title means across the application;
    // the capabilities themselves are declared by whichever module enforces
    // them, which for these two is this one.
    $module->access(static function (AccessCollector $access): void {
        $access
            ->capability('user.list', 'See the list of people with accounts.')
            ->capability('user.impersonate', 'Act as another account without their password.');

        // "member" grants nothing yet and is not pointless: it is the name
        // every ordinary account holds, so granting something to everybody
        // later is one line here rather than a migration over every row.
        $access->role('member', description: 'Any account that has logged in.');

        $access->role(
            'administrator',
            ['user.*'],
            ['member'],
            'Everything a member may do, plus the account screens.',
        );
    });

    $module->routes(static function (RouteCollector $routes): void {
        // The front page, so a fresh installation answers "/" with a page
        // rather than a 404. It is a route like any other, owned by a module
        // like any other, because there is no global routes file to put it in.
        //
        // It is meant to be replaced, and replacing it needs nothing from this
        // file: every other module registers after shared, and the router keeps
        // the last route declared for a method and path, so a plugin that
        // declares "/" answers it instead. (It must not also call its route
        // "home" -- names are unique, and that one is taken here.)
        //
        // "home" is handed to the template as the current request addresses the
        // front page, so the link is right under Apache in a subdirectory too.
        $routes->get('/', static fn(Request $request, TemplateManager $templates): Response => (new Response(
            $templates->render('home', ['home' => $request->basePath() . '/']),
        ))->withContentType('text/html'))->name('home');

        // No CSRF exemption and no auth requirement: logging in is the one
        // unsafe request a guest has to be able to make, and it is exactly the
        // request a forged form would want to make on somebody's behalf.
        //
        // The limit is the security phase meeting this one. A password endpoint
        // with no limit is an offline attack conducted online.
        $routes->post('/login', [SessionEndpoints::class, 'login'])
            ->name('auth.login')
            ->meta(['rate_limit' => '5/1m']);

        $routes->post('/logout', [SessionEndpoints::class, 'logout'])
            ->name('auth.logout')
            ->meta(['auth' => true]);

        // 'auth' => true and nothing else: every logged-in account may ask who
        // it is, and no capability is needed to answer that.
        $routes->get('/me', [SessionEndpoints::class, 'me'])
            ->name('auth.me')
            ->meta(['auth' => true]);

        // A capability rather than a role. Routes should never name roles:
        // "administrator" is a decision about people and changes with the
        // organisation, while "may list users" is a fact about the endpoint.
        $routes->get('/users', static function (UserRepository $users): array {
            $data = [];

            foreach ($users->all() as $user) {
                if ($user instanceof User) {
                    $data[] = ['id' => $user->identity(), 'username' => $user->username()];
                }
            }

            return ['data' => $data];
        })->name('users.index')->meta(['can' => 'user.list']);
    });

    $module->onBoot(static function (DataSource $source): void {
        // Demo data, and only for the in-memory source. A real database gets
        // its rows from each module's own migrations -- which this framework
        // does not have yet, so pointing DB_DSN at a database means creating
        // the tables yourself for now.
        if ($source instanceof ArraySource) {
            $source->seed('users', [
                ['id' => 10, 'username' => 'ada', 'email' => 'ada@example.test'],
                ['id' => 11, 'username' => 'grace', 'email' => 'grace@example.test'],
            ]);
        }
    });

    // Priority 100 puts this last, so the header reflects the finished response.
    $module->filter('response.instance', [ResponseFilters::class, 'stampEngine'], priority: 100);

    // API conventions, applied to every route that declares itself an API
    // route. This is the framework's answer to middleware: one filter, reading
    // route metadata, composing with everything else without any route having
    // to know a pipeline exists.
    $module->filter('dispatch.response', [ApiFilters::class, 'stampVersion'], priority: 20);
    $module->filter('dispatch.response', [ApiFilters::class, 'announceDeprecation'], priority: 30);
};
