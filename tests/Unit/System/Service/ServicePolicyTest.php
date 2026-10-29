<?php

declare(strict_types=1);

namespace App\Tests\Unit\System\Service;

use App\Engine\System\Service\ServiceAction;
use App\Engine\System\Service\ServiceException;
use App\Engine\System\Service\ServicePolicy;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ServicePolicyTest extends TestCase
{
    public function test_nothing_is_permitted_until_it_is_allowed(): void
    {
        $policy = ServicePolicy::none();

        foreach (ServiceAction::cases() as $action) {
            self::assertFalse($policy->permits('nginx', $action));
        }
    }

    /** The plan's own example: restart nginx does not imply stop ssh -- or stop nginx. */
    public function test_an_allowed_action_on_a_service_implies_nothing_else(): void
    {
        $policy = ServicePolicy::none()
            ->allow('nginx', ServiceAction::Restart)
            ->allow('php8.3-fpm', ServiceAction::Restart, ServiceAction::Reload);

        self::assertTrue($policy->permits('nginx', ServiceAction::Restart));
        self::assertFalse($policy->permits('nginx', ServiceAction::Stop));
        self::assertFalse($policy->permits('ssh', ServiceAction::Stop));
        self::assertFalse($policy->permits('ssh', ServiceAction::Restart));
        self::assertTrue($policy->permits('php8.3-fpm', ServiceAction::Reload));
    }

    public function test_a_name_and_its_unit_are_the_same_service(): void
    {
        $policy = ServicePolicy::none()->allow('nginx.service', ServiceAction::Reload);

        self::assertTrue($policy->permits('nginx', ServiceAction::Reload));
        self::assertSame(['nginx.service' => [ServiceAction::Reload]], $policy->allowed());
    }

    public function test_allowing_returns_a_new_policy_and_does_not_repeat_actions(): void
    {
        $none = ServicePolicy::none();
        $some = $none->allow('nginx', ServiceAction::Reload, ServiceAction::Reload)->allow('nginx', ServiceAction::Reload);

        self::assertSame([], $none->allowed());
        self::assertSame(['nginx.service' => [ServiceAction::Reload]], $some->allowed());
    }

    /** @return iterable<string, array{string, string}> */
    public static function units(): iterable
    {
        yield 'bare name' => ['nginx', 'nginx.service'];
        yield 'with suffix' => ['nginx.service', 'nginx.service'];
        yield 'versioned' => ['php8.3-fpm', 'php8.3-fpm.service'];
        yield 'template instance' => ['getty@tty1', 'getty@tty1.service'];
    }

    #[DataProvider('units')]
    public function test_a_service_name_becomes_its_unit(string $name, string $unit): void
    {
        self::assertSame($unit, ServicePolicy::unit($name));
    }

    /** @return iterable<string, array{string}> */
    public static function notServices(): iterable
    {
        yield 'empty' => [''];
        yield 'an option' => ['-H'];
        yield 'a long option' => ['--now'];
        yield 'poweroff' => ['poweroff.target'];
        yield 'multi-user target' => ['multi-user.target'];
        yield 'a socket' => ['nginx.socket'];
        yield 'a timer' => ['logrotate.timer'];
        yield 'a mount' => ['home.mount'];
        yield 'a space' => ['nginx reload'];
        yield 'shell syntax' => ['nginx;reboot'];
        yield 'a path' => ['../../etc/passwd'];
        yield 'a glob' => ['*'];
        yield 'too long' => [\str_repeat('a', 300)];
    }

    #[DataProvider('notServices')]
    public function test_anything_but_a_service_unit_is_refused(string $name): void
    {
        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('The service name is invalid');

        ServicePolicy::unit($name);
    }
}
