<?php

declare(strict_types=1);

namespace App\Tests\Unit\Network;

use App\Engine\Network\IpException;
use App\Engine\Network\IpVersion;
use App\Engine\Network\Netmask;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class NetmaskTest extends TestCase
{
    /** @return array<string, array{int, string, string}> */
    public static function ipv4(): array
    {
        return [
            '/0' => [0, '0.0.0.0', '255.255.255.255'],
            '/8' => [8, '255.0.0.0', '0.255.255.255'],
            '/20' => [20, '255.255.240.0', '0.0.15.255'],
            '/24' => [24, '255.255.255.0', '0.0.0.255'],
            '/31' => [31, '255.255.255.254', '0.0.0.1'],
            '/32' => [32, '255.255.255.255', '0.0.0.0'],
        ];
    }

    #[DataProvider('ipv4')]
    public function test_prefix_and_mask_convert_both_ways(int $prefix, string $mask, string $wildcard): void
    {
        self::assertSame($mask, Netmask::fromPrefix($prefix)->toString());
        self::assertSame($wildcard, Netmask::wildcard($prefix)->toString());
        self::assertSame($prefix, Netmask::toPrefix($mask));
        self::assertTrue(Netmask::isValid($mask));
    }

    public function test_ipv6_masks(): void
    {
        self::assertSame('ffff:ffff:ffff:ffff::', Netmask::fromPrefix(64, IpVersion::V6)->toString());
        self::assertSame('ffff:ffff:ffff:ff00::', Netmask::fromPrefix(56, IpVersion::V6)->toString());
        self::assertSame(48, Netmask::toPrefix('ffff:ffff:ffff::'));
        self::assertSame(128, Netmask::toPrefix('ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'));
    }

    /** @return array<string, array{string}> */
    public static function nonMasks(): array
    {
        return [
            'a hole' => ['255.0.255.0'],
            'ones after zeros' => ['0.0.0.255'],
            'a stray bit' => ['255.255.255.1'],
            'not an address' => ['255.255.255'],
        ];
    }

    #[DataProvider('nonMasks')]
    public function test_anything_but_contiguous_ones_is_refused(string $mask): void
    {
        self::assertFalse(Netmask::isValid($mask));

        $this->expectException(IpException::class);
        Netmask::toPrefix($mask);
    }

    public function test_a_prefix_out_of_range_is_refused(): void
    {
        $this->expectException(IpException::class);
        Netmask::fromPrefix(33);
    }

    public function test_a_prefix_out_of_range_for_ipv6_is_refused(): void
    {
        $this->expectException(IpException::class);
        Netmask::wildcard(129, IpVersion::V6);
    }
}
