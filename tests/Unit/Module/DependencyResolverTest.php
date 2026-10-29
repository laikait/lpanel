<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module;

use App\Engine\Module\DependencyResolver;
use App\Engine\Module\ModuleContext;
use App\Engine\Module\ModuleDefinition;
use App\Engine\Module\ModuleException;
use App\Engine\Module\ModuleStage;
use App\Tests\Support\TestCase;

/**
 * The order modules register in, and the five ways that order cannot exist.
 *
 * Built from contexts in memory rather than fixture directories, so each test
 * says its whole scenario in a few lines: which modules exist, what they
 * declare, and what the answer must be.
 */
final class DependencyResolverTest extends TestCase
{
    /**
     * A module as the resolver sees one: loaded, versioned, with declarations.
     *
     * @param array<string, string> $requires   id => constraint
     * @param array<string, string> $optionally id => constraint
     */
    private function module(
        string $id,
        ?string $version = '1.0.0',
        array $requires = [],
        array $optionally = [],
    ): ModuleContext {
        $context = new ModuleContext(ModuleDefinition::create('/modules/' . $id, $id));
        $context->enterStage(ModuleStage::Loading);

        if ($version !== null) {
            $context->version($version);
        }

        foreach ($requires as $target => $constraint) {
            $context->requires($target, $constraint);
        }

        foreach ($optionally as $target => $constraint) {
            $context->optionally($target, $constraint);
        }

        return $context;
    }

    /**
     * @param list<ModuleContext> $modules
     * @param list<string>        $disabled
     *
     * @return list<string>
     */
    private function resolve(array $modules, array $disabled = []): array
    {
        return (new DependencyResolver())->resolve($modules, $disabled);
    }

    // ---- ordering ----------------------------------------------------------

    /** An application that declares nothing registers exactly as it always did. */
    public function test_with_no_declarations_the_order_is_unchanged(): void
    {
        self::assertSame(
            ['Shared', 'Alpha', 'Beta', 'Zeta'],
            $this->resolve([
                $this->module('Shared'),
                $this->module('Alpha'),
                $this->module('Beta'),
                $this->module('Zeta'),
            ]),
        );
    }

    public function test_a_module_registers_after_what_it_requires(): void
    {
        self::assertSame(
            ['Shared', 'Billing', 'Accounts'],
            $this->resolve([
                $this->module('Shared'),
                $this->module('Accounts', requires: ['Billing' => '^1.0']),
                $this->module('Billing'),
            ]),
        );
    }

    /**
     * The stable part of the stable sort.
     *
     * Only the module that has to wait moves. Everything with no reason to
     * change position keeps it, so adding one declaration does not reshuffle
     * the registration order of modules that never mentioned it.
     */
    public function test_only_the_module_that_has_to_wait_moves(): void
    {
        self::assertSame(
            ['Beta', 'Charlie', 'Delta', 'Alpha', 'Echo'],
            $this->resolve([
                $this->module('Alpha', requires: ['Delta' => '*']),
                $this->module('Beta'),
                $this->module('Charlie'),
                $this->module('Delta'),
                $this->module('Echo'),
            ]),
        );
    }

    /** The specification's own example, end to end. */
    public function test_a_chain_resolves_in_dependency_order(): void
    {
        self::assertSame(
            ['Shared', 'Billing', 'Payment', 'Stripe'],
            $this->resolve([
                $this->module('Shared'),
                $this->module('Billing', requires: ['Shared' => '^1.0']),
                $this->module('Payment', requires: ['Billing' => '^1.0']),
                $this->module('Stripe', requires: ['Payment' => '^1.0']),
            ]),
        );
    }

    /**
     * Shared first, whatever is declared.
     *
     * This is what lets every module rely on Shared without declaring it, and
     * it holds by construction: Shared may depend on nothing, so the sort can
     * only ever move the other modules among themselves.
     */
    public function test_shared_is_always_first(): void
    {
        $order = $this->resolve([
            $this->module('Shared'),
            $this->module('Alpha', requires: ['Zulu' => '*']),
            $this->module('Zulu'),
            $this->module('Aardvark', requires: ['Zulu' => '*']),
        ]);

        self::assertSame('Shared', $order[0]);
        self::assertSame('Aardvark', $order[3]);
        self::assertSame(['Zulu', 'Alpha'], \array_slice($order, 1, 2));
    }

    public function test_an_optional_dependency_that_is_present_orders_registration(): void
    {
        self::assertSame(
            ['Crm', 'Accounts'],
            $this->resolve([
                $this->module('Accounts', optionally: ['Crm' => '^1.0']),
                $this->module('Crm'),
            ]),
        );
    }

