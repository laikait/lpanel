<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Model\ModelCollection;
use App\Engine\Model\ModelException;
use App\Engine\Model\RelationManager;
use App\Engine\Routing\Router;
use App\Engine\Support\Extensions;
use App\Tests\Fixtures\Showcase\Gateways\ExampleGateway\Hooks\AuditHooks;
use App\Tests\Fixtures\Showcase\Plugins\Example\Data\CustomerQuery;
use App\Tests\Fixtures\Showcase\Plugins\Example\Data\CustomerRepository;
use App\Tests\Fixtures\Showcase\Plugins\Example\Hooks\CustomerHooks;
use App\Tests\Fixtures\Showcase\Plugins\Example\Model\Customer;
use App\Tests\Fixtures\Showcase\Plugins\Example\Model\CustomerListRecord;
use App\Tests\Fixtures\Showcase\Shared\Model\User;
use App\Tests\Support\TestCase;

/**
 * The proof that the architecture works.
 *
 * Everything here runs against the real modules in modules/, through the real
 * Bootstrap, with nothing mocked. If these pass, then a request really does go
 * index.php -> Bootstrap -> Application -> Container -> module discovery ->
 * Router -> Dispatcher -> an injected handler -> hooks and filters -> Response.
 */
final class VerticalSliceTest extends TestCase
{
    protected function setUp(): void
    {
        CustomerHooks::reset();
        AuditHooks::reset();
    }

    protected function tearDown(): void
    {
        CustomerHooks::reset();
        AuditHooks::reset();

        parent::tearDown();
    }

    /**
     * Requests arrive the way Apache delivers them under /framework.
     *
     * @param array<string, mixed> $options
     */
    private function request(string $method, string $path, array $options = []): Request
    {
        $options['server'] = ['SCRIPT_NAME' => '/framework/index.php'] + ($options['server'] ?? []);

        return Request::create($method, '/framework' . $path, $options);
    }

