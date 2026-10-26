<?php

declare(strict_types=1);

namespace App\Engine\Cache\Stores;

use App\Engine\Cache\CacheEntry;
use App\Engine\Cache\CacheStore;

/**
 * A cache that keeps nothing.
 *
 * Two jobs, both real. It is what CACHE_STORE=null selects, which is how
 * somebody debugging a stale-data problem takes the cache out of the picture
 * without editing any code. And it is the null object that lets everything else
 * take a Cache unconditionally: no nullable property, no "if the cache is
 * configured" branch in the asset manager, no second code path that only runs
 * in production and is therefore the one that breaks.
 *
 * remember() still computes and still returns, so an application on this store
 * is slower and correct. That property is worth a test of its own -- it is the
 * whole reason a null object is safe to default to.
 */
final class NullStore implements CacheStore
{
    public function describe(): string
    {
        return 'null (nothing is kept)';
    }

    public function get(string $key): ?CacheEntry
    {
        return null;
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        return false;
    }

    public function forget(string $key): bool
    {
        return true;
    }

    public function flush(string $prefix = ''): bool
    {
        return true;
    }
}