    public function test_the_same_declarations_always_give_the_same_order(): void
    {
        $modules = [
            $this->module('Shared'),
            $this->module('C', requires: ['A' => '*']),
            $this->module('A', requires: ['B' => '*']),
            $this->module('B'),
        ];

        $first = $this->resolve($modules);

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame($first, $this->resolve($modules));
        }
    }

    // ---- missing -------------------------------------------------------------

    public function test_a_missing_required_module_refuses_to_boot(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Module "Payment" requires "Billing ^1.0", which is not installed');

        $this->resolve([
            $this->module('Payment', requires: ['Billing' => '^1.0']),
        ]);
    }

    public function test_a_likely_typo_gets_a_suggestion(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Did you mean "Billing"?');

        $this->resolve([
            $this->module('Billing'),
            $this->module('Payment', requires: ['Biling' => '*']),
        ]);
    }

    public function test_a_missing_optional_module_is_simply_absent(): void
    {
        self::assertSame(
            ['Accounts'],
            $this->resolve([$this->module('Accounts', optionally: ['Crm' => '^1.0'])]),
        );
    }

    // ---- disabled ------------------------------------------------------------

    /**
     * Disabled is not missing, and the message says which.
     *
     * "Not installed" sends somebody to composer or a deploy script; "disabled"
     * sends them to one line of configuration. The fixes are different, so the
     * errors are.
     */
    public function test_a_disabled_required_module_says_disabled_not_missing(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('installed but disabled');

        $this->resolve(
            [$this->module('Payment', requires: ['Billing' => '*'])],
            ['Billing'],
        );
    }

    public function test_a_disabled_optional_module_is_simply_absent(): void
    {
        self::assertSame(
            ['Accounts'],
            $this->resolve(
                [$this->module('Accounts', optionally: ['Crm' => '*'])],
                ['Crm'],
            ),
        );
    }

    // ---- versions ------------------------------------------------------------

    public function test_a_version_that_does_not_fit_refuses_to_boot(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('requires "Billing ^2.0", but the installed "Billing" is 1.4.0');

        $this->resolve([
            $this->module('Billing', '1.4.0'),
            $this->module('Payment', requires: ['Billing' => '^2.0']),
        ]);
    }

    /**
     * Present optional modules are held to their constraint.
     *
     * Optional means "works without it", not "works with any version of it":
     * an integration written for Crm 1.x that is handed Crm 2.0 is the failure
     * the constraint exists to stop.
     */
    public function test_an_optional_module_that_is_present_must_still_fit(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('requires "Crm ^1.0"');

        $this->resolve([
            $this->module('Accounts', optionally: ['Crm' => '^1.0']),
            $this->module('Crm', '2.0.0'),
        ]);
    }

    public function test_a_module_with_no_version_is_told_to_declare_one(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('declares no version at all');

        $this->resolve([
            $this->module('Billing', null),
            $this->module('Payment', requires: ['Billing' => '^1.0']),
        ]);
    }

    public function test_any_version_accepts_a_module_that_declared_none(): void
    {
        self::assertSame(
            ['Billing', 'Payment'],
            $this->resolve([
                $this->module('Billing', null),
                $this->module('Payment', requires: ['Billing' => '*']),
            ]),
        );
    }

    // ---- cycles --------------------------------------------------------------

    public function test_a_circle_is_refused_and_the_circle_is_named(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('A -> B -> C -> A');

        $this->resolve([
            $this->module('A', requires: ['B' => '*']),
            $this->module('B', requires: ['C' => '*']),
            $this->module('C', requires: ['A' => '*']),
        ]);
    }

    /** Optional dependencies order registration, so they can close a circle too. */
    public function test_an_optional_dependency_can_close_a_circle(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('in a circle');

        $this->resolve([
            $this->module('A', requires: ['B' => '*']),
            $this->module('B', optionally: ['A' => '*']),
        ]);
    }

    public function test_a_module_cannot_require_itself(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('A -> A');

        $this->resolve([$this->module('A', requires: ['A' => '*'])]);
    }

    /** A circle among some modules does not hide behind the ones that resolve. */
    public function test_a_circle_is_found_among_modules_that_are_fine(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('C -> D -> C');

        $this->resolve([
            $this->module('Shared'),
            $this->module('A'),
            $this->module('B', requires: ['A' => '*']),
            $this->module('C', requires: ['D' => '*', 'A' => '*']),
            $this->module('D', requires: ['C' => '*']),
        ]);
    }

    // ---- against kind ----------------------------------------------------------

    /** Shared is what everything else may rely on; it relies on nothing. */
    public function test_shared_cannot_depend_on_anything(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Module "Shared" depends on "Billing"');

        $this->resolve([
            $this->module('Shared', requires: ['Billing' => '*']),
            $this->module('Billing'),
        ]);
    }

    public function test_any_module_may_depend_on_any_other(): void
    {
        self::assertSame(
            ['Payment', 'Base', 'Stripe'],
            $this->resolve([
                $this->module('Payment'),
                $this->module('Base', requires: ['Payment' => '*']),
                $this->module('Stripe', requires: ['Base' => '*', 'Payment' => '*']),
            ]),
        );
    }
}
