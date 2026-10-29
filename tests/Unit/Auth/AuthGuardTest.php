<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Engine\Auth\AuthGuard;
use App\Engine\Routing\Route;
use App\Tests\Support\TestCase;

/**
 * Reading what a route asks for.
 *
 * Small and worth having on its own: every decision the guard makes starts
 * here, and "the metadata was spelled slightly differently" is the failure that
 * turns a protected route into a public one without anything looking wrong.
 */
final class AuthGuardTest extends TestCase
{
    /** @param array<string, mixed> $meta */
    private function route(array $meta): Route
    {
        return (new Route('GET', '/x', static fn(): string => 'x'))->meta($meta);
    }

    public function test_one_capability_may_be_written_as_a_string(): void
    {
        self::assertSame(['invoice.void'], AuthGuard::capabilitiesFor($this->route(['can' => 'invoice.void'])));
    }

    public function test_several_may_be_written_as_a_list(): void
    {
        self::assertSame(
            ['invoice.void', 'invoice.issue'],
            AuthGuard::capabilitiesFor($this->route(['can' => ['invoice.void', 'invoice.issue']])),
        );
    }

    public function test_nothing_declared_is_no_capabilities(): void
    {
        self::assertSame([], AuthGuard::capabilitiesFor($this->route([])));
        self::assertSame([], AuthGuard::capabilitiesFor($this->route(['can' => ''])));
        self::assertSame([], AuthGuard::capabilitiesFor($this->route(['can' => null])));
        self::assertSame([], AuthGuard::capabilitiesFor($this->route(['can' => true])));
    }

    public function test_rubbish_inside_a_list_is_ignored_rather_than_believed(): void
    {
        self::assertSame(
            ['invoice.void'],
            AuthGuard::capabilitiesFor($this->route(['can' => ['invoice.void', '', null, 7]])),
        );
    }

    /** A capability implies a login: checking one against a guest is a login prompt. */
    public function test_a_capability_makes_a_route_protected_without_saying_auth(): void
    {
        self::assertTrue(AuthGuard::isProtected($this->route(['can' => 'invoice.void'])));
        self::assertTrue(AuthGuard::isProtected($this->route(['auth' => true])));
        self::assertFalse(AuthGuard::isProtected($this->route([])));
    }

    /**
     * Only `true` means true.
     *
     * 'auth' => 'yes' is somebody believing they protected a route. Treating a
     * truthy value as protection would make the same mistake silently, but so
     * would treating it as public -- which is why route:list and
     * security:check both print what each route actually resolved to.
     */
    public function test_auth_is_only_on_when_it_is_exactly_true(): void
    {
        self::assertFalse(AuthGuard::isProtected($this->route(['auth' => 'yes'])));
        self::assertFalse(AuthGuard::isProtected($this->route(['auth' => 1])));
        self::assertFalse(AuthGuard::isProtected($this->route(['auth' => false])));
    }
}
