<?php

declare(strict_types=1);

namespace App\Tests\Unit\System\Service;

use App\Engine\System\Service\ServiceStatus;
use App\Tests\Support\TestCase;

final class ServiceStatusTest extends TestCase
{
    public function test_a_running_enabled_service(): void
    {
        $status = ServiceStatus::fromShow('nginx.service', "LoadState=loaded\nActiveState=active\nSubState=running\nUnitFileState=enabled\n");

        self::assertSame('nginx.service', $status->unit);
        self::assertTrue($status->exists());
        self::assertTrue($status->isActive());
        self::assertFalse($status->isFailed());
        self::assertTrue($status->isEnabled());
        self::assertSame('running', $status->subState);
    }

    public function test_a_unit_systemd_does_not_know(): void
    {
        $status = ServiceStatus::fromShow('nope.service', "LoadState=not-found\nActiveState=inactive\nSubState=dead\nUnitFileState=\n");

        self::assertFalse($status->exists());
        self::assertFalse($status->isActive());
        self::assertSame('', $status->unitFileState);
    }

    public function test_a_failed_service_keeps_systemds_own_words(): void
    {
        $status = ServiceStatus::fromShow('app.service', "LoadState=loaded\r\nActiveState=failed\r\nSubState=failed\r\nUnitFileState=disabled\r\n");

        self::assertTrue($status->isFailed());
        self::assertFalse($status->isEnabled());
        self::assertSame('failed', $status->activeState);
    }

    public function test_output_without_the_properties_is_a_status_that_says_nothing(): void
    {
        $status = ServiceStatus::fromShow('x.service', "garbage\nA=b=c\n");

        self::assertFalse($status->exists());
        self::assertSame('', $status->activeState);
    }
}
