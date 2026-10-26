<?php

declare(strict_types=1);

namespace App\Engine\Cache\Stores;

use App\Engine\Cache\AtomicStore;
use App\Engine\Cache\CacheEntry;

/**
 * Memory, for the length of one process.
 *
 * The default, and not a placeholder. A request that renders a page resolves
 * the same template name from several partials, builds the same asset URL in a
 * layout and a view, and asks the same question of a repository more than once;
 * this makes the second of each free, costs nothing, writes nothing, and cannot
 * go stale because it does not outlive the answer.
 *
 * It is the default rather than the file store for the same reason the log has
 * no writers by default: a framework that starts writing files into a directory
 * nobody asked about is one that fills a disk on somebody else's machine.
 * Caching across requests is a deployment's decision, and CACHE_STORE=file is
 * how it says so.
 *
 * Nothing is serialised, so an entry hands back the same object it was given.
 * That is a genuine difference from every other store and it is worth knowing
 * about: code that mutates something it got from the cache will be seen to do
 * so here and not elsewhere. The conformance test for the file store covers the
 * other half.
 */
final class ArrayStore implements AtomicStore
{
    /** @var array<string, CacheEntry> */
    private array $entries = [];

    private int $hits = 0;

    private int $misses = 0;

    public function describe(): string
    {
        return 'array (this process only)';
    }

    public function get(string $key): ?CacheEntry
    {
        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            ++$this->misses;

            return null;
        }

        if ($entry->hasExpired()) {
            unset($this->entries[$key]);
            ++$this->misses;

            return null;
        }

        ++$this->hits;

        return $entry;
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->entries[$key] = new CacheEntry($value, $ttl === null ? null : \time() + $ttl);

        return true;
    }

    public function forget(string $key): bool
    {
        unset($this->entries[$key]);

        return true;
    }

    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        $existing = $this->entries[$key] ?? null;

        if ($existing !== null && !$existing->hasExpired()) {
            return false;
        }

        return $this->put($key, $value, $ttl);
    }

    public function forgetIf(string $key, mixed $value): bool
    {
        $existing = $this->entries[$key] ?? null;

        if ($existing === null || $existing->hasExpired() || $existing->value !== $value) {
            return false;
        }

        unset($this->entries[$key]);

        return true;
    }

    public function flush(string $prefix = ''): bool
    {
        if ($prefix === '') {
            $this->entries = [];

            return true;
        }

        foreach ($this->entries as $key => $_) {
            if (\str_starts_with($key, $prefix)) {
                unset($this->entries[$key]);
            }
        }

        return true;
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    /** @return array{hits: int, misses: int} */
    public function statistics(): array
    {
        return ['hits' => $this->hits, 'misses' => $this->misses];
    }
}
