<?php

declare(strict_types=1);

namespace App\Engine\Routing;

/**
 * One route.
 *
 * The fluent setters are registration-time only. Once the router has compiled,
 * changing a route would silently diverge from the compiled structure, so the
 * route freezes itself and says so rather than misbehaving quietly.
 *
 * There is no middleware here, by design. Cross-cutting behaviour reads
 * metadata() from a lifecycle hook instead: meta(['auth' => true]) plus a
 * dispatch.before listener is the whole story, and it composes without a
 * pipeline abstraction that every route then has to know about.
 */
final class Route
{
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

    private ?string $name = null;

    /** @var array<string, string> */
    private array $constraints = [];

    /** @var array<string, mixed> */
    private array $defaults = [];

    /** @var array<string, mixed> */
    private array $metadata = [];

    private bool $frozen = false;

    /** @var list<array{name: string, optional: bool}>|null */
    private ?array $parameters = null;

    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly mixed $handler,
        private readonly ?string $module = null,
        private readonly string $namePrefix = '',
    ) {}

    // ---- registration-time configuration ---------------------------------

    /** The enclosing group's name prefix, if any, is applied automatically. */
    public function name(string $name): self
    {
        $this->assertMutable('name');
        $this->name = $this->namePrefix . $name;

        return $this;
    }

    /** Constrain a parameter to a regular expression fragment, e.g. where('id', '\d+'). */
    public function where(string $parameter, string $pattern): self
    {
        $this->assertMutable('where');
        $this->constraints[$parameter] = $pattern;

        return $this;
    }

    /** @param array<string, string> $constraints */
    public function whereMany(array $constraints): self
    {
        foreach ($constraints as $parameter => $pattern) {
            $this->where($parameter, $pattern);
        }

        return $this;
    }

    /** @param array<string, mixed> $defaults */
    public function defaults(array $defaults): self
    {
        $this->assertMutable('defaults');
        $this->defaults = [...$this->defaults, ...$defaults];

        return $this;
    }

    /**
     * Arbitrary data travelling with the route, read by hooks and filters.
     *
     * @param array<string, mixed> $metadata
     */
    public function meta(array $metadata): self
    {
        $this->assertMutable('meta');
        $this->metadata = [...$this->metadata, ...$metadata];

        return $this;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    // ---- reading ---------------------------------------------------------

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function handler(): mixed
    {
        return $this->handler;
    }

    public function routeName(): ?string
    {
        return $this->name;
    }

    /** The module that registered this route, for attribution and debugging. */
    public function module(): ?string
    {
        return $this->module;
    }

    /** @return array<string, string> */
    public function constraints(): array
    {
        return $this->constraints;
    }

    public function constraint(string $parameter): ?string
    {
        return $this->constraints[$parameter] ?? null;
    }

    /** @return array<string, mixed> */
    public function defaultValues(): array
    {
        return $this->defaults;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function metaValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * The parameters this path declares, in order.
     *
     * @return list<array{name: string, optional: bool}>
     */
    public function parameters(): array
    {
        return $this->parameters ??= self::parseParameters($this->path);
    }

    /**
     * @return list<array{name: string, optional: bool}>
     */
    private static function parseParameters(string $path): array
    {
        $parameters = [];
        $segments = self::segments($path);
        $optionalSeen = false;

        foreach ($segments as $index => $segment) {
            if (!\str_starts_with($segment, '{') || !\str_ends_with($segment, '}')) {
                continue;
            }

            $inner = \substr($segment, 1, -1);
            $optional = \str_ends_with($inner, '?');
            $name = $optional ? \substr($inner, 0, -1) : $inner;

            if ($name === '' || \preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                throw RoutingException::invalidPattern(
                    $path,
                    \sprintf('"%s" is not a valid parameter name.', $name),
                );
            }

            if ($optionalSeen) {
                throw RoutingException::invalidPattern(
                    $path,
                    'only the final parameter may be optional.',
                );
            }

            if ($optional && $index !== \count($segments) - 1) {
                throw RoutingException::invalidPattern(
                    $path,
                    'an optional parameter must be the last segment.',
                );
            }

            // The guard above already rejects anything following an optional,
            // so this only ever needs to record the current segment.
            $optionalSeen = $optional;
            $parameters[] = ['name' => $name, 'optional' => $optional];
        }

        return $parameters;
    }

    /**
     * Split a path into segments. "/" has none.
     *
     * @return list<string>
     */
    public static function segments(string $path): array
    {
        $trimmed = \trim($path, '/');

        return $trimmed === '' ? [] : \explode('/', $trimmed);
    }

    private function assertMutable(string $method): void
    {
        if ($this->frozen) {
            throw new RoutingException(\sprintf(
                'Cannot call %s() on route %s %s: the router has already compiled. '
                . 'Routes are configured when the module registers them and not afterwards.',
                $method,
                $this->method,
                $this->path,
            ));
        }
    }
}