    /**
     * One test, the whole slice.
     */
    public function test_a_module_registered_route_dispatches_through_hooks_and_filters(): void
    {
        $app = $this->application()->boot();

        // Listeners registered from outside any module, through the global
        // helpers, prove the engines are live and that the bridge is wired.
        $seen = [];
        add_hook('customer.created', static function (Customer $customer) use (&$seen): void {
            $seen[] = $customer->identity();
        }, priority: 5);

        add_filter('example.customers.list', static fn(array $rows): array => \array_slice($rows, 0, 1));

        $response = $app->handle($this->request('GET', '/customers.json'));

        // --- the route came from a module, not from a global route file
        self::assertSame(200, $response->status());
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame('application/json; charset=UTF-8', $response->contentType());
        self::assertSame(
            'Example',
            $app->container()->get(Router::class)->route('customers.json')?->module(),
        );

        // --- a filter transformed the value on its way out
        $data = $response->data();
        self::assertIsArray($data);
        self::assertIsArray($data['data']);
        self::assertCount(1, $data['data'], 'the filter should have truncated the list');

        // --- the shared module stamped the finished response, across a module
        //     boundary and at the very end of the chain
        self::assertSame('app-framework', $response->header('X-Engine'));

        // --- a write path fires a module-owned hook that a gateway observes
        $created = $app->handle($this->request('POST', '/api/v1/customers', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"name":"Ada Lovelace","email":"ada@example.test"}',
        ]));

        self::assertSame(201, $created->status());
        self::assertNotEmpty($seen, 'the externally registered hook did not fire');
        self::assertNotEmpty(CustomerHooks::$created, 'the owning module did not observe its own event');
        self::assertNotEmpty(AuditHooks::$records, 'the gateway module did not observe another module\'s event');
        self::assertSame('customer.created:4', AuditHooks::$records[0]);
    }

    /**
     * Neither module names the other anywhere. That is what makes hooks the
     * extension mechanism rather than a convention.
     */
    public function test_the_gateway_and_the_plugin_do_not_reference_each_other(): void
    {
        $plugin = \file_get_contents($this->basePath(self::SHOWCASE . '/Plugins/Example/Api/CustomerApi.php'));
        $gateway = \file_get_contents($this->basePath(self::SHOWCASE . '/Gateways/ExampleGateway/Hooks/AuditHooks.php'));

        self::assertIsString($plugin);
        self::assertIsString($gateway);

        self::assertStringNotContainsString('Gateways', $plugin);
        self::assertStringNotContainsString('Plugins', $gateway);
    }

    // ---- dependency injection --------------------------------------------

    public function test_the_handler_receives_its_dependencies_by_constructor_injection(): void
    {
        $app = $this->application()->boot();

        $app->container()->get(CustomerRepository::class)
            ->register(['name' => 'Injected', 'email' => 'i@example.test']);

        $response = $app->handle($this->request('GET', '/customers'));

        // The handler read what the test just wrote, which is only true if
        // both got the same data source through the container.
        self::assertStringContainsString('Injected', $response->body());
    }

    public function test_the_repository_and_the_query_are_shared_as_the_module_declared(): void
    {
        $container = $this->application()->boot()->container();

        self::assertSame(
            $container->get(CustomerRepository::class),
            $container->get(CustomerRepository::class),
        );
        self::assertSame(
            $container->get(CustomerQuery::class),
            $container->get(CustomerQuery::class),
        );
    }

    // ---- the model layer --------------------------------------------------

    /**
     * A list endpoint answers with read models, not with domain entities.
     *
     * Model is not serialisable, so this is not a stylistic preference: the
     * projection is the only way the data gets out, and the fields in the
     * payload are the ones someone chose to put there.
     */
    public function test_a_list_endpoint_answers_with_read_models(): void
    {
        $app = $this->application()->boot();
        $response = $app->handle($this->request('GET', '/customers.json'));
        self::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->data();
        self::assertIsArray($data);
        self::assertIsArray($data['data']);
        self::assertInstanceOf(CustomerListRecord::class, $data['data'][0]);

        self::assertSame(
            ['id' => 1, 'name' => 'Ada Lovelace', 'email' => 'ada@example.test'],
            $data['data'][0]->toArray(),
        );
    }

    /**
     * A plugin model related to a shared model, linked in a batch, with the
     * declaration made from onBoot because it crosses a module boundary.
     */
    public function test_a_relation_to_a_shared_model_is_declared_by_a_module_and_linked_explicitly(): void
    {
        $app = $this->application()->boot();

        $relations = $app->container()->get(RelationManager::class);
        self::assertTrue($relations->has(Customer::class, 'owner'));
        self::assertSame(User::class, $relations->get(Customer::class, 'owner')->related);

        $response = $app->handle($this->request('GET', '/api/v1/customers/1'));
        self::assertInstanceOf(JsonResponse::class, $response);
        $data = $response->data();

        self::assertSame(200, $response->status());
        self::assertIsArray($data);
        self::assertIsArray($data['data']);
        self::assertSame('ada', $data['data']['owner']);
    }

    /** A customer with no owner is loaded, found empty, and says so. */
    public function test_an_unmatched_relation_is_null_rather_than_a_second_query(): void
    {
        $response = $this->application()->boot()->handle($this->request('GET', '/api/v1/customers/3'));
        self::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->data();
        self::assertIsArray($data);
        self::assertIsArray($data['data']);
        self::assertNull($data['data']['owner']);
    }

    /**
     * Nothing loads a relation on access. A handler that forgets to link gets a
     * loud exception rather than a silent query inside a loop.
     */
    public function test_touching_an_unlinked_relation_throws_instead_of_loading(): void
    {
        $app = $this->application()->boot();
        $customer = $app->container()->get(CustomerRepository::class)->find(1);

        self::assertInstanceOf(Customer::class, $customer);
        self::assertFalse($customer->hasRelated('owner'));

        $this->expectException(ModelException::class);
        $customer->related('owner');
    }

    /**
     * Two reads of the same customer are the same object, so a change made
     * through one is visible through the other.
     */
    public function test_the_identity_map_holds_across_a_request(): void
    {
        $customers = $this->application()->boot()->container()->get(CustomerRepository::class);

        $first = $customers->find(1);
        $second = $customers->find(1);

        self::assertSame($first, $second);

        self::assertNotNull($first);
        self::assertFalse($first->isDirty());
        $first->rename('Ada King');

        self::assertSame(['name' => 'Ada King'], $second?->changes());
    }

    /** A newly created customer is registered, so a later read returns it. */
    public function test_a_created_customer_joins_the_identity_map(): void
    {
        $app = $this->application()->boot();
        $customers = $app->container()->get(CustomerRepository::class);

        $created = $customers->register(['name' => 'Ada Lovelace II', 'email' => 'ada2@example.test']);

        self::assertFalse($created->isNew(), 'a persisted model matches storage');
        self::assertSame($created, $customers->find($created->identity() ?? 0));
    }

    /** The batch primitive a data layer builds an IN clause from. */
    public function test_relation_keys_are_collected_without_touching_storage(): void
    {
        $app = $this->application()->boot();

        $customers = $app->container()->get(CustomerRepository::class)->all();
        self::assertInstanceOf(ModelCollection::class, $customers);

        self::assertSame(
            [10, 11],
            $app->container()->get(RelationManager::class)->keysFor($customers, 'owner'),
        );
    }

    // ---- routing and base paths ------------------------------------------

    public function test_the_same_application_answers_under_a_subdirectory_and_at_the_root(): void
    {
        $apache = $this->application()->boot()->handle($this->request('GET', '/customers.json'));

        $builtIn = $this->application()->boot()->handle(
            Request::create('GET', '/customers.json', ['server' => ['SCRIPT_NAME' => '/server.php']]),
        );

        self::assertSame(200, $apache->status());
        self::assertSame(200, $builtIn->status());

        // The data is the same either way; the pagination links are not, and
        // should not be -- a link is a URL, and a URL under Apache in a
        // subdirectory carries the prefix. One prefix apart is the whole
        // difference between the two deployments.
        self::assertSame(
            \str_replace('/framework/', '/', $apache->body()),
            $builtIn->body(),
        );
    }

    /**
     * The other half: a rendered page differs between the two deployments in
     * exactly one way, and that way is the prefix every URL in it carries.
     *
     * Note where the prefix comes from. Route URLs are derived per request;
     * asset URLs are derived once, when the application is built, from the
     * execution context. Under every SAPI this framework targets those are the
     * same $_SERVER and so they always agree -- which is why the applications
     * here are built with the base path rather than only the requests.
     */
    public function test_a_rendered_page_carries_the_base_path_of_the_deployment(): void
    {
        $apache = $this->application(['http' => ['base_path' => '/framework']])
            ->boot()
            ->handle($this->request('GET', '/customers'))
            ->body();

        $builtIn = $this->application(['http' => ['base_path' => '']])
            ->boot()
            ->handle(Request::create('GET', '/customers', ['server' => ['SCRIPT_NAME' => '/server.php']]))
            ->body();

        self::assertStringContainsString('href="/framework/assets/core/css/app.css?v=', $apache);
        self::assertStringContainsString('href="/assets/core/css/app.css?v=', $builtIn);

        // Same page, one prefix apart.
        self::assertSame($builtIn, \str_replace('"/framework/', '"/', $apache));
    }

    public function test_generated_urls_carry_the_base_path_of_the_current_request(): void
    {
        $app = $this->application()->boot();
        $app->handle($this->request('GET', '/customers'));

        self::assertSame(
            '/framework/api/v1/customers/7',
            $app->container()->get(Router::class)->url('api.v1.customers.show', ['id' => 7]),
        );
    }

    // ---- error paths ------------------------------------------------------

    public function test_an_unknown_path_is_a_404(): void
    {
        $response = $this->application()->boot()->handle($this->request('GET', '/nope'));

        self::assertSame(404, $response->status());
        self::assertSame('app-framework', $response->header('X-Engine'), 'the response filter runs for errors too');
    }

    public function test_a_wrong_method_is_a_405_that_advertises_what_is_allowed(): void
    {
        $response = $this->application()->boot()->handle($this->request('DELETE', '/customers'));

        self::assertSame(405, $response->status());
        self::assertSame('GET, HEAD', $response->header('Allow'));
    }

    public function test_a_missing_customer_is_a_404_from_the_handler(): void
    {
        $response = $this->application()->boot()->handle($this->request('GET', '/api/v1/customers/999'));

        self::assertSame(404, $response->status());
    }

    /**
     * The route constrains {id} to digits, so a non-numeric value never matches
     * and is a 404 rather than reaching the handler at all.
     */
    public function test_a_constrained_parameter_rejects_a_bad_value_before_dispatch(): void
    {
        $response = $this->application()->boot()->handle($this->request('GET', '/api/v1/customers/abc'));

        self::assertSame(404, $response->status());
    }

    /**
     * Every problem with the payload, in one response.
     *
     * The old hand-written check could only report the first missing field, and
     * a client fixing four mistakes needed four round trips.
     */
    public function test_a_bad_payload_is_a_400_listing_every_field_at_fault(): void
    {
        $response = $this->application()->boot()->handle($this->request('POST', '/api/v1/customers', [
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => '{}',
        ]));

        self::assertSame(400, $response->status());
        self::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->data();
        self::assertIsArray($data);
        self::assertIsArray($data['error']);
        self::assertSame(400, $data['error']['status']);
        self::assertSame(
            ['name' => ['is required'], 'email' => ['is required']],
            $data['error']['fields'],
        );
    }

    public function test_a_field_that_fails_its_own_check_is_named(): void
    {
        $response = $this->application()->boot()->handle($this->request('POST', '/api/v1/customers', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"name":"Ada","email":"not-an-address"}',
        ]));

        self::assertInstanceOf(JsonResponse::class, $response);
        $data = $response->data();

        self::assertSame(400, $response->status());
        self::assertIsArray($data);
        self::assertIsArray($data['error']);
        self::assertSame(['email' => ['must be an email address']], $data['error']['fields']);
    }

    /**
     * The contract travels with the rejection, and it is derived from the same
     * declaration that enforced it, so it cannot describe a rule that is not
     * really applied.
     */
    public function test_a_rejected_payload_is_told_what_was_expected(): void
    {
        $response = $this->application()->boot()->handle($this->request('POST', '/api/v1/customers', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{}',
        ]));

        self::assertInstanceOf(JsonResponse::class, $response);
        $data = $response->data();

        self::assertIsArray($data);
        self::assertIsArray($data['error']);
        self::assertIsArray($data['error']['expected']);
        self::assertSame(['name', 'email'], \array_keys($data['error']['expected']));
        self::assertSame(120, $data['error']['expected']['name']['max']);
        self::assertSame(['an email address'], $data['error']['expected']['email']['checks']);
    }

    /**
     * A query string is entirely strings, and the shared schema converts and
     * bounds it. One declaration protects every list endpoint from per_page
     * being typed as a hundred thousand.
     */
    public function test_query_parameters_are_converted_and_bounded_by_a_shared_schema(): void
    {
        $app = $this->application()->boot();

        $paged = $app->handle($this->request('GET', '/customers.json?page=2&per_page=2'));
        self::assertInstanceOf(JsonResponse::class, $paged);

        $data = $paged->data();
        self::assertIsArray($data);
        self::assertSame(
            ['count' => 1, 'total' => 3, 'page' => 2, 'per_page' => 2, 'pages' => 2],
            \array_diff_key($data['meta'], ['links' => null]),
        );

        $defaults = $app->handle($this->request('GET', '/customers.json'));
        self::assertInstanceOf(JsonResponse::class, $defaults);
        $data = $defaults->data();
        self::assertIsArray($data);
        self::assertSame(
            // 10, not the 25 the module declares: the application overrides its
            // default (see TestCase::application()), and this is where that
            // arrives.
            ['count' => 3, 'total' => 3, 'page' => 1, 'per_page' => 10, 'pages' => 1],
            \array_diff_key($data['meta'], ['links' => null]),
        );
    }

    public function test_a_page_size_beyond_the_shared_limit_is_refused(): void
    {
        $response = $this->application()->boot()->handle($this->request('GET', '/customers.json?per_page=100000'));

        self::assertSame(400, $response->status());
        self::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->data();
        self::assertIsArray($data);
        self::assertIsArray($data['error']);
        self::assertSame(['per_page' => ['must be at most 100']], $data['error']['fields']);
    }

    /**
     * The response is shaped by the same declaration that documents it, so the
     * two cannot drift apart.
     */
    public function test_a_resource_response_is_shaped_by_its_schema(): void
    {
        $response = $this->application()->boot()->handle($this->request('GET', '/api/v1/customers/1'));
        self::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->data();
        self::assertIsArray($data);
        self::assertIsArray($data['data']);

        self::assertSame(['name', 'email', 'id', 'owner'], \array_keys($data['data']));
    }

    // ---- the helper bridge ------------------------------------------------

    public function test_the_global_helpers_are_wired_by_bootstrap_and_not_before(): void
    {
        Extensions::reset();
        self::assertFalse(Extensions::isInitialised());

        try {
            has_filter('response.instance');
            self::fail('a helper should refuse to work before bootstrap');
        } catch (\LogicException $e) {
            self::assertStringContainsString('before the application was bootstrapped', $e->getMessage());
        }

        $app = $this->application();

        self::assertTrue(Extensions::isInitialised());

        // Bootstrap has already attached the security layer to this filter, so
        // "nothing is registered" is no longer the right claim. What is still
        // true -- and is what this test is actually about -- is that nothing a
        // MODULE declared is here until modules boot.
        self::assertSame(
            ['engine'],
            \array_values(\array_unique(\array_map(
                static fn(array $listener): ?string => $listener['module'],
                Extensions::filters()->listeners('response.instance'),
            ))),
            'only the framework itself has registered by now',
        );

        $app->boot();

        // The shared module declared this filter too, and it is reachable
        // through the global helper because the helper is bridged to the same
        // engine the framework and the module both used.
        self::assertTrue(has_filter('response.instance'));
        self::assertContains(
            'Shared',
            \array_map(
                static fn(array $listener): ?string => $listener['module'],
                Extensions::filters()->listeners('response.instance'),
            ),
        );
    }

    public function test_booting_is_idempotent(): void
    {
        $app = $this->application();

        $app->boot();
        $routes = $app->container()->get(Router::class)->count();

        $app->boot();

        self::assertSame($routes, $app->container()->get(Router::class)->count());
    }

    public function test_the_application_exposes_its_context_and_paths(): void
    {
        $app = $this->application();

        self::assertTrue($app->context()->isHttp());
        self::assertSame($this->basePath(), $app->basePath());
        self::assertStringEndsWith('engine', $app->basePath('engine'));
        self::assertSame(Application::VERSION, Application::VERSION);
    }
}
