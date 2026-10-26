<?php

declare(strict_types=1);

namespace App\Engine\Network;

/**
 * One IPv4 or IPv6 address.
 *
 * Immutable, and held as the packed form inet_pton() produces: 4 bytes or 16.
 * That makes equality a string comparison, ordering a strcmp(), and masking a
 * bitwise AND -- for both families, with no big-number extension, because PHP's
 * string operators work byte by byte at any length.
 *
 * Reading is strict. "01.2.3.4", "1.2.3" and "1.2.3.4 " are refused rather than
 * guessed at, because the guesses differ between libraries and an address that
 * two layers read differently is how an allowlist is bypassed. Two forms that are
 * unambiguous are accepted: a bracketed IPv6 address ("[::1]", as it appears in
 * a URL) and a zone id ("fe80::1%eth0"), which is dropped.
 *
 *     $ip = IpAddress::parse('2001:db8::1');
 *     $ip->version();         // IpVersion::V6
 *     $ip->isPublic();        // false: 2001:db8::/32 is for documentation
 *     $ip->mask(48);          // 2001:db8::
 *     $ip->next();            // 2001:db8::2
 */
final class IpAddress implements \Stringable
{
    /**
     * Special-purpose blocks by what they are, from the IANA special-purpose
     * registries (RFC 6890 and its updates). isPublic() is "in none of these".
     */
    private const RANGES = [
        'loopback' => ['127.0.0.0/8', '::1/128'],
        'private' => ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7'],
        'link-local' => ['169.254.0.0/16', 'fe80::/10'],
        'multicast' => ['224.0.0.0/4', 'ff00::/8'],
        'reserved' => [
            '0.0.0.0/8',          // "this network"
            '100.64.0.0/10',      // carrier-grade NAT
            '192.0.0.0/24',       // IETF protocol assignments
            '192.0.2.0/24',       // documentation (TEST-NET-1)
            '198.18.0.0/15',      // benchmarking
            '198.51.100.0/24',    // documentation (TEST-NET-2)
            '203.0.113.0/24',     // documentation (TEST-NET-3)
            '240.0.0.0/4',        // reserved, and the limited broadcast address
            '::/128',             // unspecified
            // Three IPv6 forms that carry an IPv4 address inside them. Through
            // a NAT64 gateway or a 6to4 relay each one reaches that IPv4 host,
            // so 64:ff9b::7f00:1 is 127.0.0.1 by another name. They are not
            // judged by the address inside: none of them is how a public
            // service is published, and refusing them outright is simpler
            // than trusting every relay to translate as expected.
            '64:ff9b::/96',       // NAT64 well-known prefix (RFC 6052)
            '2002::/16',          // 6to4 (RFC 3056)
            // IPv4-compatible, deprecated (RFC 4291): ::/96, less its first /8,
            // which holds :: and ::1 and is classified above.
            '::1.0.0.0/104', '::2.0.0.0/103', '::4.0.0.0/102', '::8.0.0.0/101',
            '::16.0.0.0/100', '::32.0.0.0/99', '::64.0.0.0/98', '::128.0.0.0/97',
            '64:ff9b:1::/48',     // local-use IPv4/IPv6 translation
            '100::/64',           // discard-only
            '2001:2::/48',        // benchmarking
            '2001:db8::/32',      // documentation
            '2001:10::/28',       // ORCHID
            '3fff::/20',          // documentation
        ],
    ];

    /** @var array<string, list<Cidr>> */
    private static array $ranges = [];

    private function __construct(
        private readonly string $bytes,
    ) {}

    /**
     * @throws IpException when $address is not an address
     */
    public static function parse(string $address): self
    {
        return self::tryParse($address) ?? throw IpException::invalidAddress($address);
    }

