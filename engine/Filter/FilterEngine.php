<?php

declare(strict_types=1);

namespace App\Engine\Filter;

use App\Engine\Support\Callback;
use App\Engine\Support\CallbackChain;

/**
 * Value transformation.
 *
 * A filter takes a value, hands it to each listener in turn, and returns
 * whatever comes out the far end. Every listener must return a value; a filter
 * that does not return is the single most common mistake in this style of
 * extension, so debug mode catches it and says who did it.
 *
 * The conceptual split from hooks is strict and worth keeping strict:
 *
 *   Hook    something happened   -> listeners react, return values ignored
 *   Filter  here is a value      -> listeners transform, return value is the point
 *
 * Snapshot iteration and the recursion cap work exactly as they do for hooks.
 */
final class FilterEngine
{
    public const MAX_DEPTH = 64;

    private readonly CallbackChain $chain;

    /** @var array<string, int> */
    private array $depth = [];

    /** @var (\Closure(string, Callback, int): void)|null */
    private ?\Closure $observer = null;

    public function __construct(private bool $debug = false)
    {
        $this->chain = new CallbackChain();
    }

    /**
     * Debug mode is settable because configuration is resolved after the engine
     * is constructed; the engines are built before anything else so that module
     * loading has somewhere to register.
     */
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    /**
     * @param \Closure|callable-string|array{0: object|class-string, 1: string} $callback
     *
     * @return string a handle that removes this exact registration
     */
    public function add(
        string $filter,
        mixed $callback,
        int $priority = 10,
        ?string $module = null,
        ?int $acceptedArgs = null,
    ): string {
        return $this->chain->add($filter, $callback, $priority, $module, $acceptedArgs);
    }

    /**
     * Run $value through every listener. Extra arguments are context: they are
     * passed to every listener unchanged and are never themselves transformed.
     */
    public function apply(string $filter, mixed $value, mixed ...$arguments): mixed
    {
        $listeners = $this->chain->ordered($filter);

        if ($listeners === []) {
            return $value;
        }

        $depth = ($this->depth[$filter] ?? 0) + 1;

        if ($depth > self::MAX_DEPTH) {
            throw FilterException::tooDeep($filter, self::MAX_DEPTH);
        }

        $this->depth[$filter] = $depth;

        try {
            foreach ($listeners as $listener) {
                /** @var callable $callable */
                $callable = $listener->callback;

                if ($this->observer === null) {
                    /** @var mixed $result */
                    $result = $callable(...$listener->limit([$value, ...\array_values($arguments)]));
                } else {
                    $started = \hrtime(true);

                    try {
                        /** @var mixed $result */
                        $result = $callable(...$listener->limit([$value, ...\array_values($arguments)]));
                    } finally {
                        ($this->observer)($filter, $listener, \hrtime(true) - $started);
                    }
                }

                if ($this->debug && $result === null && $value !== null) {
                    throw FilterException::returnedNull($filter, $listener);
                }

                /** @var mixed $value */
                $value = $result;
            }
        } finally {
            $this->depth[$filter] = $depth - 1;
        }

        return $value;
    }

    /**
     * Be told how long each listener took.
     *
     * The same seam as HookEngine::observe(), with the same rules: called after
     * every listener including one that threw, never fires anything itself,
     * and null detaches. This engine does not know what is listening.
     *
     * @param (\Closure(string, Callback, int): void)|null $observer
     */
    public function observe(?\Closure $observer): void
    {
        $this->observer = $observer;
    }

    public function remove(string $filter, mixed $callback, ?int $priority = null): bool
    {
        return $this->chain->remove($filter, $callback, $priority);
    }

    public function removeAll(?string $filter = null): void
    {
        $this->chain->removeAll($filter);
    }

    public function has(string $filter, mixed $callback = null): bool
    {
        return $this->chain->has($filter, $callback);
    }

    /**
     * Debugging information: who transforms $filter, in application order.
     *
     * @return list<array<string, mixed>>
     */
    public function listeners(string $filter): array
    {
        return \array_map(
            static fn(Callback $callback): array => $callback->describe(),
            $this->chain->ordered($filter),
        );
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this->chain->names();
    }
}
