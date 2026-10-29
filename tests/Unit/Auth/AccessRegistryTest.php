<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Engine\Auth\AccessCollector;
use App\Engine\Auth\AccessRegistry;
use App\Engine\Auth\AuthException;
use App\Tests\Support\TestCase;

/**
 * The declarations, and the four mistakes they are checked for.
 *
 * All four are found at boot, and that is the point of the class: an
 * authorization mistake found at runtime is found by whoever was wrongly
 * allowed through, or by nobody.
 */
final class AccessRegistryTest extends TestCase
{
    private AccessRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new AccessRegistry();
    }

    private function collector(string $module = 'Billing'): AccessCollector
    {
        return new AccessCollector($this->registry, $module);
    }

    // ---- declaring ---------------------------------------------------------

    public function test_a_declared_capability_is_known(): void
    {
        $this->collector()->capability('invoice.void', 'Cancel an issued invoice.');

        self::assertTrue($this->registry->hasPermission('invoice.void'));
        self::assertFalse($this->registry->hasPermission('invoice.issue'));

        $permission = $this->registry->permissions()['invoice.void'];

        self::assertSame('Cancel an issued invoice.', $permission->description);
        self::assertSame('Billing', $permission->module, 'who declared it is part of the record');
    }

    public function test_a_declared_role_grants_what_it_says(): void
    {
        $this->collector()->role('clerk', ['invoice.issue']);

        self::assertTrue($this->registry->hasRole('clerk'));
        self::assertSame(['invoice.issue'], $this->registry->grantsFor(['clerk']));
    }

    public function test_the_lists_come_back_sorted(): void
    {
        $this->collector()
            ->capability('zebra.feed')
            ->capability('aardvark.feed')
            ->role('zookeeper')
            ->role('apprentice');

        self::assertSame(['aardvark.feed', 'zebra.feed'], \array_keys($this->registry->permissions()));
        self::assertSame(['apprentice', 'zookeeper'], \array_keys($this->registry->roles()));
    }

    // ---- the four mistakes -------------------------------------------------

    /** Two modules defining one capability means neither can be read alone. */
    public function test_two_modules_cannot_declare_the_same_capability(): void
    {
        $this->collector('Billing')->capability('invoice.void');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Billing already declared');

        $this->collector('Reports')->capability('invoice.void');
    }

    public function test_two_modules_cannot_declare_the_same_role(): void
    {
        $this->collector('shared')->role('clerk');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('shared already declared');

        $this->collector('Billing')->role('clerk');
    }

    public function test_a_role_cannot_inherit_one_nobody_declared(): void
    {
        $this->collector()->role('senior', [], ['junior']);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('is not declared by any module');

        $this->registry->assertConsistent();
    }

    public function test_inheritance_cannot_be_circular(): void
    {
        $this->collector()
            ->role('a', [], ['b'])
            ->role('b', [], ['c'])
            ->role('c', [], ['a']);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('a -> b -> c -> a');

        $this->registry->assertConsistent();
    }

    public function test_a_role_cannot_inherit_itself(): void
    {
        $this->collector()->role('loop', [], ['loop']);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('circular');

        $this->registry->assertConsistent();
    }

    public function test_a_role_cannot_grant_something_that_is_not_a_capability(): void
    {
        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('which is not a capability');

        $this->collector()->role('clerk', ['Invoice Void']);
    }

    public function test_a_role_name_has_to_be_usable(): void
    {
        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Role name');

        $this->collector()->role('Head Of Finance');
    }

    // ---- flattening --------------------------------------------------------

    public function test_inherited_capabilities_are_folded_in(): void
    {
        $this->collector()
            ->role('junior', ['invoice.read'])
            ->role('senior', ['invoice.void'], ['junior']);

        $grants = $this->registry->grantsFor(['senior']);

        \sort($grants);

        self::assertSame(['invoice.read', 'invoice.void'], $grants);
    }

    public function test_inheritance_is_transitive(): void
    {
        $this->collector()
            ->role('a', ['one'])
            ->role('b', ['two'], ['a'])
            ->role('c', ['three'], ['b']);

        $grants = $this->registry->grantsFor(['c']);

        \sort($grants);

        self::assertSame(['one', 'three', 'two'], $grants);
    }

    /** A diamond is not a cycle, and each capability appears once. */
    public function test_a_capability_reached_twice_appears_once(): void
    {
        $this->collector()
            ->role('base', ['shared.thing'])
            ->role('left', [], ['base'])
            ->role('right', [], ['base'])
            ->role('top', [], ['left', 'right']);

        $this->registry->assertConsistent();

        self::assertSame(['shared.thing'], $this->registry->grantsFor(['top']));
    }

    public function test_several_roles_are_unioned(): void
    {
        $this->collector()
            ->role('reader', ['invoice.read'])
            ->role('writer', ['invoice.write']);

        $grants = $this->registry->grantsFor(['reader', 'writer']);

        \sort($grants);

        self::assertSame(['invoice.read', 'invoice.write'], $grants);
    }

    /**
     * A role name that no longer exists grants nothing, and does not throw.
     *
     * Role names usually come from storage. A row naming a role that a
     * since-removed module used to declare must mean "grants nothing", not
     * "the site is down" -- which is the opposite of how the DECLARATIONS are
     * treated, because those come from code somebody is editing.
     */
    public function test_an_unknown_role_grants_nothing_quietly(): void
    {
        $this->collector()->role('known', ['a.b']);

        self::assertSame(['a.b'], $this->registry->grantsFor(['known', 'deleted-last-year']));
        self::assertSame([], $this->registry->grantsFor(['nothing-at-all']));
    }

    public function test_a_consistent_model_passes(): void
    {
        $this->collector()
            ->capability('invoice.void')
            ->role('junior')
            ->role('senior', ['invoice.void'], ['junior']);

        $this->registry->assertConsistent();

        self::assertTrue($this->registry->hasRole('senior'));
    }
}
