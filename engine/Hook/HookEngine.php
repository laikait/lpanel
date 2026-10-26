<?php

declare(strict_types=1);

namespace App\Engine\Hook;

use App\Engine\Support\Callback;
use App\Engine\Support\CallbackChain;

/**
 * Named events.
 *
 * A hook announces that something happened. Listeners react; their return values
 * are ignored entirely. If you want to change a value, that is a filter.
 *
 * WordPress-inspired, not WordPress-derived. Two behaviours differ deliberately:
 *
 *  - Iteration is over a snapshot taken when the hook fires. A listener that
 *    registers another listener for the hook currently running does not affect
 *    that run. WordPress does the opposite; determinism is worth more than the
 *    trick, and "did my listener run?" should not depend on registration timing.
 *
 *  - Recursion is capped. Two modules that fire each other's hooks would
 *    otherwise take the process down with a stack overflow rather than an error
 *    anyone can read.
 */
final class HookEngine
{
    public const MAX_DEPTH = 64;

    private readonly CallbackChain $chain;

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, int> */
    private array $depth = [];

    /** @var (\Closure(string, Callback, int): void)|null */
    private ?\Closure $observer = null;

    public function __construct()
    {
        $this->chain = new CallbackChain();
    }

    /**
     * @param \Closure|callable-string|array{0: object|class-string, 1: string} $callback
     *
     * @return string a handle that removes this exact registration
     */
    public function add(
        string $hook,
        mixed $callback,
        int $priority = 10,
        ?string $module = null,
        ?int $acceptedArgs = null,
    ): string {
        return $this->chain->add($hook, $callback, $priority, $module, $acceptedArgs);
    }

    /**
     * Fire a hook. Firing one nobody listens to is free and silent.
     */
    public function do(string $hook, mixed ...$arguments): void
    {
        $this->counts[$hook] = ($this->counts[$hook] ?? 0) + 1;

        $listeners = $this->chain->ordered($hook);

        if ($listeners === []) {
            return;
        }

        $depth = ($this->depth[$hook] ?? 0) + 1;

        if ($depth > self::MAX_DEPTH) {
            throw HookException::tooDeep($hook, self::MAX_DEPTH);
        }

        $this->depth[$hook] = $depth;

        try {
            // Two loops rather than a check per listener: with nothing observing,
            // firing a hook costs exactly what it did before the seam existed.
            if ($this->observer === null) {
                foreach ($listeners as $listener) {
                    /** @var callable $callable */
                    $callable = $listener->callback;
                    $callable(...$listener->limit(\array_values($arguments)));
                }

                return;
            }

            foreach ($listeners as $listener) {
                /** @var callable $callable */
                $callable = $listener->callback;
                $started = \hrtime(true);

                try {
                    $callable(...$listener->limit(\array_values($arguments)));
                } finally {
                    ($this->observer)($hook, $listener, \hrtime(true) - $started);
                }
            }
        } finally {
            $this->depth[$hook] = $depth - 1;
        }
    }

    /**
     * Be told how long each listener took.
     *
     * The seam instrumentation attaches to, and the only one: this engine does
     * not know what a profiler is. The observer is called after every listener,
     * including one that threw, with the hook's name, the listener and the
     * nanoseconds it ran for. It must not fire hooks itself. Null detaches.
     *
     * @param (\Closure(string, Callback, int): void)|null $observer
     */
    public function observe(?\Closure $observer): void
    {
        $this->observer = $observer;
    }

    public function remove(string $hook, mixed $callback, ?int $priority = null): bool
    {
        return $this->chain->remove($hook, $callback, $priority);
    }

    public function removeAll(?string $hook = null): void
    {
        $this->chain->removeAll($hook);
    }

    public function has(string $hook, mixed $callback = null): bool
    {
        return $this->chain->has($hook, $callback);
    }

    /** How many times this hook has been fired, whether or not anyone listened. */
    public function didCount(string $hook): int
    {
        return $this->counts[$hook] ?? 0;
    }

    /**
     * Debugging information: who is listening to $hook, in firing order.
     *
     * @return list<array<string, mixed>>
     */
    public function listeners(string $hook): array
    {
        return \array_map(
            static fn(Callback $callback): array => $callback->describe(),
            $this->chain->ordered($hook),
        );
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this->chain->names();
    }
}
