<?php

declare(strict_types=1);

namespace App\Engine\Http;

/**
 * One media type, parsed: "application/vnd.api+json; q=0.8; charset=utf-8".
 *
 * A value rather than a string, because every interesting question about a
 * media type is a question about its parts. Whether text/html satisfies an
 * Accept of "text/*" is not a substring test, and neither is whether
 * application/vnd.example+json is JSON -- both are true, and both are invisible
 * to strpos().
 *
 * Parsing exists so that content negotiation can be correct rather than
 * approximate. See Negotiator for why that matters.
 */
final class MediaType
{
    /**
     * @param float                 $quality    the q parameter, 1.0 when absent
     * @param array<string, string> $parameters everything except q, lowercased keys
     */
    private function __construct(
        public readonly string $type,
        public readonly string $subtype,
        public readonly float $quality = 1.0,
        public readonly array $parameters = [],
    ) {}

    public static function of(string $type, string $subtype): self
    {
        return new self(\strtolower($type), \strtolower($subtype));
    }

    /** Null for anything that is not a media type at all. */
    public static function parse(string $value): ?self
    {
        $parts = \explode(';', \trim($value));
        $name = \strtolower(\trim(\array_shift($parts) ?? ''));

        if ($name === '' || \substr_count($name, '/') !== 1) {
            return null;
        }

        [$type, $subtype] = \explode('/', $name);

        if ($type === '' || $subtype === '') {
            return null;
        }

        $quality = 1.0;
        $parameters = [];

        foreach ($parts as $part) {
            $pair = \explode('=', $part, 2);

            if (\count($pair) !== 2) {
                continue;
            }

            $key = \strtolower(\trim($pair[0]));
            $parameterValue = \trim(\trim($pair[1]), '"');

            if ($key === 'q') {
                // A malformed q is treated as absent rather than as zero. A
                // typo should not silently mean "I refuse this type".
                $quality = \is_numeric($parameterValue) ? (float) $parameterValue : 1.0;
                $quality = \max(0.0, \min(1.0, $quality));

                continue;
            }

            $parameters[$key] = $parameterValue;
        }

        return new self($type, $subtype, $quality, $parameters);
    }

    /**
     * Parse an Accept header into ranges, best first.
     *
     * Sorted by quality, then by specificity, so that a caller can walk the
     * list in order. Ties keep the order they were written in, which is the
     * only signal left about what the client meant.
     *
     * @return list<self>
     */
    public static function parseList(string $header): array
    {
        $ranges = [];
        $position = 0;

        foreach (\explode(',', $header) as $candidate) {
            $parsed = self::parse($candidate);

            if ($parsed !== null) {
                $ranges[] = [$parsed, $position++];
            }
        }

        \usort(
            $ranges,
            static fn(array $a, array $b): int => [-$a[0]->quality, -$a[0]->specificity(), $a[1]]
                <=> [-$b[0]->quality, -$b[0]->specificity(), $b[1]],
        );

        return \array_map(static fn(array $pair): self => $pair[0], $ranges);
    }

    public function full(): string
    {
        return $this->type . '/' . $this->subtype;
    }

    /** 2 for type/subtype, 1 for type/*, 0 for the catch-all. */
    public function specificity(): int
    {
        if ($this->type === '*') {
            return 0;
        }

        return $this->subtype === '*' ? 1 : 2;
    }

    /**
     * Whether this range covers a concrete type.
     *
     * The range is the receiver: an Accept of "text/*" matches text/html, and
     * not the other way round.
     */
    public function matches(self|string $concrete): bool
    {
        $other = $concrete instanceof self ? $concrete : self::parse($concrete);

        if ($other === null) {
            return false;
        }

        if ($this->type !== '*' && $this->type !== $other->type) {
            return false;
        }

        return $this->subtype === '*' || $this->subtype === $other->subtype;
    }

    /**
     * JSON, including every vendor type that ends in +json.
     *
     * The suffix rule is what makes application/vnd.example.v2+json work
     * without anybody registering it, and it is the reason this is a method
     * rather than a comparison at the call site.
     */
    public function isJson(): bool
    {
        return $this->subtype === 'json' || \str_ends_with($this->subtype, '+json');
    }

    public function isWildcard(): bool
    {
        return $this->type === '*' || $this->subtype === '*';
    }

    public function parameter(string $name, ?string $default = null): ?string
    {
        return $this->parameters[\strtolower($name)] ?? $default;
    }

    public function charset(): ?string
    {
        return $this->parameter('charset');
    }
}
