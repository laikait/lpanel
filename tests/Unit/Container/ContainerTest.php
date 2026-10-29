<?php

declare(strict_types=1);

namespace App\Tests\Unit\Container;

use App\Engine\Container\Container;
use App\Engine\Container\ContainerException;
use App\Engine\Container\EntryNotFoundException;
use App\Tests\Fixtures\Container\AbstractThing;
use App\Tests\Fixtures\Container\CircularA;
use App\Tests\Fixtures\Container\EnglishGreeter;
use App\Tests\Fixtures\Container\Greeter;
use App\Tests\Fixtures\Container\IntersectionDependency;
use App\Tests\Fixtures\Container\Invokable;
use App\Tests\Fixtures\Container\Leaf;
use App\Tests\Fixtures\Container\Methods;
use App\Tests\Fixtures\Container\Middle;
use App\Tests\Fixtures\Container\MixedDependency;
use App\Tests\Fixtures\Container\NeedsGreeter;
use App\Tests\Fixtures\Container\NeedsScalar;
use App\Tests\Fixtures\Container\NullableDependency;
use App\Tests\Fixtures\Container\PrivateConstructor;
use App\Tests\Fixtures\Container\Root;
use App\Tests\Fixtures\Container\ScalarWithDefault;
use App\Tests\Fixtures\Container\UnionDependency;
use App\Tests\Fixtures\Container\Untyped;
use App\Tests\Fixtures\Container\VariadicLeaves;
use App\Tests\Support\TestCase;

