<?php

declare(strict_types=1);

namespace App\Engine\Network;

/**
 * A block of addresses: a network address and a prefix length.
 *
 *     $net = Cidr::parse('192.168.1.0/24');
 *     $net->contains('192.168.1.77');   // true
 *     $net->netmask();                  // 255.255.255.0
 *     $net->broadcast();                // 192.168.1.255
 *     $net->firstHost();                // 192.168.1.1
 *     $net->size();                     // "256"
 *     Cidr::parse('10.0.0.0/30')->toArray(hostsOnly: true); // ['10.0.0.1', '10.0.0.2']
 *
 * Host bits are forgiven: "192.168.1.77/24" is read as 192.168.1.0/24, because
 * that is what every router does with it and refusing it helps nobody. A bare
 * address is a block of one: /32 or /128.
 *
 * Sizes are decimal strings, because a /0 in IPv6 holds 2^128 addresses and no
 * PHP int can say so.
 */
final class Cidr implements \Stringable
{
    private function __construct(
        private readonly IpAddress $network,
        private readonly int $prefix,
    ) {}

    /**
     * @throws IpException when $cidr is not a network
     */
    public static function parse(string $cidr): self
    {
        return self::tryParse($cidr) ?? throw IpException::invalidCidr($cidr);
    }

    public static function tryParse(string $cidr): ?self
    {
        $slash = \strrpos($cidr, '/');
        $address = IpAddress::tryParse($slash === false ? $cidr : \substr($cidr, 0, $slash));

        if ($address === null) {
            return null;
        }

        if ($slash === false) {
            return new self($address, $address->version()->bits());
        }

        $prefix = \substr($cidr, $slash + 1);

        if (\preg_match('/^(0|[1-9]\d{0,2})$/', $prefix) !== 1 || (int) $prefix > $address->version()->bits()) {
            return null;
        }

        return new self($address->mask((int) $prefix), (int) $prefix);
    }

    public static function isValid(string $cidr): bool
    {
        return self::tryParse($cidr) !== null;
    }

    /**
     * @throws IpException when $prefix is out of range for the address
     */
    public static function fromAddress(IpAddress|string $address, int $prefix): self
    {
        $address = $address instanceof IpAddress ? $address : IpAddress::parse($address);

        return new self($address->mask($prefix), $prefix);
    }

    /**
     * From an address and a dotted netmask: ('10.1.2.3', '255.255.0.0') is 10.1.0.0/16.
     *
     * @throws IpException when either is unreadable, or they are different versions
     */
    public static function fromAddressAndMask(IpAddress|string $address, IpAddress|string $mask): self
    {
        $address = $address instanceof IpAddress ? $address : IpAddress::parse($address);
        $mask = $mask instanceof IpAddress ? $mask : IpAddress::parse($mask);

        if ($address->version() !== $mask->version()) {
            throw IpException::versionMismatch($address->toString(), $mask->toString());
        }

        return self::fromAddress($address, Netmask::toPrefix($mask));
    }

    /**
     * The fewest blocks that cover $start to $end exactly.
     *
     *     Cidr::fromRange('10.0.0.0', '10.0.0.10');
     *     // 10.0.0.0/29, 10.0.0.8/31, 10.0.0.10/32
     *
     * @return list<self>
     *
     * @throws IpException when the ends are unreadable, different versions, or reversed
     */
    public static function fromRange(IpAddress|string $start, IpAddress|string $end): array
    {
        $start = $start instanceof IpAddress ? $start : IpAddress::parse($start);
        $end = $end instanceof IpAddress ? $end : IpAddress::parse($end);

        if ($start->version() !== $end->version()) {
            throw IpException::versionMismatch($start->toString(), $end->toString());
        }

        if ($start->compare($end) > 0) {
            throw IpException::invalidRange($start->toString(), $end->toString());
        }

        $bits = $start->version()->bits();
        $length = $start->version()->bytes();
        $blocks = [];
        $current = $start->toBinary();

        while (true) {
            $hostBits = \min(Bytes::trailingZeros($current), $bits);

            while ($hostBits > 0 && \strcmp($current | ~Bytes::mask($bits - $hostBits, $length), $end->toBinary()) > 0) {
                --$hostBits;
            }

            $blocks[] = new self(IpAddress::fromBinary($current), $bits - $hostBits);
            $last = $current | ~Bytes::mask($bits - $hostBits, $length);

            if ($last === $end->toBinary()) {
                return $blocks;
            }

            $current = (string) Bytes::add($last, (string) Bytes::fromInt(1, $length));
        }
    }

    // ---- what it is ------------------------------------------------------

    public function version(): IpVersion
    {
        return $this->network->version();
    }

    public function prefix(): int
    {
        return $this->prefix;
    }

    /** The first address, with every host bit zero. */
    public function network(): IpAddress
    {
        return $this->network;
    }

    /** 255.255.255.0 for a /24. */
    public function netmask(): IpAddress
    {
        return Netmask::fromPrefix($this->prefix, $this->version());
    }

    /** 0.0.0.255 for a /24: the inverse mask ACLs and some firewalls are written in. */
    public function wildcard(): IpAddress
    {
        return Netmask::wildcard($this->prefix, $this->version());
    }

