<?php

declare(strict_types=1);

namespace App\Engine\Network;

/**
 * Converting between a prefix length and the masks written from it.
 *
 *     Netmask::fromPrefix(24);               // 255.255.255.0
 *     Netmask::toPrefix('255.255.240.0');    // 20
 *     Netmask::wildcard(24);                 // 0.0.0.255
 *     Netmask::fromPrefix(64, IpVersion::V6);// ffff:ffff:ffff:ffff::
 */
final class Netmask
{
    /**
     * @throws IpException when $prefix is out of range for the version
     */
    public static function fromPrefix(int $prefix, IpVersion $version = IpVersion::V4): IpAddress
    {
        self::assertPrefix($prefix, $version);

        return IpAddress::fromBinary(Bytes::mask($prefix, $version->bytes()));
    }

    /**
     * The inverse mask: host bits set, network bits clear.
     *
     * @throws IpException when $prefix is out of range for the version
     */
    public static function wildcard(int $prefix, IpVersion $version = IpVersion::V4): IpAddress
    {
        self::assertPrefix($prefix, $version);

        return IpAddress::fromBinary(~Bytes::mask($prefix, $version->bytes()));
    }

    /**
     * @throws IpException when $mask is not an address, or its ones are not contiguous
     */
    public static function toPrefix(IpAddress|string $mask): int
    {
        $address = $mask instanceof IpAddress ? $mask : IpAddress::tryParse($mask);
        $prefix = $address === null ? null : Bytes::prefixOf($address->toBinary());

        return $prefix ?? throw IpException::invalidMask((string) $mask);
    }

    /** Whether $mask is a netmask: an address whose ones are all at the front. */
    public static function isValid(IpAddress|string $mask): bool
    {
        $address = $mask instanceof IpAddress ? $mask : IpAddress::tryParse($mask);

        return $address !== null && Bytes::prefixOf($address->toBinary()) !== null;
    }

    private static function assertPrefix(int $prefix, IpVersion $version): void
    {
        if ($prefix < 0 || $prefix > $version->bits()) {
            throw IpException::invalidPrefix($prefix, $version);
        }
    }
}