    /** The address, or null when $address is not one. */
    public static function tryParse(string $address): ?self
    {
        if (\str_starts_with($address, '[') && \str_ends_with($address, ']')) {
            $address = \substr($address, 1, -1);
        }

        $zone = \strpos($address, '%');

        if ($zone !== false && \str_contains($address, ':')) {
            $address = \substr($address, 0, $zone);
        }

        if (\filter_var($address, \FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $bytes = \inet_pton($address);

        return \is_string($bytes) ? new self($bytes) : null;
    }

    /** Whether $address is an address, optionally of one version. */
    public static function isValid(string $address, ?IpVersion $version = null): bool
    {
        $parsed = self::tryParse($address);

        return $parsed !== null && ($version === null || $parsed->version() === $version);
    }

    /**
     * From the packed form: 4 bytes or 16, as inet_pton() returns.
     *
     * @throws IpException for any other length
     */
    public static function fromBinary(string $bytes): self
    {
        if (\strlen($bytes) !== 4 && \strlen($bytes) !== 16) {
            throw IpException::invalidAddress(\bin2hex($bytes));
        }

        return new self($bytes);
    }

    /**
     * An IPv4 address from its 32-bit value, as ip2long() returns it.
     *
     * @throws IpException when $value is not 0 to 4294967295
     */
    public static function fromLong(int $value): self
    {
        if ($value < 0 || $value > 0xFFFFFFFF) {
            throw IpException::invalidAddress((string) $value);
        }

        return new self(\pack('N', $value));
    }

    // ---- what it is ------------------------------------------------------

    public function version(): IpVersion
    {
        return \strlen($this->bytes) === 4 ? IpVersion::V4 : IpVersion::V6;
    }

    public function isV4(): bool
    {
        return $this->version() === IpVersion::V4;
    }

    public function isV6(): bool
    {
        return $this->version() === IpVersion::V6;
    }

    /** An IPv4 address carried in IPv6: ::ffff:a.b.c.d, as a dual-stack socket reports one. */
    public function isV4Mapped(): bool
    {
        return $this->isV6() && \str_starts_with($this->bytes, \str_repeat("\x00", 10) . "\xFF\xFF");
    }

    public function isLoopback(): bool
    {
        return $this->in('loopback');
    }

    /** RFC 1918 for IPv4, unique local (fc00::/7) for IPv6. */
    public function isPrivate(): bool
    {
        return $this->in('private');
    }

    public function isLinkLocal(): bool
    {
        return $this->in('link-local');
    }

    public function isMulticast(): bool
    {
        return $this->in('multicast');
    }

    /** Documentation, benchmarking, carrier-grade NAT, unspecified and the other special-purpose blocks. */
    public function isReserved(): bool
    {
        return $this->in('reserved');
    }

    /**
     * Routable on the internet: none of the above.
     *
     * An IPv4-mapped address is judged by the IPv4 address it carries, so
     * ::ffff:10.0.0.1 is private, not public.
     */
    public function isPublic(): bool
    {
        foreach (\array_keys(self::RANGES) as $kind) {
            if ($this->in($kind)) {
                return false;
            }
        }

        return true;
    }

    // ---- other forms -----------------------------------------------------

    /** The canonical text form: dotted quad, or RFC 5952 compressed IPv6. */
    public function toString(): string
    {
        if ($this->isV4()) {
            return (string) \inet_ntop($this->bytes);
        }

        if ($this->isV4Mapped()) {
            return '::ffff:' . \inet_ntop(\substr($this->bytes, 12));
        }

        // RFC 5952 by hand: inet_ntop() writes any address whose first 96 bits
        // are zero as "::a.b.c.d", so ::1:0 would come out as ::0.1.0.0.
        $groups = \array_map(
            static fn(string $group): string => \ltrim($group, '0') ?: '0',
            \str_split(\bin2hex($this->bytes), 4),
        );

        // The longest run of two or more zero groups, the first on a tie.
        $bestStart = -1;
        $bestLength = 1;

        for ($i = 0; $i < 8; ++$i) {
            $length = 0;

            while ($i + $length < 8 && $groups[$i + $length] === '0') {
                ++$length;
            }

            if ($length > $bestLength) {
                $bestStart = $i;
                $bestLength = $length;
            }

            $i += $length;
        }

        if ($bestStart === -1) {
            return \implode(':', $groups);
        }

        return \implode(':', \array_slice($groups, 0, $bestStart))
            . '::'
            . \implode(':', \array_slice($groups, $bestStart + $bestLength));
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /** Every IPv6 group written out in full: 2001:0db8:0000:…:0001. IPv4 is unchanged. */
    public function toExpanded(): string
    {
        if ($this->isV4()) {
            return $this->toString();
        }

        return \implode(':', \str_split(\bin2hex($this->bytes), 4));
    }

    /** The packed form, 4 or 16 bytes, for storage in a BINARY/VARBINARY column. */
    public function toBinary(): string
    {
        return $this->bytes;
    }

    /**
     * The 32-bit value of an IPv4 address, as ip2long() gives it.
     *
     * @throws IpException for IPv6
     */
    public function toLong(): int
    {
        if (!$this->isV4()) {
            throw IpException::notIpv4($this->toString());
        }

        /** @var array{1: int} $value */
        $value = \unpack('N', $this->bytes);

        return $value[1];
    }

    /** The name a PTR lookup asks for: 4.3.2.1.in-addr.arpa, or the nibbles of IPv6 under ip6.arpa. */
    public function toReversePointer(): string
    {
        if ($this->isV4()) {
            return \implode('.', \array_reverse(\explode('.', $this->toString()))) . '.in-addr.arpa';
        }

        return \implode('.', \array_reverse(\str_split(\bin2hex($this->bytes)))) . '.ip6.arpa';
    }

    /** The IPv4 address inside an IPv4-mapped one, this address if it is IPv4, else null. */
    public function toV4(): ?self
    {
        if ($this->isV4()) {
            return $this;
        }

        return $this->isV4Mapped() ? new self(\substr($this->bytes, 12)) : null;
    }

    /** ::ffff:a.b.c.d for an IPv4 address; an IPv6 address is returned as it is. */
    public function toV6Mapped(): self
    {
        return $this->isV4() ? new self(\str_repeat("\x00", 10) . "\xFF\xFF" . $this->bytes) : $this;
    }

    // ---- arithmetic ------------------------------------------------------

    /**
     * Only the first $prefix bits kept: the network this address is in.
     *
     * @throws IpException when $prefix is out of range for the version
     */
    public function mask(int $prefix): self
    {
        $this->assertPrefix($prefix);

        return new self($this->bytes & Bytes::mask($prefix, \strlen($this->bytes)));
    }

    /**
     * The address with its host part zeroed, for logs and analytics that must
     * not keep a full address: /24 for IPv4, /48 for IPv6 by default, which is
     * what Google Analytics and most privacy guidance use.
     */
    public function anonymize(int $v4Prefix = 24, int $v6Prefix = 48): self
    {
        return $this->mask($this->isV4() ? $v4Prefix : $v6Prefix);
    }

    /**
     * @throws IpException past 255.255.255.255 or ffff:…:ffff
     */
    public function next(): self
    {
        return $this->add(1);
    }

    /**
     * @throws IpException before 0.0.0.0 or ::
     */
    public function previous(): self
    {
        return $this->add(-1);
    }

    /**
     * The address $offset places away, in either direction.
     *
     * @throws IpException when that is outside the address space
     */
    public function add(int $offset): self
    {
        $length = \strlen($this->bytes);
        $magnitude = Bytes::fromInt($offset === \PHP_INT_MIN ? $offset : \abs($offset), $length);
        $result = $magnitude === null
            ? null
            : ($offset >= 0 ? Bytes::add($this->bytes, $magnitude) : Bytes::subtract($this->bytes, $magnitude));

        return $result === null
            ? throw IpException::outOfRange($this->toString(), $offset)
            : new self($result);
    }

    /** -1, 0 or 1. IPv4 sorts before IPv6. */
    public function compare(self $other): int
    {
        return \strlen($this->bytes) <=> \strlen($other->bytes) ?: \strcmp($this->bytes, $other->bytes) <=> 0;
    }

    public function equals(self|string $other): bool
    {
        $other = $other instanceof self ? $other : self::tryParse($other);

        return $other !== null && $this->bytes === $other->bytes;
    }

    private function in(string $kind): bool
    {
        $address = $this->toV4() ?? $this;

        foreach (self::ranges($kind) as $range) {
            if ($range->contains($address)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Cidr> */
    private static function ranges(string $kind): array
    {
        return self::$ranges[$kind] ??= \array_map(Cidr::parse(...), self::RANGES[$kind]);
    }

    private function assertPrefix(int $prefix): void
    {
        if ($prefix < 0 || $prefix > $this->version()->bits()) {
            throw IpException::invalidPrefix($prefix, $this->version());
        }
    }
}
