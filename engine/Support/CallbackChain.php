<?php

declare(strict_types=1);

namespace App\Engine\Support;

use App\Engine\Error\FrameworkException;

/**
 * Priority-ordered callback storage.
 *
 * Internal to the hook and filter engines. It holds the ~70 lines of ordering
 * mechanics both need while their public APIs stay completely distinct: a hook
 * announces that something happened, a filter transforms a value, and nothing
 * about that difference lives here.
 *
 * The sort key is (priority ascending, registration sequence ascending), which
 * makes ordering total and stable rather than dependent on array internals.
 */
final class CallbackChain
{
    /** @var array<string, list<Callback>> */
    private array $callbacks = [];

    /** @var array<string, list<Callback>> sorted view, invalidated on any write */
    private array $sorted = [];

    /**
     * @param \Closure|callable-string|array{0: object|class-string, 1: string} $callback
     *
     * @return string the handle, which removes this exact registration
     */
    public function add(
        string $name,
        mixed $callback,
        int $priority = 10,
        ?string $module = null,
        ?int $acceptedArgs = null,
    ): string {
        self::assertInvokable($name, $callback, $module);

        $entry = new Callback($callback, $priority, $module, $acceptedArgs);

        $this->callbacks[$name][] = $entry;
        unset($this->sorted[$name]);

        return $entry->handle;
    }

    public function has(string $name, mixed $callback = null): bool
    {
        if (!isset($this->callbacks[$name]) || $this->callbacks[$name] === []) {
            return false;
        }

        if ($callback === null) {
            return true;
        }

        return $this->find($name, $callback) !== [];
    }

    /**
     * Remove by handle, or by the callable itself.
     *
     * Removing by callable removes every registration of that target unless a
     * priority is given. Closures can only be removed by handle or by passing
     * the identical instance, because two identical-looking closures are not
     * the same callback.
     */
    public function remove(string $name, mixed $callback, ?int $priority = null): bool
    {
        $matches = $this->find($name, $callback, $priority);

        if ($matches === []) {
            return false;
        }

        $this->callbacks[$name] = \array_values(\array_filter(
            $this->callbacks[$name] ?? [],
            static fn(Callback $entry): bool => !\in_array($entry, $matches, true),
        ));

        unset($this->sorted[$name]);

        return true;
    }

    public function removeAll(?string $name = null): void
    {
        if ($name === null) {
            $this->callbacks = [];
            $this->sorted = [];

            return;
        }

        unset($this->callbacks[$name], $this->sorted[$name]);
    }

    /**
     * The callbacks for $name, in firing order.
     *
     * The sorted list is memoised per name and thrown away whenever that name is
     * written to, so the sort cost is paid once per name rather than per fire.
     *
     * @return list<Callback>
     */
    public function ordered(string $name): array
    {
        if (isset($this->sorted[$name])) {
            return $this->sorted[$name];
        }

        $entries = $this->callbacks[$name] ?? [];

        \usort(
            $entries,
            static fn(Callback $a, Callback $b): int => [$a->priority, $a->sequence] <=> [$b->priority, $b->sequence],
        );

        return $this->sorted[$name] = $entries;
    }

    /** @return list<string> */
    public function names(): array
    {
        $names = [];

        foreach ($this->callbacks as $name => $entries) {
            if ($entries !== []) {
                $names[] = $name;
            }
        }

        \sort($names);

        return $names;
    }

    /**
     * @return list<Callback>
     */
    private function find(string $name, mixed $callback, ?int $priority = null): array
    {
        $entries = $this->callbacks[$name] ?? [];

        if ($entries === []) {
            return [];
        }

        $matches = [];

        foreach ($entries as $entry) {
            if ($priority !== null && $entry->priority !== $priority) {
                continue;
            }

            if ($this->identifies($entry, $callback)) {
                $matches[] = $entry;
            }
        }

        return $matches;
    }

    private function identifies(Callback $entry, mixed $callback): bool
    {
        // A handle removes exactly one registration, which is the only way to
        // remove one of several identical-looking closures.
        if (\is_string($callback) && $entry->handle === $callback) {
            return true;
        }

        if ($entry->callback === $callback) {
            return true;
        }

        return \is_string($callback) || \is_array($callback)
            ? $entry->identity() === self::identityOf($callback)
            : false;
    }

    private static function identityOf(mixed $callback): string
    {
        return (new Callback($callback))->identity();
    }

    /**
     * Reject a callback that cannot actually be called, at the point of
     * registration rather than at the point of firing.
     *
     * The case worth catching is [SomeClass::class, 'method'] naming an
     * INSTANCE method. That is ordinary PHP callable syntax for a static call,
     * so PHP would fatal when the hook fired -- possibly weeks later, in
     * production, in an unrelated request. The engines deliberately know
     * nothing about the container, so the fix is not to resolve it here but to
     * point the author at the place where dependencies are available.
     */
    private static function assertInvokable(string $name, mixed $callback, ?string $module): void
    {
        if (\is_callable($callback)) {
            return;
        }

        if (\is_array($callback) && \count($callback) === 2
            && \is_string($callback[0]) && \is_string($callback[1])
            && \method_exists($callback[0], $callback[1])
        ) {
            throw new FrameworkException(\sprintf(
                '%s::%s() is not static, so [%s::class, \'%s\'] cannot be used as a listener for "%s"%s. '
                . 'Either make the method static, or register the listener from onBoot(), where the instance '
                . 'can be injected:'
                . "\n\n"
                . '    $module->onBoot(static function (%s $service, HookEngine $hooks): void {' . "\n"
                . '        $hooks->add(\'%s\', [$service, \'%s\'], 10, %s);' . "\n"
                . '    });' . "\n",
                $callback[0],
                $callback[1],
                $callback[0],
                $callback[1],
                $name,
                $module === null ? '' : \sprintf(' by module "%s"', $module),
                $callback[0],
                $name,
                $callback[1],
                $module === null ? 'null' : \sprintf("'%s'", $module),
            ));
        }

        throw new FrameworkException(\sprintf(
            'The listener registered for "%s"%s is not callable (%s).',
            $name,
            $module === null ? '' : \sprintf(' by module "%s"', $module),
            \get_debug_type($callback),
        ));
    }
}
