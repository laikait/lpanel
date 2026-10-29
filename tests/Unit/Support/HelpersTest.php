<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Support\Extensions;
use App\Tests\Support\TestCase;

final class HelpersTest extends TestCase
{
    private HookEngine $hooks;

    private FilterEngine $filters;

    protected function setUp(): void
    {
        $this->hooks = new HookEngine();
        $this->filters = new FilterEngine();

        Extensions::init($this->hooks, $this->filters);
    }

    protected function tearDown(): void
    {
        Extensions::reset();
    }

    // ---- hooks ------------------------------------------------------------

    public function test_add_hook_registers_on_the_wired_engine(): void
    {
        $handle = add_hook('customer.created', static fn(): null => null, 20);

        self::assertTrue($this->hooks->has('customer.created'));
        self::assertSame(20, $this->hooks->listeners('customer.created')[0]['priority']);
        self::assertSame($handle, $this->hooks->listeners('customer.created')[0]['handle']);
    }

    public function test_do_hook_fires_on_the_wired_engine(): void
    {
        $seen = [];

        $this->hooks->add('customer.created', static function (string $name) use (&$seen): void {
            $seen[] = $name;
        });

        do_hook('customer.created', 'Ada');

        self::assertSame(['Ada'], $seen);
    }

    public function test_has_hook_and_remove_hook_delegate(): void
    {
        $handle = add_hook('x', static fn(): null => null);

        self::assertTrue(has_hook('x'));
        self::assertTrue(remove_hook('x', $handle));
        self::assertFalse(has_hook('x'));
    }

    public function test_add_hook_accepts_an_accepted_args_limit(): void
    {
        $seen = null;

        add_hook('x', static function (...$args) use (&$seen): void {
            $seen = $args;
        }, 10, 1);

        do_hook('x', 'a', 'b');

        self::assertSame(['a'], $seen);
    }

    // ---- filters ----------------------------------------------------------

    public function test_add_filter_and_apply_filter_delegate(): void
    {
        add_filter('invoice.total', static fn(int $t): int => $t * 2);

        self::assertSame(20, apply_filter('invoice.total', 10));
    }

    public function test_apply_filter_passes_context_arguments(): void
    {
        add_filter('customer.name', static fn(string $n, string $suffix): string => $n . $suffix);

        self::assertSame('Ada!', apply_filter('customer.name', 'Ada', '!'));
    }

    public function test_has_filter_and_remove_filter_delegate(): void
    {
        $handle = add_filter('x', static fn(mixed $v): mixed => $v);

        self::assertTrue(has_filter('x'));
        self::assertTrue(remove_filter('x', $handle));
        self::assertFalse(has_filter('x'));
    }

    public function test_apply_filter_on_an_unregistered_name_returns_the_value(): void
    {
        self::assertSame('untouched', apply_filter('nothing.listens', 'untouched'));
    }

    // ---- the wiring itself ------------------------------------------------

    /**
     * The helpers must be a bridge to injected engines, not ambient global
     * state. If the engines are gone, calling one is a clear error rather than
     * a silent no-op against a hidden singleton.
     */
    public function test_a_helper_used_before_bootstrap_fails_loudly(): void
    {
        Extensions::reset();

        self::assertFalse(Extensions::isInitialised());

        try {
            add_hook('x', static fn(): null => null);
            self::fail('expected a LogicException');
        } catch (\LogicException $e) {
            self::assertStringContainsString('before the application was bootstrapped', $e->getMessage());
        }
    }

    public function test_the_filter_helpers_fail_loudly_too(): void
    {
        Extensions::reset();

        $this->expectException(\LogicException::class);

        apply_filter('x', 'value');
    }

    public function test_init_rebinds_the_helpers_to_new_engines(): void
    {
        add_hook('x', static fn(): null => null);
        self::assertTrue(has_hook('x'));

        Extensions::init(new HookEngine(), new FilterEngine());

        self::assertFalse(has_hook('x'));
    }

    public function test_every_helper_is_guarded_against_redeclaration(): void
    {
        $source = \file_get_contents($this->basePath('engine/Support/helpers.php'));
        self::assertIsString($source);

        // helpers.php lives in the global namespace, so function_exists may
        // legitimately appear there with or without a leading separator.
        $normalised = \str_replace('\\', '', $source);

        foreach (self::PERMITTED as $function) {
            self::assertStringContainsString(
                \sprintf("!function_exists('%s')", $function),
                $normalised,
                \sprintf('%s() is not guarded; loading the framework would fatal an app that defines it.', $function),
            );
        }
    }

    /**
     * The closed set, and the gate on growing it.
     *
     * A name belongs here only when the specification says application authors
     * reach that subsystem globally -- the hook and filter helpers, asset() and
     * template(). Everything else in the framework is injected.
     *
     * The list is now complete: the specification mandates no global beyond
     * these. Changing this array is a design decision with a written
     * justification, not a line in somebody's commit.
     */
    public const PERMITTED = [
        'add_hook',
        'do_hook',
        'remove_hook',
        'has_hook',
        'add_filter',
        'apply_filter',
        'remove_filter',
        'has_filter',
        'asset',
        'template',
    ];

    public function test_every_permitted_helper_exists(): void
    {
        foreach (self::PERMITTED as $function) {
            self::assertTrue(\function_exists($function), $function . '() is missing');
        }
    }
}
