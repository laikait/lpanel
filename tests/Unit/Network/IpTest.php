<?php

declare(strict_types=1);

namespace App\Tests\Unit\Network;

use App\Engine\Network\Ip;
use App\Engine\Network\IpException;
use App\Engine\Network\IpVersion;
use App\Tests\Support\TestCase;

final class IpTest extends TestCase
{
    public function test_the_predicates(): void
    {
        self::assertTrue(Ip::isValid('10.0.0.1'));
        self::assertFalse(Ip::isValid('10.0.0'));
        self::assertTrue(Ip::isV4('10.0.0.1'));
        self::assertFalse(Ip::isV4('::1'));
        self::assertTrue(Ip::isV6('::1'));
        self::assertFalse(Ip::isV6('10.0.0.1'));
        self::assertSame(IpVersion::V6, Ip::version('::1'));
        self::assertNull(Ip::version('nope'));
        self::assertTrue(Ip::isPrivate('192.168.1.1'));
        self::assertTrue(Ip::isLoopback('127.0.0.1'));
        self::assertTrue(Ip::isPublic('8.8.8.8'));
        self::assertFalse(Ip::isPublic('192.168.1.1'));
    }

    /** "Is this public" of garbage is "no", not an exception. */
    public function test_a_predicate_of_garbage_is_false(): void
    {
        self::assertFalse(Ip::isPrivate('garbage'));
        self::assertFalse(Ip::isLoopback('garbage'));
        self::assertFalse(Ip::isPublic('garbage'));
        self::assertFalse(Ip::inRange('garbage', '0.0.0.0/0'));
    }

    public function test_range_membership(): void
    {
        self::assertTrue(Ip::inRange('10.1.2.3', '10.0.0.0/8'));
        self::assertFalse(Ip::inRange('11.1.2.3', '10.0.0.0/8'));
        self::assertTrue(Ip::inRange('10.1.2.3', ['::1', '10.0.0.0/8']));
        self::assertTrue(Ip::inRange('2001:db8::5', ['2001:db8::/32']));
    }

    public function test_an_unreadable_range_is_a_bug_not_a_no(): void
    {
        $this->expectException(IpException::class);
        Ip::inRange('10.0.0.1', '10.0.0.0/33');
    }

    public function test_the_transformations(): void
    {
        self::assertSame('::1', Ip::normalize('0:0:0:0:0:0:0:1'));
        self::assertNull(Ip::normalize('nope'));
        self::assertSame('192.168.1.0', Ip::mask('192.168.1.77', 24));
        self::assertSame('203.0.113.0', Ip::anonymize('203.0.113.77'));
        self::assertSame('2001:db8:1::', Ip::anonymize('2001:db8:1:2::1'));
        self::assertSame(['192.168.1.0', '192.168.1.1', '192.168.1.2', '192.168.1.3'], Ip::range('192.168.1.0/30'));
        self::assertSame(['192.168.1.1', '192.168.1.2'], Ip::range('192.168.1.0/30', hostsOnly: true));
        self::assertSame(\inet_pton('::1'), Ip::toBinary('::1'));
        self::assertNull(Ip::toBinary('nope'));
    }

    public function test_transforming_garbage_throws(): void
    {
        $this->expectException(IpException::class);
        Ip::mask('garbage', 24);
    }
}
