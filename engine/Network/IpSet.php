<?php

declare(strict_types=1);

namespace App\Engine\Network;

/**
 * A list of addresses and networks, asked one question: is this address in it?
 *
 *     $office = new IpSet(['203.0.113.0/24', '2001:db8:abcd::/48', '198.51.100.7']);
 *     $office->contains($request->ip());
 *
 * The allowlist, the blocklist, the trusted proxies. Entries are read when the
 * set is built, so a typo fails there, loudly, instead of quietly matching
 * nothing on every request afterwards.
 *
 * @implements \IteratorAggregate<int, Cidr>
 */
final class IpSet implements \Countable, \IteratorAggregate
{
    /** @var list<Cidr> */
    private readonly array $blocks;

    /**
     * @param iterable<IpAddress|Cidr|string> $entries addresses and CIDR blocks, of either version
     *
     * @throws IpException for an entry that is neither
     */
    public function __construct(iterable $entries = [])
    {
        $blocks = [];

        foreach ($entries as $entry) {
            $blocks[] = match (true) {
                $entry instanceof Cidr => $entry,
                $entry instanceof IpAddress => Cidr::fromAddress($entry, $entry->version()->bits()),
                default => Cidr::parse(\trim($entry)),
            };
        }

        $this->blocks = $blocks;
    }

    /**
     * The entries that can be read, dropping the rest -- for input that has
     * already been reported elsewhere and must not stop a request.
     *
     * @param iterable<string> $entries
     */
    public static function lenient(iterable $entries): self
    {
        $valid = [];

        foreach ($entries as $entry) {
            $block = Cidr::tryParse(\trim($entry));

            if ($block !== null) {
                $valid[] = $block;
            }
        }

        return new self($valid);
    }

    /** False for an address that cannot be read, and for null, so a missing REMOTE_ADDR matches nothing. */
    public function contains(IpAddress|string|null $address): bool
    {
        if ($address === null) {
            return false;
        }

        $address = $address instanceof IpAddress ? $address : IpAddress::tryParse($address);

        if ($address === null) {
            return false;
        }

        foreach ($this->blocks as $block) {
            if ($block->contains($address)) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->blocks === [];
    }

    /** @return list<Cidr> */
    public function blocks(): array
    {
        return $this->blocks;
    }

    public function count(): int
    {
        return \count($this->blocks);
    }

    /** @return \ArrayIterator<int, Cidr> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->blocks);
    }
}
