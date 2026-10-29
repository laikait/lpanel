<?php

declare(strict_types=1);

use App\Engine\Cli\CommandCollector;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Data\ArraySource;
use App\Engine\Data\DataSource;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Response;
use App\Engine\Model\Relation;
use App\Engine\Model\RelationManager;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\Routing\Router;
use App\Engine\Scheduler\ScheduleCollector;
use App\Engine\Schema\Field;
use App\Engine\Session\Session;
use App\Tests\Fixtures\Showcase\Plugins\Example\Api\CustomerApi;
use App\Tests\Fixtures\Showcase\Plugins\Example\Api\CustomerPage;
use App\Tests\Fixtures\Showcase\Plugins\Example\Api\ListCustomers;
use App\Tests\Fixtures\Showcase\Plugins\Example\Commands\ShowCustomer;
use App\Tests\Fixtures\Showcase\Plugins\Example\Commands\SyncCustomers;
use App\Tests\Fixtures\Showcase\Plugins\Example\Data\CustomerQuery;
use App\Tests\Fixtures\Showcase\Plugins\Example\Data\CustomerRepository;
use App\Tests\Fixtures\Showcase\Plugins\Example\Filters\CustomerFilters;
use App\Tests\Fixtures\Showcase\Plugins\Example\Hooks\CustomerHooks;
use App\Tests\Fixtures\Showcase\Plugins\Example\Jobs\ReviewCustomers;
use App\Tests\Fixtures\Showcase\Plugins\Example\Model\Customer;
use App\Tests\Fixtures\Showcase\Plugins\Example\Schema\CustomerSchema;
use App\Tests\Fixtures\Showcase\Shared\Model\User;
use App\Tests\Fixtures\Showcase\Shared\Schema\PaginationSchema;