    /** The first address in the block: the network address. */
    public function first(): IpAddress
    {
        return $this->network;
    }

    /** The last address in the block, with every host bit one. */
    public function last(): IpAddress
    {
        $mask = Bytes::mask($this->prefix, $this->version()->bytes());

        return IpAddress::fromBinary($this->network->toBinary() | ~$mask);
    }

    /** The IPv4 broadcast address; IPv6 has none, so null. */
    public function broadcast(): ?IpAddress
    {
        return $this->version() === IpVersion::V4 ? $this->last() : null;
    }

    /**
     * The first address a host can have: the network address is skipped in IPv4
     * blocks larger than /31. /31 (RFC 3021) and /32 have no network address to
     * skip, and IPv6 has no such rule.
     */
    public function firstHost(): IpAddress
    {
        return $this->reservesEnds() ? $this->network->next() : $this->network;
    }

    /** The last address a host can have: the broadcast address is skipped under the same rule. */
    public function lastHost(): IpAddress
    {
        return $this->reservesEnds() ? $this->last()->previous() : $this->last();
    }

    /** How many addresses the block holds, as a decimal string. */
    public function size(): string
    {
        return Bytes::powerOfTwo($this->version()->bits() - $this->prefix);
    }

    /** How many of those a host can have: size() less the network and broadcast addresses where they apply. */
    public function hostCount(): string
    {
        if (!$this->reservesEnds()) {
            return $this->size();
        }

        return (string) (2 ** (32 - $this->prefix) - 2);
    }

    /**
     * Whether an address, or a whole block, is inside this one.
     *
     * An IPv4-mapped IPv6 address is judged as the IPv4 address it carries, so
     * a dual-stack socket's ::ffff:10.0.0.5 is inside 10.0.0.0/8. An address of
     * the other version, or one that cannot be read, is never inside.
     */
    public function contains(IpAddress|self|string $subject): bool
    {
        if ($subject instanceof self) {
            return $subject->version() === $this->version()
                && $subject->prefix >= $this->prefix
                && $this->contains($subject->network);
        }

        $address = $subject instanceof IpAddress ? $subject : IpAddress::tryParse($subject);

        if ($address === null) {
            return false;
        }

        if ($this->version() === IpVersion::V4) {
            $address = $address->toV4();

            if ($address === null) {
                return false;
            }
        }

        if ($address->version() !== $this->version()) {
            return false;
        }

        return $address->mask($this->prefix)->equals($this->network);
    }

    /** Whether the two blocks share any address. */
    public function overlaps(self $other): bool
    {
        return $this->contains($other) || $other->contains($this);
    }

    public function equals(self $other): bool
    {
        return $this->prefix === $other->prefix && $this->network->equals($other->network);
    }

    // ---- listing ---------------------------------------------------------

    /**
     * Every address in the block, lazily -- safe for any size, since nothing is
     * built until it is asked for.
     *
     * @return \Generator<int, IpAddress>
     */
    public function addresses(bool $hostsOnly = false): \Generator
    {
        $current = $hostsOnly ? $this->firstHost() : $this->first();
        $last = $hostsOnly ? $this->lastHost() : $this->last();

        while (true) {
            yield $current;

            if ($current->equals($last)) {
                return;
            }

            $current = $current->next();
        }
    }

    /**
     * Every address in the block as a string.
     *
     * The limit is there because the mistake is easy: a /64 is the smallest
     * ordinary IPv6 subnet and holds 18 quintillion addresses. Raise it
     * deliberately, or iterate addresses() instead.
     *
     * @return list<string>
     *
     * @throws IpException when the block holds more than $limit addresses
     */
    public function toArray(bool $hostsOnly = false, int $limit = 65536): array
    {
        $hostBits = $this->version()->bits() - $this->prefix;

        if ($hostBits >= 62 || 2 ** $hostBits > $limit) {
            throw IpException::tooLarge($this->toString(), $this->size(), $limit);
        }

        $list = [];

        foreach ($this->addresses($hostsOnly) as $address) {
            $list[] = $address->toString();
        }

        return $list;
    }

    /**
     * The block split into blocks of $prefix: /24 into /26 gives four.
     *
     * @return \Generator<int, self>
     *
     * @throws IpException when $prefix is shorter than this block's or too long for the version
     */
    public function subnets(int $prefix): \Generator
    {
        if ($prefix < $this->prefix || $prefix > $this->version()->bits()) {
            throw IpException::invalidPrefix($prefix, $this->version());
        }

        $length = $this->version()->bytes();
        $current = $this->network->toBinary();
        $last = $this->last()->toBinary();
        // The size of one subnet: the single bit at position $prefix. A /0 has
        // no such bit, and is only ever one subnet.
        $step = $prefix === 0 ? \str_repeat("\x00", $length) : Bytes::mask($prefix, $length) ^ Bytes::mask($prefix - 1, $length);

        while (true) {
            $block = new self(IpAddress::fromBinary($current), $prefix);

            yield $block;

            if ($block->last()->toBinary() === $last) {
                return;
            }

            $current = (string) Bytes::add($current, $step);
        }
    }

    public function toString(): string
    {
        return $this->network->toString() . '/' . $this->prefix;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    private function reservesEnds(): bool
    {
        return $this->version() === IpVersion::V4 && $this->prefix < 31;
    }
}
