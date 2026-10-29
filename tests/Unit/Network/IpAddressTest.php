<?php

declare(strict_types=1);

namespace App\Tests\Unit\Network;

use App\Engine\Network\IpAddress;
use App\Engine\Network\IpException;
use App\Engine\Network\IpVersion;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class IpAddressTest extends TestCase
{
    // ---- reading -----------------------------------------------------------------------

    /** @return array<string, array{string, string, IpVersion}> */
    public static function addresses(): array
    {
        return [
            'IPv4' => ['192.168.1.10', '192.168.1.10', IpVersion::V4],
            'IPv4 zero' => ['0.0.0.0', '0.0.0.0', IpVersion::V4],
            'IPv6 loopback' => ['::1', '::1', IpVersion::V6],
            'IPv6 unspecified' => ['::', '::', IpVersion::V6],
            'IPv6 written out' => ['2001:0db8:0000:0000:0000:0000:0000:0001', '2001:db8::1', IpVersion::V6],
            'IPv6 upper case' => ['2001:DB8::A', '2001:db8::a', IpVersion::V6],
            'longest zero run compressed' => ['2001:db8:0:0:1:0:0:0', '2001:db8:0:0:1::', IpVersion::V6],
            'first run on a tie' => ['2001:db8:0:0:1:0:0:1', '2001:db8::1:0:0:1', IpVersion::V6],
            'a single zero group is not compressed' => ['2001:db8:0:1:1:1:1:1', '2001:db8:0:1:1:1:1:1', IpVersion::V6],
            'not written as IPv4-compatible' => ['::1:0', '::1:0', IpVersion::V6],
            'IPv4-mapped' => ['::ffff:1.2.3.4', '::ffff:1.2.3.4', IpVersion::V6],
            'IPv4-mapped in hex' => ['::ffff:0102:0304', '::ffff:1.2.3.4', IpVersion::V6],
            'bracketed' => ['[2001:db8::1]', '2001:db8::1', IpVersion::V6],
            'zone id dropped' => ['fe80::1%eth0', 'fe80::1', IpVersion::V6],
        ];
    }

    #[DataProvider('addresses')]
    public function test_an_address_is_read_and_written_canonically(string $input, string $canonical, IpVersion $version): void
    {
        $address = IpAddress::parse($input);

        self::assertSame($canonical, $address->toString());
        self::assertSame($canonical, (string) $address);
        self::assertSame($version, $address->version());
        self::assertTrue(IpAddress::parse($canonical)->equals($address), 'the canonical form reads back');
    }

    /** @return array<string, array{string}> */
    public static function nonAddresses(): array
    {
        return [
            'empty' => [''],
            'three octets' => ['1.2.3'],
            'octet too large' => ['1.2.3.256'],
            'leading zero' => ['01.2.3.4'],
            'surrounding space' => [' 1.2.3.4'],
            'with a port' => ['1.2.3.4:80'],
            'with a prefix' => ['10.0.0.0/8'],
            'two double colons' => ['1::2::3'],
            'nine groups' => ['1:2:3:4:5:6:7:8:9'],
            'hostname' => ['localhost'],
            'zone on IPv4' => ['1.2.3.4%eth0'],
        ];
    }

    #[DataProvider('nonAddresses')]
    public function test_anything_else_is_refused(string $input): void
    {
        self::assertNull(IpAddress::tryParse($input));
        self::assertFalse(IpAddress::isValid($input));

        $this->expectException(IpException::class);
        IpAddress::parse($input);
    }

    public function test_validity_can_ask_for_a_version(): void
    {
        self::assertTrue(IpAddress::isValid('10.0.0.1', IpVersion::V4));
        self::assertFalse(IpAddress::isValid('10.0.0.1', IpVersion::V6));
        self::assertTrue(IpAddress::isValid('::1', IpVersion::V6));
        self::assertFalse(IpAddress::isValid('::1', IpVersion::V4));
    }

    // ---- other forms ------------------------------------------------------------------

    public function test_the_other_forms(): void
    {
        $v4 = IpAddress::parse('192.168.1.10');
        $v6 = IpAddress::parse('2001:db8::1');

        self::assertSame(3232235786, $v4->toLong());
        self::assertSame('192.168.1.10', IpAddress::fromLong(3232235786)->toString());
        self::assertSame(\inet_pton('192.168.1.10'), $v4->toBinary());
        self::assertTrue(IpAddress::fromBinary($v6->toBinary())->equals($v6));
        self::assertSame('2001:0db8:0000:0000:0000:0000:0000:0001', $v6->toExpanded());
        self::assertSame('192.168.1.10', $v4->toExpanded());
        self::assertSame('10.1.168.192.in-addr.arpa', $v4->toReversePointer());
        self::assertSame(
            '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa',
            $v6->toReversePointer(),
        );
    }

    public function test_an_ipv6_address_has_no_integer_form(): void
    {
        $this->expectException(IpException::class);
        IpAddress::parse('::1')->toLong();
    }

    public function test_a_long_out_of_range_is_refused(): void
    {
        $this->expectException(IpException::class);
        IpAddress::fromLong(0x100000000);
    }

    public function test_a_packed_address_must_be_four_or_sixteen_bytes(): void
    {
        $this->expectException(IpException::class);
        IpAddress::fromBinary('abc');
    }

    public function test_ipv4_mapped_addresses_convert_both_ways(): void
    {
        $mapped = IpAddress::parse('::ffff:10.0.0.1');

        self::assertTrue($mapped->isV4Mapped());
        self::assertSame('10.0.0.1', $mapped->toV4()?->toString());
        self::assertSame('::ffff:10.0.0.1', IpAddress::parse('10.0.0.1')->toV6Mapped()->toString());
        self::assertNull(IpAddress::parse('2001:db8::1')->toV4());
        self::assertFalse(IpAddress::parse('::1')->isV4Mapped());
    }

    // ---- classification ---------------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function classified(): array
    {
        return [
            '8.8.8.8' => ['8.8.8.8', 'public'],
            '2606:4700::1111' => ['2606:4700::1111', 'public'],
            '10.1.2.3' => ['10.1.2.3', 'private'],
            '172.16.0.1' => ['172.16.0.1', 'private'],
            '172.31.255.255' => ['172.31.255.255', 'private'],
            '172.32.0.1' => ['172.32.0.1', 'public'],
            '192.168.0.1' => ['192.168.0.1', 'private'],
            'fd00::1' => ['fd00::1', 'private'],
            '127.0.0.1' => ['127.0.0.1', 'loopback'],
            '::1' => ['::1', 'loopback'],
            '169.254.1.1' => ['169.254.1.1', 'link-local'],
            'fe80::1' => ['fe80::1', 'link-local'],
            '224.0.0.1' => ['224.0.0.1', 'multicast'],
            'ff02::1' => ['ff02::1', 'multicast'],
            '0.0.0.0' => ['0.0.0.0', 'reserved'],
            '100.64.0.1' => ['100.64.0.1', 'reserved'],
            '192.0.2.1' => ['192.0.2.1', 'reserved'],
            '203.0.113.9' => ['203.0.113.9', 'reserved'],
            '255.255.255.255' => ['255.255.255.255', 'reserved'],
            '::' => ['::', 'reserved'],
            '2001:db8::1' => ['2001:db8::1', 'reserved'],
            'mapped private' => ['::ffff:10.0.0.1', 'private'],
            'mapped public' => ['::ffff:8.8.8.8', 'public'],
            // IPv4 inside IPv6, reachable through a translator: loopback and
            // the metadata endpoint by other names, so never "public".
            'NAT64 loopback' => ['64:ff9b::7f00:1', 'reserved'],
            'NAT64 metadata' => ['64:ff9b::a9fe:a9fe', 'reserved'],
            '6to4' => ['2002:7f00:1::1', 'reserved'],
            'IPv4-compatible' => ['::7f00:1', 'reserved'],
        ];
    }

    #[DataProvider('classified')]
    public function test_an_address_is_classified(string $input, string $kind): void
    {
        $address = IpAddress::parse($input);

        self::assertSame($kind === 'public', $address->isPublic(), 'public');
        self::assertSame($kind === 'private', $address->isPrivate(), 'private');
        self::assertSame($kind === 'loopback', $address->isLoopback(), 'loopback');
        self::assertSame($kind === 'link-local', $address->isLinkLocal(), 'link-local');
        self::assertSame($kind === 'multicast', $address->isMulticast(), 'multicast');
        self::assertSame($kind === 'reserved', $address->isReserved(), 'reserved');
    }

    // ---- arithmetic -------------------------------------------------------------------

    public function test_masking_keeps_the_network_bits(): void
    {
        self::assertSame('192.168.1.0', IpAddress::parse('192.168.1.77')->mask(24)->toString());
        self::assertSame('192.168.0.0', IpAddress::parse('192.168.1.77')->mask(20)->toString());
        self::assertSame('0.0.0.0', IpAddress::parse('192.168.1.77')->mask(0)->toString());
        self::assertSame('192.168.1.77', IpAddress::parse('192.168.1.77')->mask(32)->toString());
        self::assertSame('2001:db8:1::', IpAddress::parse('2001:db8:1:2:3::1')->mask(48)->toString());
    }

    public function test_a_prefix_out_of_range_is_refused(): void
    {
        $this->expectException(IpException::class);
        IpAddress::parse('10.0.0.1')->mask(33);
    }

    public function test_anonymizing_drops_the_host_part(): void
    {
        self::assertSame('203.0.113.0', IpAddress::parse('203.0.113.77')->anonymize()->toString());
        self::assertSame('2001:db8:1::', IpAddress::parse('2001:db8:1:2::1')->anonymize()->toString());
        self::assertSame('203.0.0.0', IpAddress::parse('203.0.113.77')->anonymize(16)->toString());
    }

    /** @return array<string, array{string, int, string}> */
    public static function offsets(): array
    {
        return [
            'next' => ['10.0.0.1', 1, '10.0.0.2'],
            'carry across a byte' => ['0.0.0.255', 1, '0.0.1.0'],
            'carry across every byte' => ['0.255.255.255', 1, '1.0.0.0'],
            'borrow across a byte' => ['0.0.1.0', -1, '0.0.0.255'],
            'large' => ['10.0.0.0', 65536, '10.1.0.0'],
            'IPv6 carry' => ['::ffff', 1, '::1:0'],
            'IPv6 borrow' => ['::1:0', -1, '::ffff'],
            'IPv6 large' => ['2001:db8::', \PHP_INT_MAX, '2001:db8::7fff:ffff:ffff:ffff'],
            'IPv6 most negative' => ['::1:0:0:0:0', \PHP_INT_MIN, '::8000:0:0:0'],
            'zero' => ['10.0.0.1', 0, '10.0.0.1'],
        ];
    }

    #[DataProvider('offsets')]
    public function test_arithmetic_carries(string $start, int $offset, string $expected): void
    {
        self::assertSame($expected, IpAddress::parse($start)->add($offset)->toString());
    }

    public function test_next_and_previous(): void
    {
        self::assertSame('10.0.0.2', IpAddress::parse('10.0.0.1')->next()->toString());
        self::assertSame('10.0.0.0', IpAddress::parse('10.0.0.1')->previous()->toString());
    }

    /** @return array<string, array{string, int}> */
    public static function overflows(): array
    {
        return [
            'past the top of IPv4' => ['255.255.255.255', 1],
            'below the bottom of IPv4' => ['0.0.0.0', -1],
            'an offset wider than IPv4' => ['0.0.0.0', 0x100000000],
            'past the top of IPv6' => ['ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', 1],
            'below the bottom of IPv6' => ['::', -1],
        ];
    }

    #[DataProvider('overflows')]
    public function test_arithmetic_never_wraps(string $start, int $offset): void
    {
        $this->expectException(IpException::class);
        IpAddress::parse($start)->add($offset);
    }

    public function test_ordering(): void
    {
        self::assertSame(-1, IpAddress::parse('10.0.0.1')->compare(IpAddress::parse('10.0.0.2')));
        self::assertSame(1, IpAddress::parse('10.0.1.0')->compare(IpAddress::parse('10.0.0.255')));
        self::assertSame(0, IpAddress::parse('::1')->compare(IpAddress::parse('0::1')));
        self::assertSame(-1, IpAddress::parse('255.255.255.255')->compare(IpAddress::parse('::')), 'IPv4 first');
    }

    public function test_equality_reads_strings(): void
    {
        self::assertTrue(IpAddress::parse('::1')->equals('0:0:0:0:0:0:0:1'));
        self::assertFalse(IpAddress::parse('::1')->equals('not an address'));
        self::assertFalse(IpAddress::parse('10.0.0.1')->equals('::ffff:10.0.0.1'), 'different versions');
    }
}
