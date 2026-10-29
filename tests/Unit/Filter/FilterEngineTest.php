<?php

declare(strict_types=1);

namespace App\Tests\Unit\Filter;

use App\Engine\Filter\FilterEngine;
use App\Engine\Filter\FilterException;
use App\Tests\Support\TestCase;

final class FilterEngineTest extends TestCase
{
    private FilterEngine $filters;

    protected function setUp(): void
    {
        $this->filters = new FilterEngine();
    }

    // ---- the observation seam -----------------------------------------------

    public function test_an_observer_hears_every_listener_and_the_value_is_unchanged_by_it(): void
    {
        $heard = [];
        $this->filters->observe(static function (string $filter, \App\Engine\Support\Callback $listener) use (&$heard): void {
            $heard[] = $filter . ' ' . $listener->module;
        });

        $this->filters->add('invoice.total', static fn(int $t): int => $t * 2, 10, 'Billing');
        $this->filters->add('invoice.total', static fn(int $t): int => $t + 1, 20, 'Tax');

        self::assertSame(21, $this->filters->apply('invoice.total', 10));
        self::assertSame(['invoice.total Billing', 'invoice.total Tax'], $heard);
    }

    public function test_the_debug_null_guard_still_applies_while_observed(): void
    {
        $filters = new FilterEngine(debug: true);
        $heard = 0;
        $filters->observe(static function () use (&$heard): void {
            ++$heard;
        });
        $filters->add('x', [ForgetfulFilter::class, 'forgetsToReturn']);

        try {
            $filters->apply('x', 2);
            self::fail('the null guard did not fire');
        } catch (FilterException) {
        }

        self::assertSame(1, $heard);
    }

    // ---- transformation ---------------------------------------------------

    public function test_a_value_passes_through_every_listener_in_order(): void
    {
        $this->filters->add('invoice.total', static fn(int $t): int => $t + 100, 30);
        $this->filters->add('invoice.total', static fn(int $t): int => $t * 2, 10);
        $this->filters->add('invoice.total', static fn(int $t): int => $t - 5, 20);

        // 50 -> *2 = 100 -> -5 = 95 -> +100 = 195
        self::assertSame(195, $this->filters->apply('invoice.total', 50));
    }

    public function test_equal_priorities_apply_in_registration_order(): void
    {
        $this->filters->add('name', static fn(string $n): string => $n . '-first');
        $this->filters->add('name', static fn(string $n): string => $n . '-second');

        self::assertSame('x-first-second', $this->filters->apply('name', 'x'));
    }

    public function test_an_unregistered_filter_returns_the_value_untouched(): void
    {
        $value = ['a' => 1];

        self::assertSame($value, $this->filters->apply('nothing.listens', $value));
    }

    public function test_a_filter_can_change_the_type_of_the_value(): void
    {
        $this->filters->add('x', static fn(int $n): string => (string) $n);

        self::assertSame('42', $this->filters->apply('x', 42));
    }

    // ---- context arguments ------------------------------------------------

    /**
     * Extra arguments are context, not values. Every listener sees the original
     * ones regardless of what earlier listeners did to the value.
     */
    public function test_context_arguments_reach_every_listener_unchanged(): void
    {
        $seen = [];

        $this->filters->add('customer.name', static function (string $name, string $locale, int $id) use (&$seen): string {
            $seen[] = [$locale, $id];

            return \strtoupper($name);
        });

        $this->filters->add('customer.name', static function (string $name, string $locale, int $id) use (&$seen): string {
            $seen[] = [$locale, $id];

            return $name . '!';
        });

        $result = $this->filters->apply('customer.name', 'ada', 'en_GB', 7);

        self::assertSame('ADA!', $result);
        self::assertSame([['en_GB', 7], ['en_GB', 7]], $seen);
    }

    /**
     * Userland callables ignore surplus arguments; PHP internals throw. Without
     * acceptedArgs, add_filter('x', 'strtoupper') would be a fatal error the
     * moment anyone passed context.
     */
    public function test_accepted_args_makes_php_internals_usable_as_filters(): void
    {
        $this->filters->add('x', 'strtoupper', 10, null, 1);

        self::assertSame('ADA', $this->filters->apply('x', 'ada', 'context', 'more'));
    }

    // ---- removal and introspection ---------------------------------------

    public function test_a_filter_can_be_removed_by_handle(): void
    {
        $handle = $this->filters->add('x', static fn(string $v): string => $v . '-changed');

        self::assertTrue($this->filters->remove('x', $handle));
        self::assertSame('v', $this->filters->apply('x', 'v'));
    }

