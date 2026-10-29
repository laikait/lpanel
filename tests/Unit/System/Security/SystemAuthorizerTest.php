<?php

declare(strict_types=1);

namespace App\Tests\Unit\System\Security;

use App\Engine\Auth\AccessCollector;
use App\Engine\Auth\AccessRegistry;
use App\Engine\Auth\AuthException;
use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Capability;
use App\Engine\Auth\Identity;
use App\Engine\Filter\FilterEngine;
use App\Engine\System\Security\SystemAuthorizationException;
use App\Engine\System\Security\SystemAuthorizer;
use App\Engine\System\Security\SystemCapability;
use App\Engine\System\Security\SystemOperation;
use App\Tests\Support\TestCase;

final class SystemAuthorizerTest extends TestCase
{
    private AccessRegistry $access;

    private FilterEngine $filters;

    private SystemAuthorizer $system;

    protected function setUp(): void
    {
        $this->access = new AccessRegistry();
        $this->filters = new FilterEngine();

        $collector = new AccessCollector($this->access, 'Server');
        SystemCapability::declare(
            $collector,
            SystemCapability::ServiceRead,
            SystemCapability::ServiceRestart,
            SystemCapability::ServiceStop,
            SystemCapability::ShellExecute,
        );
        $collector
            ->role('viewer', [SystemCapability::ServiceRead->value])
            ->role('operator', [SystemCapability::ServiceRestart->value], ['viewer'])
            ->role('everything-system', ['system.*']);

        $this->system = new SystemAuthorizer(new Authorizer($this->access, $this->filters));
    }

    private function identity(string ...$roles): Identity
    {
        return new Identity('7', 'ada', \array_values($roles));
    }

    public function test_a_role_grants_exactly_the_capabilities_it_names(): void
    {
        $operator = $this->identity('operator');

        self::assertTrue($this->system->allows($operator, SystemCapability::ServiceRestart, 'nginx.service'));
        self::assertTrue($this->system->allows($operator, SystemCapability::ServiceRead), 'inherited from viewer');
        self::assertFalse($this->system->allows($operator, SystemCapability::ServiceStop, 'ssh.service'));
        self::assertFalse($this->system->allows($this->identity('viewer'), SystemCapability::ServiceRestart, 'nginx.service'));
    }

    /**
     * The plan's example, answered the auth model's way: roles grant the
     * operation, and a module narrows it by target. The narrowing can only
     * refuse.
     */
    public function test_a_module_can_narrow_a_capability_to_targets_and_cannot_widen_one(): void
    {
        $this->filters->add(Authorizer::DECISION_FILTER, static fn(bool $allowed, Capability $capability, Identity $who, mixed $subject): bool => $subject instanceof SystemOperation
            && $subject->capability === SystemCapability::ServiceRestart
            && $subject->target !== 'nginx.service' ? false : $allowed);

        // A listener that tries to grant.
        $this->filters->add(Authorizer::DECISION_FILTER, static fn(bool $allowed): bool => true, 5);

        $operator = $this->identity('operator');

        self::assertTrue($this->system->allows($operator, SystemCapability::ServiceRestart, 'nginx.service'));
        self::assertFalse($this->system->allows($operator, SystemCapability::ServiceRestart, 'php8.3-fpm.service'));
        self::assertFalse($this->system->allows($this->identity('viewer'), SystemCapability::ServiceStop, 'ssh.service'));
    }

    public function test_the_filter_receives_the_operation_with_its_target(): void
    {
        $seen = null;
        $this->filters->add(Authorizer::DECISION_FILTER, static function (bool $allowed, Capability $capability, Identity $who, mixed $subject) use (&$seen): bool {
            $seen = $subject;

            return $allowed;
        });

        $this->system->allows($this->identity('operator'), SystemCapability::ServiceRestart, 'nginx.service');

        self::assertInstanceOf(SystemOperation::class, $seen);
        self::assertSame(SystemCapability::ServiceRestart, $seen->capability);
        self::assertSame('nginx.service', $seen->target);
    }

    public function test_authorize_refuses_a_known_identity_and_a_guest_differently(): void
    {
        try {
            $this->system->authorize($this->identity('viewer'), SystemCapability::ServiceStop, 'ssh.service');
            self::fail('no exception');
        } catch (SystemAuthorizationException $e) {
            self::assertSame('This identity may not system.service.stop on "ssh.service".', $e->getMessage());
            self::assertFalse($e->guest);
            self::assertSame('ssh.service', $e->operation->target);
        }

        try {
            $this->system->authorize(Identity::guest(), SystemCapability::ServiceRead);
            self::fail('no exception');
        } catch (SystemAuthorizationException $e) {
            self::assertSame('Authentication is required for system.service.read.', $e->getMessage());
            self::assertTrue($e->guest);
        }
    }

    public function test_authorize_passes_silently_when_allowed(): void
    {
        $this->system->authorize($this->identity('operator'), SystemCapability::ServiceRestart, 'nginx.service');

        $this->addToAssertionCount(1);
    }

    /** Checking a capability nobody declared is a mistake to hear about, not a quiet no. */
    public function test_an_undeclared_capability_is_an_error_not_a_refusal(): void
    {
        $this->expectException(AuthException::class);

        $this->system->allows($this->identity('operator'), SystemCapability::FilesystemDelete, '/srv/x');
    }

    /** The documented danger of a wildcard grant, pinned so nobody is surprised by it. */
    public function test_a_system_wildcard_includes_the_shell(): void
    {
        self::assertTrue($this->system->allows($this->identity('everything-system'), SystemCapability::ShellExecute));
    }

    public function test_every_capability_is_askable_described_and_under_system(): void
    {
        foreach (SystemCapability::cases() as $capability) {
            self::assertTrue(Capability::isAskable($capability->value), $capability->value);
            self::assertStringStartsWith('system.', $capability->value);
            self::assertNotSame('', $capability->description());
        }
    }

    public function test_declaring_records_the_description_for_the_access_list(): void
    {
        $permissions = [];

        foreach ($this->access->permissions() as $permission) {
            $permissions[$permission->capability] = [$permission->description, $permission->module];
        }

        self::assertSame(
            [SystemCapability::ServiceStop->description(), 'Server'],
            $permissions['system.service.stop'] ?? null,
        );
        self::assertArrayNotHasKey('system.info.read', $permissions, 'only what a module declares exists');
    }
}
