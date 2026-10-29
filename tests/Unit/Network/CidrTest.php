<?php

declare(strict_types=1);

namespace App\Tests\Unit\Network;

use App\Engine\Network\Cidr;
use App\Engine\Network\IpAddress;
use App\Engine\Network\IpException;
use App\Engine\Network\IpVersion;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CidrTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string, string, string, string, string, ?string}>
     */
    public static function blocks(): array
    {
        return [
            // input, canonical, netmask, first, last, first host, last host, size, broadcast
            '/24' => ['192.168.1.0/24', '192.168.1.0/24', '255.255.255.0', '192.168.1.0', '192.168.1.255', '192.168.1.1', '192.168.1.254', '256', '192.168.1.255'],
            'host bits forgiven' => ['192.168.1.77/24', '192.168.1.0/24', '255.255.255.0', '192.168.1.0', '192.168.1.255', '192.168.1.1', '192.168.1.254', '256', '192.168.1.255'],
            '/0' => ['0.0.0.0/0', '0.0.0.0/0', '0.0.0.0', '0.0.0.0', '255.255.255.255', '0.0.0.1', '255.255.255.254', '4294967296', '255.255.255.255'],
            '/31 has no network address' => ['10.0.0.0/31', '10.0.0.0/31', '255.255.255.254', '10.0.0.0', '10.0.0.1', '10.0.0.0', '10.0.0.1', '2', '10.0.0.1'],
            '/32' => ['10.0.0.5/32', '10.0.0.5/32', '255.255.255.255', '10.0.0.5', '10.0.0.5', '10.0.0.5', '10.0.0.5', '1', '10.0.0.5'],
            'a bare IPv4 address' => ['10.0.0.5', '10.0.0.5/32', '255.255.255.255', '10.0.0.5', '10.0.0.5', '10.0.0.5', '10.0.0.5', '1', '10.0.0.5'],
            'IPv6 /64' => ['2001:db8::1/64', '2001:db8::/64', 'ffff:ffff:ffff:ffff::', '2001:db8::', '2001:db8::ffff:ffff:ffff:ffff', '2001:db8::', '2001:db8::ffff:ffff:ffff:ffff', '18446744073709551616', null],
            'IPv6 /127' => ['2001:db8::/127', '2001:db8::/127', 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:fffe', '2001:db8::', '2001:db8::1', '2001:db8::', '2001:db8::1', '2', null],
            'IPv6 /128' => ['::1/128', '::1/128', 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', '::1', '::1', '::1', '::1', '1', null],
            'IPv6 /0' => ['::/0', '::/0', '::', '::', 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', '::', 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', '340282366920938463463374607431768211456', null],
        ];
    }

    #[DataProvider('blocks')]
    public function test_a_block_knows_its_bounds(
        string $input,
        string $canonical,
        string $netmask,
        string $first,
        string $last,
        string $firstHost,
        string $lastHost,
        string $size,
        ?string $broadcast,
    ): void {
        $block = Cidr::parse($input);

        self::assertSame($canonical, $block->toString());
        self::assertSame($netmask, $block->netmask()->toString());
        self::assertSame($first, $block->first()->toString());
        self::assertSame($first, $block->network()->toString());
        self::assertSame($last, $block->last()->toString());
        self::assertSame($firstHost, $block->firstHost()->toString());
        self::assertSame($lastHost, $block->lastHost()->toString());
        self::assertSame($size, $block->size());
        self::assertSame($broadcast, $block->broadcast()?->toString());
    }

    public function test_host_count_skips_the_network_and_broadcast_addresses(): void
    {
        self::assertSame('254', Cidr::parse('10.0.0.0/24')->hostCount());
        self::assertSame('2', Cidr::parse('10.0.0.0/31')->hostCount());
        self::assertSame('1', Cidr::parse('10.0.0.0/32')->hostCount());
        self::assertSame('4294967294', Cidr::parse('0.0.0.0/0')->hostCount());
        self::assertSame('18446744073709551616', Cidr::parse('2001:db8::/64')->hostCount());
    }

    public function test_the_wildcard_is_the_inverse_mask(): void
    {
        self::assertSame('0.0.0.255', Cidr::parse('10.0.0.0/24')->wildcard()->toString());
        self::assertSame('::ffff:ffff:ffff:ffff', Cidr::parse('2001:db8::/64')->wildcard()->toString());
    }

    /** @return array<string, array{string}> */
    public static function nonBlocks(): array
    {
        return [
            'prefix too long for IPv4' => ['10.0.0.0/33'],
            'prefix too long for IPv6' => ['::/129'],
            'negative prefix' => ['10.0.0.0/-1'],
            'empty prefix' => ['10.0.0.0/'],
            'leading zero in the prefix' => ['10.0.0.0/08'],
            'prefix with a space' => ['10.0.0.0/ 8'],
            'not an address' => ['nope/8'],
            'empty' => [''],
        ];
    }

    #[DataProvider('nonBlocks')]
    public function test_anything_else_is_refused(string $input): void
    {
        self::assertNull(Cidr::tryParse($input));
        self::assertFalse(Cidr::isValid($input));

        $this->expectException(IpException::class);
        Cidr::parse($input);
    }

    public function test_a_block_from_an_address_and_a_mask(): void
    {
        self::assertSame('10.1.0.0/16', Cidr::fromAddressAndMask('10.1.2.3', '255.255.0.0')->toString());
        self::assertSame('2001:db8::/32', Cidr::fromAddress(IpAddress::parse('2001:db8::1'), 32)->toString());
        self::assertSame(IpVersion::V6, Cidr::fromAddress('::1', 64)->version());
        self::assertSame(64, Cidr::fromAddress('::1', 64)->prefix());
    }

    public function test_a_non_contiguous_mask_is_refused(): void
    {
        $this->expectException(IpException::class);
        Cidr::fromAddressAndMask('10.0.0.1', '255.0.255.0');
    }

    public function test_a_mask_of_the_other_version_is_refused(): void
    {
        $this->expectException(IpException::class);
        Cidr::fromAddressAndMask('10.0.0.1', 'ffff::');
    }

    // ---- membership -------------------------------------------------------------------

    public function test_containment(): void
    {
        $private = Cidr::parse('10.0.0.0/8');

        self::assertTrue($private->contains('10.255.255.255'));
        self::assertTrue($private->contains(IpAddress::parse('10.0.0.0')));
        self::assertFalse($private->contains('11.0.0.0'));
        self::assertFalse($private->contains('::1'), 'the other version');
        self::assertFalse($private->contains('garbage'));
        self::assertTrue($private->contains('::ffff:10.1.2.3'), 'IPv4-mapped is judged as IPv4');
        self::assertTrue($private->contains(Cidr::parse('10.1.0.0/16')));
        self::assertFalse($private->contains(Cidr::parse('0.0.0.0/0')));
        self::assertTrue(Cidr::parse('2001:db8::/32')->contains('2001:db8:ffff::1'));
        self::assertFalse(Cidr::parse('2001:db8::/32')->contains('2001:db9::'));
        self::assertTrue(Cidr::parse('::/0')->contains('::ffff:10.0.0.1'));
    }

    public function test_overlap(): void
    {
        self::assertTrue(Cidr::parse('10.0.0.0/8')->overlaps(Cidr::parse('10.1.0.0/16')));
        self::assertTrue(Cidr::parse('10.1.0.0/16')->overlaps(Cidr::parse('10.0.0.0/8')));
        self::assertFalse(Cidr::parse('10.0.0.0/16')->overlaps(Cidr::parse('10.1.0.0/16')));
        self::assertTrue(Cidr::parse('10.0.0.0/8')->equals(Cidr::parse('10.9.9.9/8')));
    }

    // ---- listing ----------------------------------------------------------------------

    public function test_a_block_lists_its_addresses(): void
    {
        self::assertSame(['192.168.1.0', '192.168.1.1', '192.168.1.2', '192.168.1.3'], Cidr::parse('192.168.1.0/30')->toArray());
        self::assertSame(['192.168.1.1', '192.168.1.2'], Cidr::parse('192.168.1.0/30')->toArray(hostsOnly: true));
        self::assertSame(['10.0.0.5'], Cidr::parse('10.0.0.5/32')->toArray());
        self::assertSame(['2001:db8::', '2001:db8::1', '2001:db8::2', '2001:db8::3'], Cidr::parse('2001:db8::/126')->toArray());
        self::assertCount(256, Cidr::parse('10.0.0.0/24')->toArray());
    }

    public function test_a_large_block_is_refused_as_a_list(): void
    {
        $this->expectException(IpException::class);
        $this->expectExceptionMessage('18446744073709551616');
        Cidr::parse('2001:db8::/64')->toArray();
    }

    public function test_the_limit_can_be_lowered(): void
    {
        $this->expectException(IpException::class);
        Cidr::parse('10.0.0.0/24')->toArray(limit: 255);
    }

    /** Lazily, so the first few of a /64 cost only the first few. */
    public function test_addresses_are_generated_lazily(): void
    {
        $taken = [];

        foreach (Cidr::parse('2001:db8::/64')->addresses() as $address) {
            $taken[] = $address->toString();

            if (\count($taken) === 3) {
                break;
            }
        }

        self::assertSame(['2001:db8::', '2001:db8::1', '2001:db8::2'], $taken);
    }

    public function test_the_top_of_the_address_space_ends_the_listing(): void
    {
        self::assertSame(
            ['255.255.255.252', '255.255.255.253', '255.255.255.254', '255.255.255.255'],
            Cidr::parse('255.255.255.252/30')->toArray(),
        );
    }

    public function test_a_block_splits_into_subnets(): void
    {
        $subnets = \array_map(\strval(...), \iterator_to_array(Cidr::parse('10.0.0.0/24')->subnets(26), false));

        self::assertSame(['10.0.0.0/26', '10.0.0.64/26', '10.0.0.128/26', '10.0.0.192/26'], $subnets);
        self::assertSame(['10.0.0.0/24'], \array_map(\strval(...), \iterator_to_array(Cidr::parse('10.0.0.0/24')->subnets(24), false)));
        self::assertSame(['::/1', '8000::/1'], \array_map(\strval(...), \iterator_to_array(Cidr::parse('::/0')->subnets(1), false)));
        self::assertCount(256, \iterator_to_array(Cidr::parse('2001:db8::/32')->subnets(40), false));
    }

    public function test_a_subnet_cannot_be_larger_than_the_block(): void
    {
        $this->expectException(IpException::class);
        \iterator_to_array(Cidr::parse('10.0.0.0/24')->subnets(16));
    }

    /** @return array<string, array{string, string, list<string>}> */
    public static function ranges(): array
    {
        return [
            'one address' => ['10.0.0.1', '10.0.0.1', ['10.0.0.1/32']],
            'aligned block' => ['10.0.0.0', '10.0.0.255', ['10.0.0.0/24']],
            'unaligned' => ['10.0.0.0', '10.0.0.10', ['10.0.0.0/29', '10.0.0.8/31', '10.0.0.10/32']],
            'both ends unaligned' => ['10.0.0.1', '10.0.0.6', ['10.0.0.1/32', '10.0.0.2/31', '10.0.0.4/31', '10.0.0.6/32']],
            'everything' => ['0.0.0.0', '255.255.255.255', ['0.0.0.0/0']],
            'IPv6 everything' => ['::', 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', ['::/0']],
            'IPv6' => ['2001:db8::', '2001:db8::2', ['2001:db8::/127', '2001:db8::2/128']],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('ranges')]
    public function test_a_range_becomes_the_fewest_blocks(string $start, string $end, array $expected): void
    {
        self::assertSame($expected, \array_map(\strval(...), Cidr::fromRange($start, $end)));
    }

    public function test_a_reversed_range_is_refused(): void
    {
        $this->expectException(IpException::class);
        Cidr::fromRange('10.0.0.2', '10.0.0.1');
    }

    public function test_a_range_across_versions_is_refused(): void
    {
        $this->expectException(IpException::class);
        Cidr::fromRange('10.0.0.1', '::1');
    }
}
