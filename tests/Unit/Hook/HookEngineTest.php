<?php

declare(strict_types=1);

namespace App\Tests\Unit\Hook;

use App\Engine\Hook\HookEngine;
use App\Engine\Hook\HookException;
use App\Tests\Support\TestCase;

final class HookEngineTest extends TestCase
{
    private HookEngine $hooks;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->hooks = new HookEngine();
        $this->log = [];
    }

    private function record(string $tag): \Closure
    {
        return function () use ($tag): void {
            $this->log[] = $tag;
        };
    }

    // ---- ordering ---------------------------------------------------------

    public function test_listeners_run_in_priority_order(): void
    {
        $this->hooks->add('customer.created', $this->record('c'), 30);
        $this->hooks->add('customer.created', $this->record('a'), 10);
        $this->hooks->add('customer.created', $this->record('b'), 20);

        $this->hooks->do('customer.created');

        self::assertSame(['a', 'b', 'c'], $this->log);
    }

    /**
     * Equal priorities must be stable, because module registration order is the
     * thing that actually determines them, and that order is deterministic.
     */
    public function test_equal_priorities_run_in_registration_order(): void
    {
        $this->hooks->add('x', $this->record('first'));
        $this->hooks->add('x', $this->record('second'));
        $this->hooks->add('x', $this->record('third'));

        $this->hooks->do('x');

        self::assertSame(['first', 'second', 'third'], $this->log);
    }

    public function test_negative_priorities_run_before_the_default(): void
    {
        $this->hooks->add('x', $this->record('default'));
        $this->hooks->add('x', $this->record('early'), -10);

        $this->hooks->do('x');

        self::assertSame(['early', 'default'], $this->log);
    }

    // ---- arguments --------------------------------------------------------

    public function test_arguments_are_passed_to_every_listener(): void
    {
        $seen = [];

        $this->hooks->add('invoice.paid', static function (int $id, string $currency) use (&$seen): void {
            $seen[] = [$id, $currency];
        });
        $this->hooks->add('invoice.paid', static function (int $id, string $currency) use (&$seen): void {
            $seen[] = [$id * 2, $currency];
        });

        $this->hooks->do('invoice.paid', 7, 'USD');

        self::assertSame([[7, 'USD'], [14, 'USD']], $seen);
    }

    /**
     * Userland callables ignore extra arguments; PHP internals throw. This is
     * the whole reason acceptedArgs exists.
     */
    public function test_accepted_args_limits_what_a_listener_receives(): void
    {
        $seen = null;

        $this->hooks->add('x', static function (...$args) use (&$seen): void {
            $seen = $args;
        }, 10, null, 1);

        $this->hooks->do('x', 'a', 'b', 'c');

        self::assertSame(['a'], $seen);
    }

    public function test_return_values_are_ignored(): void
    {
        $this->hooks->add('x', static fn(): string => 'ignored');
        $this->hooks->add('x', $this->record('ran'), 20);

        $this->hooks->do('x');

        // The first listener returning a value neither stops the chain nor
        // reaches the caller: do() is void by design.
        self::assertSame(['ran'], $this->log);
        self::assertSame(1, $this->hooks->didCount('x'));
    }

    // ---- absence ----------------------------------------------------------

    public function test_firing_a_hook_nobody_listens_to_is_silent(): void
    {
        $this->hooks->do('nobody.listening', 'arg');

        self::assertSame(1, $this->hooks->didCount('nobody.listening'));
        self::assertFalse($this->hooks->has('nobody.listening'));
    }

    public function test_did_count_tracks_every_fire(): void
    {
        self::assertSame(0, $this->hooks->didCount('x'));

        $this->hooks->do('x');
        $this->hooks->do('x');

        self::assertSame(2, $this->hooks->didCount('x'));
    }

    // ---- removal ----------------------------------------------------------

    public function test_a_listener_can_be_removed_by_handle(): void
    {
        $this->hooks->add('x', $this->record('keep'));
        $handle = $this->hooks->add('x', $this->record('drop'));

        self::assertTrue($this->hooks->remove('x', $handle));
        $this->hooks->do('x');

        self::assertSame(['keep'], $this->log);
    }

    public function test_a_listener_can_be_removed_by_its_callable(): void
    {
        $this->hooks->add('x', [HookSpy::class, 'record']);
        $this->hooks->add('x', $this->record('closure'));

        self::assertTrue($this->hooks->remove('x', [HookSpy::class, 'record']));
        $this->hooks->do('x');

        self::assertSame(['closure'], $this->log);
    }

    public function test_a_closure_can_be_removed_by_identity(): void
    {
        $callback = $this->record('drop');
        $this->hooks->add('x', $callback);
        $this->hooks->add('x', $this->record('keep'));

        self::assertTrue($this->hooks->remove('x', $callback));
        $this->hooks->do('x');

        self::assertSame(['keep'], $this->log);
    }

    /**
     * Two identical-looking closures are not the same callback. The handle is
     * the only way to single one of them out, which is why add() returns it.
     */
    public function test_two_identical_looking_closures_are_distinct(): void
    {
        $this->hooks->add('x', static fn(): null => null);
        $handle = $this->hooks->add('x', static fn(): null => null);

        self::assertCount(2, $this->hooks->listeners('x'));
        self::assertTrue($this->hooks->remove('x', $handle));
        self::assertCount(1, $this->hooks->listeners('x'));
    }

    public function test_removal_can_be_scoped_to_a_priority(): void
    {
        $this->hooks->add('x', [HookSpy::class, 'record'], 10);
        $this->hooks->add('x', [HookSpy::class, 'record'], 20);

        self::assertTrue($this->hooks->remove('x', [HookSpy::class, 'record'], 20));
        self::assertCount(1, $this->hooks->listeners('x'));
        self::assertSame(10, $this->hooks->listeners('x')[0]['priority']);
    }

    public function test_removing_something_absent_reports_false(): void
    {
        self::assertFalse($this->hooks->remove('x', 'no-such-handle'));
    }

    public function test_remove_all_clears_one_hook_or_everything(): void
    {
        $this->hooks->add('a', $this->record('a'));
        $this->hooks->add('b', $this->record('b'));

        $this->hooks->removeAll('a');
        self::assertFalse($this->hooks->has('a'));
        self::assertTrue($this->hooks->has('b'));

        $this->hooks->removeAll();
        self::assertFalse($this->hooks->has('b'));
    }

    // ---- introspection ----------------------------------------------------

    public function test_has_can_ask_about_a_specific_callback(): void
    {
        $this->hooks->add('x', [HookSpy::class, 'record']);

        self::assertTrue($this->hooks->has('x'));
        self::assertTrue($this->hooks->has('x', [HookSpy::class, 'record']));
        self::assertFalse($this->hooks->has('x', [HookSpy::class, 'other']));
    }

    public function test_listeners_report_debugging_information(): void
    {
        $this->hooks->add('customer.created', [HookSpy::class, 'record'], 20, 'Example', 1);

        $listeners = $this->hooks->listeners('customer.created');

        self::assertCount(1, $listeners);
        self::assertSame('Example', $listeners[0]['module']);
        self::assertSame(20, $listeners[0]['priority']);
        self::assertSame(HookSpy::class . '::record', $listeners[0]['callback']);
        self::assertSame(1, $listeners[0]['accepted_args']);
        self::assertIsString($listeners[0]['handle']);
    }

    public function test_names_lists_hooks_that_have_listeners(): void
    {
        $this->hooks->add('zeta', $this->record('z'));
        $this->hooks->add('alpha', $this->record('a'));
        $this->hooks->do('never.registered');

        self::assertSame(['alpha', 'zeta'], $this->hooks->names());
    }

    // ---- snapshot semantics ----------------------------------------------

    /**
     * A listener that registers another listener for the hook currently running
     * must not affect that run. WordPress does the opposite. Determinism wins:
     * "did my listener run?" should not depend on registration timing.
     */
    public function test_a_listener_added_during_a_run_does_not_join_it(): void
    {
        $this->hooks->add('x', function (): void {
            $this->log[] = 'first';
            $this->hooks->add('x', $this->record('late'), 1);
        });

        $this->hooks->do('x');
        self::assertSame(['first'], $this->log);

        $this->hooks->do('x');
        self::assertSame(['first', 'late', 'first'], $this->log);
    }

    public function test_a_listener_removed_during_a_run_still_runs_in_it(): void
    {
        // Registered in reverse priority order so the handle exists up front.
        $handle = $this->hooks->add('x', $this->record('second'), 20);

        $this->hooks->add('x', function () use ($handle): void {
            $this->log[] = 'first';
            $this->hooks->remove('x', $handle);
        }, 10);

        $this->hooks->do('x');
        self::assertSame(['first', 'second'], $this->log);

        $this->hooks->do('x');
        self::assertSame(['first', 'second', 'first'], $this->log);
    }

    // ---- recursion --------------------------------------------------------

    public function test_runaway_recursion_throws_rather_than_overflowing_the_stack(): void
    {
        $this->hooks->add('x', function (): void {
            $this->hooks->do('x');
        });

        $this->expectException(HookException::class);
        $this->expectExceptionMessage('still firing');

        $this->hooks->do('x');
    }

    public function test_bounded_nesting_is_allowed(): void
    {
        $depth = 0;

        $this->hooks->add('x', function () use (&$depth): void {
            ++$depth;

            if ($depth < 5) {
                $this->hooks->do('x');
            }
        });

        $this->hooks->do('x');

        self::assertSame(5, $depth);
    }

    public function test_the_depth_counter_unwinds_after_a_breach(): void
    {
        $this->hooks->add('x', function (): void {
            $this->hooks->do('x');
        });

        try {
            $this->hooks->do('x');
        } catch (HookException) {
            // The point is whether the engine is usable afterwards.
        }

        $this->hooks->removeAll('x');
        $this->hooks->add('x', $this->record('ok'));
        $this->hooks->do('x');

        self::assertSame(['ok'], $this->log);
    }

    // ---- the observation seam -----------------------------------------------

    public function test_an_observer_hears_every_listener_with_its_module_and_duration(): void
    {
        $heard = [];
        $this->hooks->observe(static function (string $hook, \App\Engine\Support\Callback $listener, int $ns) use (&$heard): void {
            $heard[] = [$hook, $listener->module, $ns >= 0];
        });

        $this->hooks->add('invoice.paid', $this->record('a'), 10, 'Billing');
        $this->hooks->add('invoice.paid', $this->record('b'), 20, 'Stripe');
        $this->hooks->do('invoice.paid');

        self::assertSame(['a', 'b'], $this->log);
        self::assertSame([
            ['invoice.paid', 'Billing', true],
            ['invoice.paid', 'Stripe', true],
        ], $heard);
    }

    /** The listener that failed is the one somebody diagnosing it most wants timed. */
    public function test_a_listener_that_throws_is_still_observed(): void
    {
        $heard = 0;
        $this->hooks->observe(static function () use (&$heard): void {
            ++$heard;
        });
        $this->hooks->add('x', static function (): never {
            throw new \RuntimeException('listener failed');
        });

        try {
            $this->hooks->do('x');
            self::fail('the exception was swallowed');
        } catch (\RuntimeException) {
        }

        self::assertSame(1, $heard);
    }

    public function test_detaching_the_observer_stops_observation(): void
    {
        $heard = 0;
        $this->hooks->observe(static function () use (&$heard): void {
            ++$heard;
        });
        $this->hooks->add('x', $this->record('ran'));

        $this->hooks->observe(null);
        $this->hooks->do('x');

        self::assertSame(0, $heard);
        self::assertSame(['ran'], $this->log);
    }
}

final class HookSpy
{
    public static function record(): void {}

    public static function other(): void {}
}
