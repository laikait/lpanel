<?php

declare(strict_types=1);

namespace App\Engine\Asset;

/**
 * Which directories are published, and under which logical names.
 *
 * The whole of the asset layer's authority lives here: a directory that is not
 * in this registry cannot be reached through any asset URL, whatever the path
 * says. That is the other half of "assets must never expose physical
 * application directories" -- the first half being that URLs name a namespace
 * rather than a directory, and this half being that the set of namespaces is
 * finite, enumerable and decided at boot.
 *
 * Modules do not ask to be published. A module that has an assets/ directory is
 * published; one that does not, is not. The module system registers them during
 * the Register stage, which is why nothing here scans the filesystem.
 */
final class AssetRegistry
{
    /** @var array<string, AssetSource> keyed by AssetSource::key() */
    private array $sources = [];

    /**
     * Publish a directory.
     *
     * Re-registering the same kind and name with the same root is a no-op
     * rather than an error, so that a double boot is harmless. Re-registering
     * it with a *different* root is refused: one namespace resolving to two
     * directories means a URL names both, and which one wins would come down to
     * registration order.
     */
    public function register(AssetSource $source): void
    {
        $existing = $this->sources[$source->key()] ?? null;

        if ($existing !== null && $existing->root !== $source->root) {
            throw AssetException::duplicateSource($existing);
        }

        $this->sources[$source->key()] = $source;
    }

    public function publish(AssetKind $kind, ?string $name, string $root): AssetSource
    {
        $source = new AssetSource($kind, $name, $root);
        $this->register($source);

        return $source;
    }

    public function has(AssetKind $kind, ?string $name = null): bool
    {
        return isset($this->sources[$this->key($kind, $name)]);
    }

    /** @throws AssetException when nothing is published under that name */
    public function source(AssetKind $kind, ?string $name = null): AssetSource
    {
        return $this->sources[$this->key($kind, $name)]
            ?? throw AssetException::unknownSource($kind, $name);
    }

    public function find(AssetKind $kind, ?string $name = null): ?AssetSource
    {
        return $this->sources[$this->key($kind, $name)] ?? null;
    }

    /** @return list<AssetSource> in key order, so listings are stable */
    public function all(): array
    {
        $sources = $this->sources;
        \ksort($sources);

        return \array_values($sources);
    }

    /** @return list<string> the names published under one kind */
    public function names(AssetKind $kind): array
    {
        $names = [];

        foreach ($this->all() as $source) {
            if ($source->kind === $kind && $source->name !== null) {
                $names[] = $source->name;
            }
        }

        return $names;
    }

    public function count(): int
    {
        return \count($this->sources);
    }

    public function clear(): void
    {
        $this->sources = [];
    }

    private function key(AssetKind $kind, ?string $name): string
    {
        return $name === null || $name === '' ? $kind->value : $kind->value . '/' . $name;
    }
}