    public function test_has_reports_registration(): void
    {
        self::assertFalse($this->filters->has('x'));

        $this->filters->add('x', 'strtoupper', 10, null, 1);

        self::assertTrue($this->filters->has('x'));
        self::assertTrue($this->filters->has('x', 'strtoupper'));
        self::assertFalse($this->filters->has('x', 'strtolower'));
    }

    public function test_listeners_report_module_ownership(): void
    {
        $this->filters->add('invoice.total', 'intval', 30, 'Billing', 1);

        $listeners = $this->filters->listeners('invoice.total');

        self::assertCount(1, $listeners);
        self::assertSame('Billing', $listeners[0]['module']);
        self::assertSame(30, $listeners[0]['priority']);
        self::assertSame('intval', $listeners[0]['callback']);
    }

    public function test_remove_all_clears_registrations(): void
    {
        $this->filters->add('a', 'strtoupper', 10, null, 1);
        $this->filters->add('b', 'strtolower', 10, null, 1);

        $this->filters->removeAll('a');
        self::assertFalse($this->filters->has('a'));
        self::assertTrue($this->filters->has('b'));

        $this->filters->removeAll();
        self::assertSame([], $this->filters->names());
    }

    // ---- snapshot semantics ----------------------------------------------

    public function test_a_listener_added_during_an_application_does_not_join_it(): void
    {
        $this->filters->add('x', function (string $v): string {
            $this->filters->add('x', static fn(string $inner): string => $inner . '-late', 1);

            return $v . '-first';
        });

        self::assertSame('v-first', $this->filters->apply('x', 'v'));
        self::assertSame('v-late-first', $this->filters->apply('x', 'v'));
    }

    // ---- the null guard ---------------------------------------------------

    /**
     * Forgetting to return is the commonest mistake in this style of extension.
     * In debug mode it is an error that names the culprit; in production the
     * null is used, because breaking a live request over it would be worse.
     */
    public function test_debug_mode_catches_a_filter_that_forgot_to_return(): void
    {
        $filters = new FilterEngine(debug: true);
        $filters->add('invoice.total', [ForgetfulFilter::class, 'forgetsToReturn'], 10, 'Billing');

        try {
            $filters->apply('invoice.total', 100);
            self::fail('expected the null guard to fire');
        } catch (FilterException $e) {
            self::assertStringContainsString('invoice.total', $e->getMessage());
            self::assertStringContainsString('forgetsToReturn', $e->getMessage());
            self::assertStringContainsString('Billing', $e->getMessage());
        }
    }

    public function test_production_uses_the_null_rather_than_failing(): void
    {
        $filters = new FilterEngine(debug: false);
        $filters->add('x', [ForgetfulFilter::class, 'forgetsToReturn']);

        self::assertNull($filters->apply('x', 100));
    }

    public function test_a_filter_may_legitimately_turn_null_into_null(): void
    {
        $filters = new FilterEngine(debug: true);
        $filters->add('x', static fn(mixed $v): mixed => $v);

        self::assertNull($filters->apply('x', null));
    }

    public function test_debug_can_be_switched_on_after_construction(): void
    {
        $filters = new FilterEngine(debug: false);
        $filters->add('x', [ForgetfulFilter::class, 'forgetsToReturn']);

        self::assertNull($filters->apply('x', 1));

        $filters->setDebug(true);

        $this->expectException(FilterException::class);
        $filters->apply('x', 1);
    }

    // ---- recursion --------------------------------------------------------

    public function test_runaway_recursion_throws(): void
    {
        $this->filters->add('x', function (int $v): int {
            return $this->filters->apply('x', $v + 1);
        });

        $this->expectException(FilterException::class);
        $this->expectExceptionMessage('still applying');

        $this->filters->apply('x', 0);
    }

    public function test_applying_a_different_filter_from_within_one_is_fine(): void
    {
        $this->filters->add('outer', function (string $v): string {
            return $this->filters->apply('inner', $v . '-outer');
        });

        $this->filters->add('inner', static fn(string $v): string => $v . '-inner');

        self::assertSame('v-outer-inner', $this->filters->apply('outer', 'v'));
    }
}

final class ForgetfulFilter
{
    public static function forgetsToReturn(mixed $value): mixed
    {
        $doubled = \is_int($value) ? $value * 2 : $value;

        // The bug this guard exists for: the result is computed and dropped.
        unset($doubled);

        return null;
    }
}
