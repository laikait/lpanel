<?php

declare(strict_types=1);

namespace App\Tests\Unit\Routing;

use App\Engine\Routing\MatchStatus;
use App\Engine\Routing\Route;
use App\Engine\Routing\RouteCollector;
use App\Engine\Routing\Router;
use App\Engine\Routing\RoutingException;
use App\Tests\Support\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    private RouteCollector $routes;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->routes = new RouteCollector($this->router, 'Example');
    }

    private function handler(string $tag = 'handler'): \Closure
    {
        return static fn(): string => $tag;
    }

    // ---- static matching --------------------------------------------------

    public function test_a_static_route_matches(): void
    {
        $this->routes->get('/customers', $this->handler());

        $match = $this->router->match('GET', '/customers');

        self::assertTrue($match->isMatched());
        self::assertSame('/customers', $match->route?->path());
        self::assertSame([], $match->parameters);
    }

    public function test_the_root_path_matches(): void
    {
        $this->routes->get('/', $this->handler());

        self::assertTrue($this->router->match('GET', '/')->isMatched());
    }

    public function test_a_trailing_slash_matches_the_same_route(): void
    {
        $this->routes->get('/customers', $this->handler());

        self::assertTrue($this->router->match('GET', '/customers/')->isMatched());
    }

    public function test_the_method_is_matched_case_insensitively(): void
    {
        $this->routes->get('/customers', $this->handler());

        self::assertTrue($this->router->match('get', '/customers')->isMatched());
    }

    public function test_an_unknown_path_is_not_found(): void
    {
        $this->routes->get('/customers', $this->handler());

        $match = $this->router->match('GET', '/nope');

        self::assertSame(MatchStatus::NotFound, $match->status);
        self::assertNull($match->route);
    }

    // ---- parameters -------------------------------------------------------

    public function test_a_single_parameter_is_captured(): void
    {
        $this->routes->get('/customers/{id}', $this->handler());

        $match = $this->router->match('GET', '/customers/42');

        self::assertTrue($match->isMatched());
        self::assertSame(['id' => '42'], $match->parameters);
        self::assertSame('42', $match->parameter('id'));
    }

    public function test_several_parameters_are_captured(): void
    {
        $this->routes->get('/customers/{customer}/invoices/{invoice}', $this->handler());

        $match = $this->router->match('GET', '/customers/7/invoices/99');

        self::assertSame(['customer' => '7', 'invoice' => '99'], $match->parameters);
    }

    public function test_a_constraint_is_enforced(): void
    {
        $this->routes->get('/customers/{id}', $this->handler())->where('id', '\d+');

        self::assertTrue($this->router->match('GET', '/customers/42')->isMatched());
        self::assertSame(MatchStatus::NotFound, $this->router->match('GET', '/customers/abc')->status);
    }

    /**
     * Deterministic precedence without any ordering rules for the author to
     * remember: a literal segment always beats a parameter at the same depth.
     */
    public function test_a_literal_beats_a_parameter_at_the_same_depth(): void
    {
        $this->routes->get('/customers/{id}', $this->handler('dynamic'));
        $this->routes->get('/customers/export', $this->handler('literal'));

        $match = $this->router->match('GET', '/customers/export');

        self::assertSame('literal', ($match->route?->handler())());
        self::assertSame([], $match->parameters);
    }

    public function test_the_literal_preference_holds_regardless_of_registration_order(): void
    {
        $this->routes->get('/customers/export', $this->handler('literal'));
        $this->routes->get('/customers/{id}', $this->handler('dynamic'));

        self::assertSame('literal', ($this->router->match('GET', '/customers/export')->route?->handler())());
        self::assertSame('dynamic', ($this->router->match('GET', '/customers/9')->route?->handler())());
    }

    // ---- paths in any language ------------------------------------------------

    public function test_a_route_in_another_script_matches(): void
    {
        $this->routes->get('/পণ্য/{slug}', $this->handler());

        $match = $this->router->match('GET', '/পণ্য/ঢাকা-শহর');

        self::assertTrue($match->isMatched());
        self::assertSame(['slug' => 'ঢাকা-শহর'], $match->parameters);
    }

    /** Matched as UTF-8: \p{L} is a letter in any script, not a byte. */
    public function test_a_unicode_constraint_matches_letters_in_any_script(): void
    {
        $this->routes->get('/tags/{slug}', $this->handler())->where('slug', '[\p{L}\p{M}-]+');

        self::assertTrue($this->router->match('GET', '/tags/ঢাকা-শহর')->isMatched());
        self::assertTrue($this->router->match('GET', '/tags/café')->isMatched());
        self::assertTrue($this->router->match('GET', '/tags/东京')->isMatched());
        self::assertSame(MatchStatus::NotFound, $this->router->match('GET', '/tags/2026')->status);
    }

    public function test_a_segment_that_is_not_utf8_fails_a_constraint(): void
    {
        $this->routes->get('/tags/{slug}', $this->handler())->where('slug', '.+');

        self::assertSame(MatchStatus::NotFound, $this->router->match('GET', "/tags/\xFF\xFE")->status);
    }

    /**
     * The same word typed on two systems: é as one character, and e followed
     * by a combining accent. Either spelling reaches a route written in either.
     */
    public function test_a_path_matches_however_its_accents_were_spelled(): void
    {
        $this->routes->get("/caf\u{E9}", $this->handler('composed'));
        $this->routes->get("/cafe\u{301}s/{name}", $this->handler('decomposed'));

        self::assertSame('composed', ($this->router->match('GET', "/cafe\u{301}")->route?->handler())());

        $match = $this->router->match('GET', "/caf\u{E9}s/Zo\u{EB}");

        self::assertSame('decomposed', ($match->route?->handler())());
        self::assertSame(['name' => "Zo\u{EB}"], $match->parameters);
        self::assertSame(['name' => "Zo\u{EB}"], $this->router->match('GET', "/cafe\u{301}s/Zoe\u{308}")->parameters);
    }

    public function test_a_parameter_does_not_match_across_a_separator(): void
    {
        $this->routes->get('/customers/{id}', $this->handler());

        self::assertSame(MatchStatus::NotFound, $this->router->match('GET', '/customers/7/invoices')->status);
    }

    public function test_a_trailing_optional_parameter_may_be_absent(): void
    {
        $this->routes->get('/reports/{period?}', $this->handler());

        $present = $this->router->match('GET', '/reports/2026');
        self::assertTrue($present->isMatched());
        self::assertSame(['period' => '2026'], $present->parameters);

        $absent = $this->router->match('GET', '/reports');
        self::assertTrue($absent->isMatched());
        self::assertSame([], $absent->parameters);
    }

    public function test_defaults_fill_in_an_absent_optional_parameter(): void
    {
        $this->routes->get('/reports/{period?}', $this->handler())->defaults(['period' => 'current']);

        self::assertSame(['period' => 'current'], $this->router->match('GET', '/reports')->parameters);
        self::assertSame(['period' => '2026'], $this->router->match('GET', '/reports/2026')->parameters);
    }

    public function test_an_optional_parameter_must_be_last(): void
    {
        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('must be the last segment');

        $this->routes->get('/reports/{period?}/detail', $this->handler());
        $this->router->compile();
    }

    public function test_an_invalid_parameter_name_is_rejected(): void
    {
        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('not a valid parameter name');

        $this->routes->get('/customers/{9bad}', $this->handler());
        $this->router->compile();
    }

    // ---- methods ----------------------------------------------------------

    public function test_the_wrong_method_reports_what_is_allowed(): void
    {
        $this->routes->get('/customers', $this->handler());
        $this->routes->post('/customers', $this->handler());

        $match = $this->router->match('DELETE', '/customers');

        self::assertSame(MatchStatus::MethodNotAllowed, $match->status);
        self::assertSame(['GET', 'HEAD', 'POST'], $match->allowedMethods);
    }

    public function test_allowed_methods_are_computed_for_parametric_routes_too(): void
    {
        $this->routes->get('/customers/{id}', $this->handler());
        $this->routes->patch('/customers/{id}', $this->handler());

        self::assertSame(
            ['GET', 'HEAD', 'PATCH'],
            $this->router->match('DELETE', '/customers/7')->allowedMethods,
        );
    }

    public function test_head_falls_back_to_the_get_route(): void
    {
        $this->routes->get('/customers', $this->handler('get'));

        $match = $this->router->match('HEAD', '/customers');

        self::assertTrue($match->isMatched());
        self::assertSame('get', ($match->route?->handler())());
    }

    public function test_an_explicit_head_route_wins_over_the_get_fallback(): void
    {
        $this->routes->get('/customers', $this->handler('get'));
        $this->routes->head('/customers', $this->handler('head'));

        self::assertSame('head', ($this->router->match('HEAD', '/customers')->route?->handler())());
    }

    public function test_match_registers_several_methods(): void
    {
        $this->routes->match(['GET', 'POST'], '/webhook', $this->handler());

        self::assertTrue($this->router->match('GET', '/webhook')->isMatched());
        self::assertTrue($this->router->match('POST', '/webhook')->isMatched());
        self::assertSame(MatchStatus::MethodNotAllowed, $this->router->match('PUT', '/webhook')->status);
    }

    public function test_an_unsupported_method_is_rejected_at_registration(): void
    {
        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('not a supported HTTP method');

        $this->router->add(new Route('TRACE', '/x', $this->handler()));
    }

    // ---- groups -----------------------------------------------------------

    public function test_a_group_prefixes_paths(): void
    {
        $this->routes->group('/api/v1', function (RouteCollector $routes): void {
            $routes->get('/customers', $this->handler());
        });

        self::assertTrue($this->router->match('GET', '/api/v1/customers')->isMatched());
        self::assertSame(MatchStatus::NotFound, $this->router->match('GET', '/customers')->status);
    }

    public function test_a_group_prefixes_names(): void
    {
        $this->routes->group('/api/v1', function (RouteCollector $routes): void {
            $routes->get('/customers', $this->handler())->name('customers.index');
        }, name: 'api.v1.');

        self::assertNotNull($this->router->route('api.v1.customers.index'));
        self::assertNull($this->router->route('customers.index'));
    }

    public function test_nested_groups_compose_prefixes_and_names(): void
    {
        $this->routes->group('/api', function (RouteCollector $routes): void {
            $routes->group('/v1', function (RouteCollector $routes): void {
                $routes->get('/customers', $this->handler())->name('customers.index');
            }, name: 'v1.');
        }, name: 'api.');

        $route = $this->router->route('api.v1.customers.index');

        self::assertNotNull($route);
        self::assertSame('/api/v1/customers', $route->path());
    }

    public function test_group_metadata_reaches_every_route_and_merges_with_nesting(): void
    {
        $this->routes->group('/api', function (RouteCollector $routes): void {
            $routes->group('/admin', function (RouteCollector $routes): void {
                $routes->get('/users', $this->handler())->name('users')->meta(['scope' => 'users']);
            }, meta: ['admin' => true]);
        }, meta: ['api' => true, 'admin' => false]);

        $route = $this->router->route('users');

        self::assertNotNull($route);
        self::assertSame(['api' => true, 'admin' => true, 'scope' => 'users'], $route->metadata());
        self::assertTrue($route->metaValue('api'));
        self::assertNull($route->metaValue('missing'));
    }

    public function test_an_empty_group_prefix_changes_nothing(): void
    {
        $this->routes->group('', function (RouteCollector $routes): void {
            $routes->get('/customers', $this->handler());
        });

        self::assertTrue($this->router->match('GET', '/customers')->isMatched());
    }

    // ---- names and ownership ---------------------------------------------

    public function test_a_route_records_the_module_that_registered_it(): void
    {
        $this->routes->get('/customers', $this->handler())->name('customers.index');

        self::assertSame('Example', $this->router->route('customers.index')?->module());
    }

    public function test_a_duplicate_route_name_is_rejected_and_names_both_modules(): void
    {
        $this->routes->get('/customers', $this->handler())->name('customers.index');

        (new RouteCollector($this->router, 'Other'))
            ->get('/others', $this->handler())
            ->name('customers.index');

        try {
            $this->router->compile();
            self::fail('expected a duplicate name failure');
        } catch (RoutingException $e) {
            self::assertStringContainsString('customers.index', $e->getMessage());
            self::assertStringContainsString('Example', $e->getMessage());
            self::assertStringContainsString('Other', $e->getMessage());
        }
    }

    public function test_an_unknown_route_name_returns_null(): void
    {
        self::assertNull($this->router->route('nope'));
    }

    // ---- URL generation ---------------------------------------------------

    public function test_a_url_is_built_from_a_named_route(): void
    {
        $this->routes->get('/customers/{id}', $this->handler())->name('customers.show');

        self::assertSame('/customers/42', $this->router->url('customers.show', ['id' => 42]));
    }

    public function test_a_url_includes_the_base_path(): void
    {
        $this->router->setBasePath('/framework');
        $this->routes->get('/customers', $this->handler())->name('customers.index');

        self::assertSame('/framework/customers', $this->router->url('customers.index'));
    }

    public function test_the_root_url_under_a_base_path_is_the_base_path(): void
    {
        $this->router->setBasePath('/framework');
        $this->routes->get('/', $this->handler())->name('home');

        self::assertSame('/framework', $this->router->url('home'));
    }

    public function test_extra_parameters_become_the_query_string(): void
    {
        $this->routes->get('/customers', $this->handler())->name('customers.index');

        self::assertSame('/customers?page=2', $this->router->url('customers.index', ['page' => 2]));
    }

    public function test_a_url_parameter_is_percent_encoded(): void
    {
        $this->routes->get('/customers/{name}', $this->handler())->name('customers.byName');

        self::assertSame('/customers/Ada%20Lovelace', $this->router->url('customers.byName', ['name' => 'Ada Lovelace']));
    }

    /** Fixed segments are encoded like parameters, and the URL routes back to the route. */
    public function test_a_fixed_segment_in_another_script_is_percent_encoded(): void
    {
        $this->routes->get('/পণ্য/{slug}', $this->handler())->name('products.show');

        $url = $this->router->url('products.show', ['slug' => 'ঢাকা']);

        self::assertSame('/' . \rawurlencode('পণ্য') . '/' . \rawurlencode('ঢাকা'), $url);

        $request = \App\Engine\Http\Request::create('GET', $url);

        self::assertSame(['slug' => 'ঢাকা'], $this->router->match('GET', $request->path())->parameters);
    }

    /** Only bytes outside ASCII are encoded, so an existing ASCII URL does not change. */
    public function test_an_ascii_fixed_segment_is_written_as_declared(): void
    {
        $this->routes->get('/v1:batch/~reports', $this->handler())->name('batch');

        self::assertSame('/v1:batch/~reports', $this->router->url('batch'));
    }

    public function test_a_unicode_value_passes_a_unicode_constraint_when_building_a_url(): void
    {
        $this->routes->get('/tags/{slug}', $this->handler())->where('slug', '[\p{L}\p{M}-]+')->name('tags.show');

        self::assertSame('/tags/' . \rawurlencode("caf\u{E9}"), $this->router->url('tags.show', ['slug' => "cafe\u{301}"]));
    }

    public function test_an_absent_optional_parameter_is_omitted(): void
    {
        $this->routes->get('/reports/{period?}', $this->handler())->name('reports');

        self::assertSame('/reports', $this->router->url('reports'));
        self::assertSame('/reports/2026', $this->router->url('reports', ['period' => '2026']));
    }

    public function test_a_missing_required_parameter_is_reported(): void
    {
        $this->routes->get('/customers/{id}', $this->handler())->name('customers.show');

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('the required parameter "id" was not supplied');

        $this->router->url('customers.show');
    }

    /**
     * Generating a URL the router would then refuse to match is a bug worth
     * surfacing where it is created rather than on the next request.
     */
    public function test_a_value_that_violates_a_constraint_is_rejected(): void
    {
        $this->routes->get('/customers/{id}', $this->handler())->where('id', '\d+')->name('customers.show');

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('is not a valid value');

        $this->router->url('customers.show', ['id' => 'abc']);
    }

    public function test_generating_a_url_for_an_unknown_name_is_reported(): void
    {
        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('No route is named "nope"');

        $this->router->url('nope');
    }

    // ---- compilation ------------------------------------------------------

    public function test_a_route_is_frozen_once_the_router_compiles(): void
    {
        $route = $this->routes->get('/customers', $this->handler());
        $this->router->compile();

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('the router has already compiled');

        $route->name('too.late');
    }

    public function test_adding_a_route_after_compiling_recompiles(): void
    {
        $this->routes->get('/customers', $this->handler());
        $this->router->compile();

        $this->routes->get('/invoices', $this->handler());

        self::assertTrue($this->router->match('GET', '/invoices')->isMatched());
        self::assertSame(2, $this->router->count());
    }

    /**
     * The point of the trie is that matching cost tracks path depth, not route
     * count. This is a sanity check that a realistic table still resolves.
     */
    public function test_a_large_route_table_still_matches_correctly(): void
    {
        for ($i = 0; $i < 500; ++$i) {
            $this->routes->get(\sprintf('/resource%d/{id}/detail', $i), $this->handler('h' . $i));
        }

        $match = $this->router->match('GET', '/resource499/77/detail');

        self::assertTrue($match->isMatched());
        self::assertSame('h499', ($match->route?->handler())());
        self::assertSame(['id' => '77'], $match->parameters);
        self::assertSame(MatchStatus::NotFound, $this->router->match('GET', '/resource500/1/detail')->status);
    }

    public function test_routes_are_listed_for_introspection(): void
    {
        $this->routes->get('/customers', $this->handler())->name('customers.index');
        $this->routes->post('/customers', $this->handler());

        self::assertCount(2, $this->router->routes());
        self::assertSame(['customers.index'], \array_keys($this->router->namedRoutes()));
    }
}