/**
 * A plugin module.
 *
 * This file is the whole contract. There is nothing to extend and nothing to
 * implement: the closure receives a ModuleContext and describes what the module
 * provides. Everything here is RECORDED, not executed -- by the time this
 * returns, nothing has been bound, routed or hooked. The manager replays it all
 * afterwards, by category, in a deterministic module order.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Example Customers')
        ->version('0.1.0')
        ->description('Demonstrates the module contract end to end.');

    // What this module needs from elsewhere, with the versions it was written
    // against. The shared module registers first regardless, so this is not
    // about order: it is about the User model and the pagination contract this
    // module uses (see Api/ and the owner relation below), and about refusing
    // to boot against a shared module whose 1.0 changed them.
    $module->requires('Shared', '^0.1');

    $module->config([
        'page_size' => 25,
    ]);

    $module->services(static function (ServiceRegistrar $services): void {
        // The registrar can write to the container and cannot read from it, so
        // service location during registration is impossible by construction.
        // A repository and a query are different jobs: one enforces the rules
        // of writing a customer, the other reads fast. Both are singletons
        // because both are stateless collaborators over a shared source.
        $services->singleton(CustomerRepository::class);
        $services->singleton(CustomerQuery::class);
        $services->bind(ListCustomers::class);
        $services->bind(CustomerPage::class);
        $services->bind(CustomerApi::class);
        $services->bind(SyncCustomers::class);
        $services->bind(ShowCustomer::class);
    });

    $module->routes(static function (RouteCollector $routes): void {
        // An index of what this module offers. Not at "/": the front page
        // belongs to the application, which the shared module answers.
        $routes->get('/links.json', static fn(Router $router): array => [
            'framework' => 'app-framework',
            'links' => [
                'customers' => $router->url('customers.index'),
                'api.customers' => $router->url('api.v1.customers.index'),
                'api.customer' => $router->url('api.v1.customers.show', ['id' => 1]),
            ],
            // The global asset() helper, used where it is meant to be used: a
            // closure written in module.php, with no constructor to inject an
            // AssetManager into. A class would take one by injection instead --
            // see this module's Api/ handlers, which take everything that way.
            //
            // None of these URLs says where the file is. The plugin's script
            // lives under modules/Plugins/Example/assets/, a directory Apache
            // is configured to refuse, and the URL knows only that it belongs
            // to the plugin called Example.
            'assets' => [
                'app.css' => asset()->core('css/app.css'),
                'app.js' => asset()->core('js/app.js'),
                'plugin.js' => asset()->module('Example', 'js/example.js'),
                'gateway.js' => asset()->module('ExampleGateway', 'js/gateway.js'),
            ],
        ])->name('links');

        // The same customers twice: once as a page, once as JSON. One
        // kernel, one query, two renderings.
        $routes->get('/customers', CustomerPage::class)->name('customers.index');
        $routes->get('/customers.json', ListCustomers::class)->name('customers.json');

        $routes->group('/api/v1', static function (RouteCollector $routes): void {
            // The description is the same declarations the handlers enforce:
            // the pagination schema is what ListCustomers validates the query
            // with, and CustomerSchema what store() and show() use.
            $routes->get('/customers', ListCustomers::class)
                ->name('customers.index')
                ->meta(['openapi' => [
                    'summary' => 'A page of customers',
                    'query' => PaginationSchema::schema(),
                    'responses' => [200 => ['data' => [CustomerSchema::resource()]]],
                ]]);

            $routes->get('/customers/{id}', [CustomerApi::class, 'show'])
                ->where('id', '\d+')
                ->name('customers.show')
                ->meta(['openapi' => [
                    'summary' => 'One customer',
                    'path' => ['id' => Field::int('id')->describedAs('The customer id.')],
                    'responses' => [200 => ['data' => CustomerSchema::resource()], 404 => 'No such customer'],
                ]]);

            $routes->post('/customers', [CustomerApi::class, 'store'])
                ->name('customers.store')
                ->meta(['auth' => false, 'openapi' => [
                    'summary' => 'Register a customer',
                    'body' => CustomerSchema::input(),
                    'responses' => [201 => ['data' => CustomerSchema::resource()]],
                ]]);
            // Versioning is a route prefix plus route metadata. There is no
            // version negotiator and no version resolver, because a URL prefix
            // already answers "which version" unambiguously and a second
            // mechanism would only let the two disagree. What the metadata adds
            // is somewhere for the framework-side conventions to read it from:
            // the shared module turns these into response headers.
        }, name: 'api.v1.', meta: [
            'api' => true,
            'version' => 'v1',
            // Opted out, explicitly, one group at a time.
            //
            // CSRF defends against a browser attaching credentials it holds
            // ambiently -- a cookie -- to a request some other site made. An
            // API authenticated by a bearer token has no such credential: the
            // token has to be put on the request by whoever is making it, and
            // another site cannot do that. So the check would cost every API
            // client a token round-trip and buy nothing.
            //
            // Note what is NOT true: "api" does not imply this. An API that
            // authenticates with a session cookie needs CSRF exactly as much
            // as a form does, which is why the framework makes nobody write
            // this line and makes everybody who wants it write it here.
            'csrf' => false,
            // Enough for a real client, low enough that a script hammering
            // this endpoint is stopped before it becomes somebody's evening.
            'rate_limit' => '60/1m',
        ]);

        // A deprecated version, kept answering while clients move off it. The
        // dates are what RFC 8594 and RFC 9745 put in Deprecation and Sunset
        // headers, so a client learns the endpoint is going away from the
        // response rather than from a changelog it never read.
        $routes->group('/api/v0', static function (RouteCollector $routes): void {
            $routes->get('/customers', ListCustomers::class)->name('customers.index');
        }, name: 'api.v0.', meta: [
            'api' => true,
            'version' => 'v0',
            'deprecated' => '2026-01-01',
            'sunset' => '2027-01-01',
        ]);

        // The session, proven the way everything else here is proven: a route
        // that asks for one and gets this request's.
        //
        // Session is injected into the CLOSURE, which resolves per request.
        // Note what would be wrong: a singleton service taking Session in its
        // constructor. In a long-running worker that service would hold the
        // first request's session for ever, and the bug looks like users seeing
        // each other's data. Ask for it where the request is.
        $routes->get('/visits', static function (Session $session): array {
            $visits = (int) $session->get('visits', 0) + 1;
            $session->set('visits', $visits);

            return [
                'visits' => $visits,
                'session' => $session->existed() ? 'resumed' : 'new',
                // Set by the reset below, readable exactly once after it.
                'status' => $session->get('status'),
            ];
        })->name('visits');

        // POST, and therefore CSRF-checked like any other unsafe route -- this
        // one has not opted out, so it needs the token the framework already
        // handed the browser.
        $routes->post('/visits/reset', static function (Session $session): array {
            $session->invalidate();
            $session->flash('status', 'Counting again from zero.');

            return ['visits' => 0];
        })->name('visits.reset');

        $routes->get('/ping', static fn(): Response => new Response('pong'));
    });

    // Commands are declared exactly the way routes are, and for the same
    // reason: this module owns a capability, and the shell is simply another
    // way in to it. Nothing is scanned; a Commands/ directory is where these
    // classes happen to live, not how they are found.
    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('customer:sync', SyncCustomers::class)
            ->describe('Pull customer records from the upstream system.')
            ->argument('since', 'Only records changed on or after this date.', required: false, default: 'yesterday')
            ->option('limit', 'Stop after this many records.', shortcut: 'l', default: '25')
            ->flag('dry-run', 'Report what would change without writing anything.', shortcut: 'd')
            ->note('--dry-run is the one to reach for first: it reads and prints, and touches nothing.');

        // The same three handler forms a route accepts. This one is
        // [class, method]; the one above is an invokable class; the one below
        // is a closure whose parameters are injected.
        $commands->add('customer:show', [ShowCustomer::class, 'show'])
            ->describe('Print one customer.')
            ->argument('id', 'The customer id.');

        $commands->add('customer:count', static fn(CustomerQuery $customers): string => sprintf(
            '%d customer(s).',
            $customers->total(),
        ))->describe('Count the customers, without reading a row of them.');
    });

    // When this module's work runs unattended. Declared here rather than in a
    // machine's crontab for the same reason the routes are declared here and
    // not in a global route file: installing this module brings its schedule
    // with it, removing the module takes it away, and a reviewer can see both.
    $module->schedules(static function (ScheduleCollector $schedules): void {
        // A console command, which is the shape the specification's own
        // examples take. It is the same command a person runs by hand while
        // debugging, with the same arguments -- checked here, as the
        // application starts, rather than at two in the morning.
        $schedules->command('customer:sync', '--limit=100')
            ->describe('Pull anything the upstream system changed overnight.')
            ->dailyAt('02:00')
            ->withoutOverlapping(1800);

        // A job, which is the queue integration in one line: the scheduler
        // pushes and returns, and where the work actually happens is the
        // queue's configuration rather than this file's business.
        $schedules->job(ReviewCustomers::class)
            ->describe('Walk the customer list and report what it found.')
            ->weeklyOn(1, '03:30');

        // A closure, called through the container. Cheap, idempotent, and
        // allowed to overlap because two of it would cost nothing.
        $schedules->call(
            'customer:refresh-counts',
            static function (CustomerQuery $customers): void {
                $customers->forgetTotal();
            },
        )
            ->describe('Drop the cached customer count so the next read is fresh.')
            ->everyThirtyMinutes()
            ->allowOverlapping();
    });

    $module->hook('customer.created', [CustomerHooks::class, 'onCreated'], priority: 20);

    $module->filter('customer.name', [CustomerFilters::class, 'normaliseName'], priority: 10);

    $module->onBoot(static function (
        RelationManager $relations,
        DataSource $source,
        CustomerQuery $customers,
        HookEngine $hooks,
    ): void {
        // Demo rows, for the in-memory source only. A real source is populated
        // by this module's own migrations, which the database phase owns.
        if ($source instanceof ArraySource) {
            $source->seed('customers', [
                ['id' => 1, 'name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'ownerId' => 10],
                ['id' => 2, 'name' => 'Grace Hopper', 'email' => 'grace@example.test', 'ownerId' => 11],
                ['id' => 3, 'name' => 'Katherine Johnson', 'email' => 'katherine@example.test', 'ownerId' => null],
            ]);
        }

        // Relations are declared at boot rather than at registration, and that
        // is not an accident: this one crosses a module boundary, and the
        // shared module's User only exists to be related to once every module
        // has registered. The declaration is metadata -- nothing here loads
        // anything, and nothing will load it later on its own.
        $relations->declare(
            Customer::class,
            Relation::one('owner', User::class, localKey: 'ownerId', foreignKey: 'id'),
        );

        // Cache invalidation, declared where the dependency can be injected.
        // The cached customer count has a five minute TTL, but a TTL is a
        // backstop and not a plan: the thing that knows the count has changed
        // is the event saying a customer was created, so that is what clears
        // it. A cache whose only invalidation is time is a cache that is
        // usually wrong for a while.
        $hooks->add('customer.created', $customers->forgetTotal(...), 5, 'Example');
    });
};
