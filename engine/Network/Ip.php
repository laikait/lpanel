<?php

declare(strict_types=1);

namespace App\Engine\Network;

/**
 * One-line answers about addresses given as strings.
 *
 *     Ip::isValid('10.0.0.1');                      // true
 *     Ip::isV6('::1');                              // true
 *     Ip::isPublic('192.168.1.10');                 // false
 *     Ip::inRange('10.1.2.3', '10.0.0.0/8');        // true
 *     Ip::inRange('10.1.2.3', ['::1', '10.0.0.0/8']); // true
 *     Ip::mask('192.168.1.77', 24);                 // "192.168.1.0"
 *     Ip::anonymize('2001:db8:1:2::1');             // "2001:db8:1::"
 *     Ip::range('192.168.1.0/30');                  // ['192.168.1.0', … '192.168.1.3']
 *
 * Every predicate answers false for input that is not an address, rather than
 * throwing: "is this public" of garbage is "no". The functions that return an
 * address throw IpException instead, since they have nothing honest to return.
 * For more than one question about the same address, parse it once with
 * IpAddress::parse() and ask that.
 */
final class Ip
{
    public static function isValid(string $ip): bool
    {
        return IpAddress::isValid($ip);
    }

    public static function isV4(string $ip): bool
    {
        return IpAddress::isValid($ip, IpVersion::V4);
    }

    public static function isV6(string $ip): bool
    {
        return IpAddress::isValid($ip, IpVersion::V6);
    }

    public static function version(string $ip): ?IpVersion
    {
        return IpAddress::tryParse($ip)?->version();
    }

    public static function isPrivate(string $ip): bool
    {
        return IpAddress::tryParse($ip)?->isPrivate() ?? false;
    }

    public static function isLoopback(string $ip): bool
    {
        return IpAddress::tryParse($ip)?->isLoopback() ?? false;
    }

    public static function isPublic(string $ip): bool
    {
        return IpAddress::tryParse($ip)?->isPublic() ?? false;
    }

    /**
     * Whether $ip is in a network, or in any of a list of networks and addresses.
     *
     * @param string|iterable<string> $ranges
     *
     * @throws IpException when a range cannot be read: a typo in an allowlist is a bug, not a "no"
     */
    public static function inRange(string $ip, string|iterable $ranges): bool
    {
        return (new IpSet(\is_string($ranges) ? [$ranges] : $ranges))->contains($ip);
    }

    /** The canonical form -- "::1" for "0:0:0:0:0:0:0:1" -- or null for input that is not an address. */
    public static function normalize(string $ip): ?string
    {
        return IpAddress::tryParse($ip)?->toString();
    }

    /**
     * @throws IpException
     */
    public static function mask(string $ip, int $prefix): string
    {
        return IpAddress::parse($ip)->mask($prefix)->toString();
    }

    /**
     * @throws IpException
     */
    public static function anonymize(string $ip, int $v4Prefix = 24, int $v6Prefix = 48): string
    {
        return IpAddress::parse($ip)->anonymize($v4Prefix, $v6Prefix)->toString();
    }

    /**
     * Every address in a CIDR block.
     *
     * @return list<string>
     *
     * @throws IpException when the block cannot be read or holds more than $limit addresses
     */
    public static function range(string $cidr, bool $hostsOnly = false, int $limit = 65536): array
    {
        return Cidr::parse($cidr)->toArray($hostsOnly, $limit);
    }

    /** As ip2long(), for both versions: the packed bytes, suitable for a BINARY(16) column or a sort. */
    public static function toBinary(string $ip): ?string
    {
        return IpAddress::tryParse($ip)?->toBinary();
    }
}
