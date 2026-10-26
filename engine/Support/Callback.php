<?php

declare(strict_types=1);

namespace App\Engine\Support;

/**
 * One registered hook or filter callback.
 *
 * $sequence is a process-wide monotonic counter. It is what makes ordering
 * deterministic: two callbacks registered at the same priority always fire in
 * registration order, and registration order is itself fixed by module order.
 * Without it, ordering would depend on array internals.
 */
final class Callback
{
    private static int $counter = 0;

    public readonly int $sequence;

    public readonly string $handle;

    /**
     * @param \Closure|callable-string|array{0: object|class-string, 1: string} $callback
     * @param int|null $acceptedArgs null means "pass everything"
     */
    public function __construct(
        public readonly mixed $callback,
        public readonly int $priority = 10,
        public readonly ?string $module = null,
        public readonly ?int $acceptedArgs = null,
    ) {
        $this->sequence = ++self::$counter;
        $this->handle = 'cb' . $this->sequence;
    }

    /**
     * A stable, printable identity for the callback.
     *
     * Used both for removal by callable and for the debugging information the
     * console prints, so it has to be the same string for the same target every
     * time. Closures fall back to their object id, which is why removing a
     * closure requires the identical instance or its handle.
     */
    public function identity(): string
    {
        $callback = $this->callback;

        if (\is_string($callback)) {
            return $callback;
        }

        if (\is_array($callback)) {
            $target = $callback[0];
            $class = \is_object($target) ? $target::class : $target;

            return $class . '::' . $callback[1];
        }

        if ($callback instanceof \Closure) {
            return 'Closure#' . \spl_object_id($callback);
        }

        return \is_object($callback) ? $callback::class . '::__invoke' : \get_debug_type($callback);
    }

    /**
     * @param list<mixed> $arguments
     *
     * @return list<mixed>
     */
    public function limit(array $arguments): array
    {
        if ($this->acceptedArgs === null) {
            return $arguments;
        }

        return \array_slice($arguments, 0, \max(0, $this->acceptedArgs));
    }

    /**
     * Debugging information, as reported by the console and by listeners().
     *
     * @return array{handle: string, priority: int, module: string|null, callback: string, accepted_args: int|null}
     */
    public function describe(): array
    {
        return [
            'handle' => $this->handle,
            'priority' => $this->priority,
            'module' => $this->module,
            'callback' => $this->identity(),
            'accepted_args' => $this->acceptedArgs,
        ];
    }
}
