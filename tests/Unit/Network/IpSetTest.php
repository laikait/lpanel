<?php

declare(strict_types=1);

namespace App\Tests\Unit\Network;

use App\Engine\Network\Cidr;
use App\Engine\Network\IpAddress;
use App\Engine\Network\IpException;
use App\Engine\Network\IpSet;
use App\Tests\Support\TestCase;

final class IpSetTest extends TestCase
{
    public function test_a_set_matches_addresses_and_blocks_of_either_version(): void
    {
        $set = new IpSet(['203.0.113.0/24', '198.51.100.7', '2001:db8::/32', IpAddress::parse('::1'), Cidr::parse('10.0.0.0/8')]);

        self::assertTrue($set->contains('203.0.113.200'));
        self::assertTrue($set->contains('198.51.100.7'));
        self::assertFalse($set->contains('198.51.100.8'));
        self::assertTrue($set->contains('2001:db8:1::1'));
        self::assertTrue($set->contains(IpAddress::parse('::1')));
        self::assertTrue($set->contains('::ffff:10.2.3.4'));
        self::assertFalse($set->contains('8.8.8.8'));
        self::assertFalse($set->contains('garbage'));
        self::assertFalse($set->contains(null));
        self::assertCount(5, $set);
        self::assertFalse($set->isEmpty());
        self::assertSame('198.51.100.7/32', $set->blocks()[1]->toString());
    }

    public function test_an_empty_set_matches_nothing(): void
    {
        $set = new IpSet();

        self::assertTrue($set->isEmpty());
        self::assertFalse($set->contains('10.0.0.1'));
    }

    public function test_an_unreadable_entry_fails_when_the_set_is_built(): void
    {
        $this->expectException(IpException::class);
        new IpSet(['10.0.0.0/8', '10.0.0.300']);
    }

    public function test_a_lenient_set_drops_what_it_cannot_read(): void
    {
        $set = IpSet::lenient(['10.0.0.0/8', '10.0.0.300', ' 192.168.0.1 ']);

        self::assertCount(2, $set);
        self::assertTrue($set->contains('192.168.0.1'));
    }

    public function test_a_set_is_iterable(): void
    {
        $blocks = [];

        foreach (new IpSet(['10.0.0.0/8', '::1']) as $block) {
            $blocks[] = $block->toString();
        }

        self::assertSame(['10.0.0.0/8', '::1/128'], $blocks);
    }
}
