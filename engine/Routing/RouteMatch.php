<?php

declare(strict_types=1);

namespace App\Engine\Routing;

/**
 * The result of asking the router about a method and a path.
 *
 * Readonly, because it travels through a filter and several listeners; a
 * mutable match would make "what actually got dispatched?" unanswerable.
 */
final class RouteMatch
{
    /**
     * @param array<string, string> $parameters
     * @param list<string>          $allowedMethods populated only for MethodNotAllowed
     */
    private function __construct(
        public readonly MatchStatus $status,
        public readonly ?Route $route = null,
        public readonly array $parameters = [],
        public readonly array $allowedMethods = [],
    ) {}

    /** @param array<string, string> $parameters */
    public static function matched(Route $route, array $parameters = []): self
    {
        return new self(MatchStatus::Matched, $route, $parameters);
    }

    public static function notFound(): self
    {
        return new self(MatchStatus::NotFound);
    }

    /** @param list<string> $allowed */
    public static function methodNotAllowed(array $allowed): self
    {
        \sort($allowed);

        return new self(MatchStatus::MethodNotAllowed, null, [], $allowed);
    }

    public function isMatched(): bool
    {
        return $this->status === MatchStatus::Matched;
    }

    public function parameter(string $name, ?string $default = null): ?string
    {
        return $this->parameters[$name] ?? $default;
    }

    /** @param array<string, string> $parameters */
    public function withParameters(array $parameters): self
    {
        return new self($this->status, $this->route, $parameters, $this->allowedMethods);
    }
}