final class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    // ---- binding ---------------------------------------------------------

    public function test_a_closure_binding_receives_the_container(): void
    {
        $this->container->bind('thing', static fn(Container $c): object => new Leaf());

        self::assertInstanceOf(Leaf::class, $this->container->get('thing'));
    }

    public function test_a_non_shared_binding_returns_a_new_instance_each_time(): void
    {
        $this->container->bind(Leaf::class);

        self::assertNotSame($this->container->get(Leaf::class), $this->container->get(Leaf::class));
    }

    public function test_a_singleton_returns_the_same_instance(): void
    {
        $this->container->singleton(Leaf::class);

        self::assertSame($this->container->get(Leaf::class), $this->container->get(Leaf::class));
    }

    public function test_an_instance_binding_returns_exactly_that_object(): void
    {
        $leaf = new Leaf();
        $this->container->instance('leaf', $leaf);

        self::assertSame($leaf, $this->container->get('leaf'));
    }

    public function test_rebinding_discards_a_previously_shared_instance(): void
    {
        $this->container->singleton(Leaf::class);
        $first = $this->container->get(Leaf::class);

        $this->container->singleton(Leaf::class);

        self::assertNotSame($first, $this->container->get(Leaf::class));
    }

    public function test_binding_an_interface_to_a_concrete_class_acts_as_an_alias(): void
    {
        $this->container->bind(Greeter::class, EnglishGreeter::class);

        $resolved = $this->container->get(NeedsGreeter::class);

        self::assertInstanceOf(EnglishGreeter::class, $resolved->greeter);
    }

    public function test_resolved_reports_whether_a_shared_instance_exists(): void
    {
        $this->container->singleton(Leaf::class);

        self::assertFalse($this->container->resolved(Leaf::class));
        $this->container->get(Leaf::class);
        self::assertTrue($this->container->resolved(Leaf::class));
    }

    public function test_has_covers_bindings_and_autowirable_classes_but_not_unknown_ids(): void
    {
        $this->container->bind('thing', static fn(): object => new Leaf());

        self::assertTrue($this->container->has('thing'));
        self::assertTrue($this->container->has(Leaf::class));
        self::assertFalse($this->container->has('nope'));

        // An unbound interface cannot be returned, so has() is honest about it
        // even though get() gives a more specific error than "unknown".
        self::assertFalse($this->container->has(Greeter::class));
    }

    /**
     * Sharing has to hold at every depth. If a singleton is rebuilt whenever it
     * is injected into something else, "singleton" means nothing: the object
     * get() hands out and the one a service receives would be different.
     */
    public function test_a_singleton_is_shared_with_nested_dependencies_too(): void
    {
        $this->container->singleton(Leaf::class);

        $direct = $this->container->get(Leaf::class);
        $injected = $this->container->get(Middle::class)->leaf;
        $deeper = $this->container->get(Root::class)->middle->leaf;

        self::assertSame($direct, $injected);
        self::assertSame($direct, $deeper);
    }

    public function test_an_instance_binding_is_visible_to_nested_autowiring(): void
    {
        $leaf = new Leaf();
        $this->container->instance(Leaf::class, $leaf);

        self::assertSame($leaf, $this->container->get(Middle::class)->leaf);
        self::assertSame($leaf, $this->container->get(Root::class)->middle->leaf);
    }

    public function test_a_non_shared_binding_is_still_rebuilt_for_each_dependent(): void
    {
        $this->container->bind(Leaf::class);

        self::assertNotSame(
            $this->container->get(Middle::class)->leaf,
            $this->container->get(Middle::class)->leaf,
        );
    }

    // ---- autowiring ------------------------------------------------------

    public function test_it_autowires_an_unbound_concrete_class(): void
    {
        self::assertInstanceOf(Leaf::class, $this->container->get(Leaf::class));
    }

    public function test_it_autowires_a_three_level_dependency_graph(): void
    {
        $root = $this->container->get(Root::class);

        self::assertInstanceOf(Middle::class, $root->middle);
        self::assertInstanceOf(Leaf::class, $root->middle->leaf);
    }

    public function test_an_unknown_identifier_throws_entry_not_found(): void
    {
        $this->expectException(EntryNotFoundException::class);
        $this->expectExceptionMessage('"totally.unknown"');

        $this->container->get('totally.unknown');
    }

    public function test_an_unbound_interface_reports_that_it_is_not_instantiable(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('it is an interface');

        $this->container->get(Greeter::class);
    }

    public function test_an_abstract_class_reports_that_it_is_abstract(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('it is abstract');

        $this->container->get(AbstractThing::class);
    }

    public function test_a_private_constructor_is_reported_clearly(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('constructor is not public');

        $this->container->get(PrivateConstructor::class);
    }

    // ---- circular dependencies -------------------------------------------

    public function test_a_circular_dependency_reports_the_whole_chain(): void
    {
        try {
            $this->container->get(CircularA::class);
            self::fail('expected a circular dependency failure');
        } catch (ContainerException $e) {
            self::assertStringContainsString('Circular dependency detected', $e->getMessage());
            self::assertStringContainsString('CircularA -> ', $e->getMessage());
            self::assertStringContainsString('CircularB -> ', $e->getMessage());
            self::assertStringContainsString('CircularC -> ', $e->getMessage());
            self::assertStringEndsWith('CircularA', $e->getMessage());
        }
    }

    public function test_the_build_stack_unwinds_after_a_failure(): void
    {
        try {
            $this->container->get(CircularA::class);
        } catch (ContainerException) {
            // Deliberately swallowed: the point is what happens next.
        }

        // If the stack had leaked, this second attempt would report a bogus cycle.
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency detected');
        $this->container->get(CircularA::class);
    }

    // ---- parameter resolution rules --------------------------------------

    public function test_an_unresolvable_scalar_names_the_owner_and_the_parameter(): void
    {
        try {
            $this->container->get(NeedsScalar::class);
            self::fail('expected an unresolvable parameter failure');
        } catch (ContainerException $e) {
            self::assertStringContainsString('$dsn', $e->getMessage());
            self::assertStringContainsString('NeedsScalar::__construct()', $e->getMessage());
            self::assertStringContainsString('string', $e->getMessage());
        }
    }

    public function test_a_scalar_with_a_default_falls_back_to_that_default(): void
    {
        self::assertSame('sqlite::memory:', $this->container->get(ScalarWithDefault::class)->dsn);
    }

    public function test_an_untyped_parameter_with_no_default_is_rejected(): void
    {
        try {
            $this->container->get(Untyped::class);
            self::fail('expected an unresolvable parameter failure');
        } catch (ContainerException $e) {
            self::assertStringContainsString('untyped', $e->getMessage());
            self::assertStringContainsString('$whatever', $e->getMessage());
        }
    }

    public function test_a_mixed_parameter_falls_back_to_null_because_mixed_allows_it(): void
    {
        self::assertNull($this->container->get(MixedDependency::class)->anything);
    }

    public function test_a_nullable_class_dependency_still_resolves_when_it_can(): void
    {
        self::assertInstanceOf(Leaf::class, $this->container->get(NullableDependency::class)->leaf);
    }

    public function test_a_variadic_class_parameter_resolves_to_zero_arguments(): void
    {
        self::assertSame([], $this->container->get(VariadicLeaves::class)->leaves);
    }

    public function test_a_union_resolves_the_single_bound_member(): void
    {
        $this->container->bind(Middle::class);

        self::assertInstanceOf(Middle::class, $this->container->get(UnionDependency::class)->either);
    }

    public function test_a_union_with_no_bound_member_is_never_guessed(): void
    {
        try {
            $this->container->get(UnionDependency::class);
            self::fail('expected an ambiguous union failure');
        } catch (ContainerException $e) {
            self::assertStringContainsString('union type', $e->getMessage());
            self::assertStringContainsString('$either', $e->getMessage());
        }
    }

    public function test_an_intersection_type_is_never_autowired(): void
    {
        try {
            $this->container->get(IntersectionDependency::class);
            self::fail('expected an intersection failure');
        } catch (ContainerException $e) {
            self::assertStringContainsString('intersection', $e->getMessage());
        }
    }

    // ---- make() ----------------------------------------------------------

    public function test_make_without_overrides_behaves_exactly_like_get(): void
    {
        $this->container->singleton(Leaf::class);

        self::assertSame($this->container->get(Leaf::class), $this->container->make(Leaf::class));
    }

    public function test_make_applies_overrides_by_parameter_name(): void
    {
        $made = $this->container->make(NeedsScalar::class, ['dsn' => 'mysql:host=localhost']);

        self::assertSame('mysql:host=localhost', $made->dsn);
    }

    public function test_make_overrides_do_not_leak_into_nested_resolutions(): void
    {
        // Root has no $dsn; Middle and Leaf have no $dsn either. If overrides
        // propagated, this would still succeed but for the wrong reason -- so
        // the assertion that matters is that the nested graph is untouched.
        $root = $this->container->make(Root::class, ['middle' => new Middle(new Leaf())]);

        self::assertInstanceOf(Leaf::class, $root->middle->leaf);
    }

    public function test_make_with_overrides_does_not_return_the_shared_instance(): void
    {
        $this->container->singleton(NeedsScalar::class, static fn(): object => new NeedsScalar('shared'));
        $shared = $this->container->get(NeedsScalar::class);

        $made = $this->container->make(NeedsScalar::class, ['dsn' => 'fresh']);

        self::assertSame('shared', $shared->dsn);
        self::assertNotSame($shared, $made);
    }

    // ---- call() ----------------------------------------------------------

    public function test_call_resolves_a_closures_parameters(): void
    {
        $result = $this->container->call(static fn(Leaf $leaf): string => \get_class($leaf));

        self::assertSame(Leaf::class, $result);
    }

    public function test_call_invokes_an_instance_method_on_a_resolved_object(): void
    {
        $result = $this->container->call([Methods::class, 'instanceMethod'], ['label' => 'x']);

        self::assertSame('x:other', $result);
    }

    public function test_call_invokes_a_static_method_without_constructing_the_class(): void
    {
        $this->container->bind(Greeter::class, EnglishGreeter::class);

        self::assertSame('x:hello', $this->container->call([Methods::class, 'staticMethod'], ['label' => 'x']));
    }

    public function test_call_accepts_the_class_double_colon_method_string_form(): void
    {
        $this->container->bind(Greeter::class, EnglishGreeter::class);

        self::assertSame('none:hello', $this->container->call(Methods::class . '::staticMethod'));
    }

    public function test_call_invokes_an_invokable_object(): void
    {
        $invokable = $this->container->get(Invokable::class);

        self::assertInstanceOf(Leaf::class, $invokable->leaf());
        self::assertSame('invoked?', $this->container->call($invokable, ['suffix' => '?']));
    }

    public function test_call_overrides_win_over_type_resolution(): void
    {
        $leaf = new Leaf();
        $methods = $this->container->get(Methods::class);

        $result = $this->container->call([$methods, 'instanceMethod'], ['leaf' => $leaf, 'label' => 'y']);

        self::assertSame('y:other', $result);
    }

    public function test_call_rejects_something_that_is_not_callable(): void
    {
        $this->expectException(ContainerException::class);

        $this->container->call([Methods::class, 'noSuchMethod']);
    }

    // ---- reflection caching ----------------------------------------------

    public function test_the_reflection_cache_still_yields_independent_instances(): void
    {
        $first = $this->container->get(Root::class);
        $second = $this->container->get(Root::class);

        self::assertNotSame($first, $second);
        self::assertNotSame($first->middle, $second->middle);
        self::assertEquals($first->middle->leaf, $second->middle->leaf);
    }
}
