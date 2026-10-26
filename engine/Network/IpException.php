<?php

declare(strict_types=1);

namespace App\Engine\Network;

use App\Engine\Error\FrameworkException;

/**
 * An address, a network or a mask that cannot be read, or arithmetic that
 * would leave the address space.
 *
 * Every message is written here and names only what the caller passed, so it is
 * safe to show an operator.
 */
final class IpException extends FrameworkException
{
    public static function invalidAddress(string $address): self
    {
        return new self(\sprintf('"%s" is not an IPv4 or IPv6 address.', $address));
    }

    public static function invalidCidr(string $cidr): self
    {
        return new self(\sprintf(
            '"%s" is not a network. Write it as <address>/<prefix>, e.g. "192.168.0.0/16" or "2001:db8::/32".',
            $cidr,
        ));
    }

    public static function invalidPrefix(int $prefix, IpVersion $version): self
    {
        return new self(\sprintf(
            'A prefix length for IPv%d is 0 to %d; %d was given.',
            $version->value,
            $version->bits(),
            $prefix,
        ));
    }

    public static function invalidMask(string $mask): self
    {
        return new self(\sprintf(
            '"%s" is not a netmask: a mask is contiguous ones followed by zeros, e.g. "255.255.255.0".',
            $mask,
        ));
    }

    public static function versionMismatch(string $first, string $second): self
    {
        return new self(\sprintf('"%s" and "%s" are not the same IP version.', $first, $second));
    }

    public static function outOfRange(string $address, int $offset): self
    {
        return new self(\sprintf('%s %+d leaves the address space.', $address, $offset));
    }

    public static function notIpv4(string $address): self
    {
        return new self(\sprintf('%s is not an IPv4 address, so it has no 32-bit integer form.', $address));
    }

    public static function tooLarge(string $cidr, string $size, int $limit): self
    {
        return new self(\sprintf(
            '%s holds %s addresses, more than the limit of %d. Iterate addresses() instead of building a list.',
            $cidr,
            $size,
            $limit,
        ));
    }

    public static function invalidRange(string $start, string $end): self
    {
        return new self(\sprintf('The range %s - %s ends before it starts.', $start, $end));
    }
}
