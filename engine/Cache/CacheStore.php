<?php

declare(strict_types=1);

namespace App\Engine\Cache;

/**
 * Somewhere values can be kept.
 *
 * Five methods, none of which mentions a file, a socket or a serialisation
 * format. That is what "abstract the backend" has to mean: an APCu store, a
 * Redis store and the file store here differ in every line of their bodies and
 * in none of their signatures.
 *
 * The keys arriving here are already validated and already carry their
 * namespace prefix -- Cache does both -- so a store is free to treat a key as
 * an opaque, safe string. It must not, however, assume the key is short or a
 * legal filename; FileStore hashes it for exactly that reason.
 *
 * TTL is relative seconds rather than an absolute timestamp, because that is
 * what every backend worth abstracting over takes natively. A store that needs
 * an absolute expiry works it out once; the alternative has every other store
 * working it back out.
 *
 * Implementations do not throw for an ordinary failure. A cache that is not
 * reachable is a slow application, not a broken one, so put() answers false and
 * get() answers null. The exceptions are refusals -- a value that cannot be
 * serialised at all is a programming mistake and says so.
 */
interface CacheStore
{
    /** One line for cache:clear and about, e.g. "file system/Cache/data". */
    public function describe(): string;

    /** The entry, or null when there is nothing usable under this key. */
    public function get(string $key): ?CacheEntry;

    /** @param int|null $ttl seconds from now, or null to keep it until something removes it */
    public function put(string $key, mixed $value, ?int $ttl = null): bool;

    public function forget(string $key): bool;

    /**
     * Remove everything under a prefix, or everything when the prefix is empty.
     *
     * Prefix-scoped rather than "flush the backend", because the backend is
     * usually shared. A store that cannot enumerate its own keys has to solve
     * this some other way -- which is the one part of a Redis or APCu store
     * that takes thought, and the reason neither is shipped half-written.
     */
    public function flush(string $prefix = ''): bool;
}
