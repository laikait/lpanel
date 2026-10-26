<?php

declare(strict_types=1);

namespace App\Engine\Cache;

/**
 * The cache an application is given.
 *
 * Injected, always. There is no Cache::get(), no cache() helper and no static
 * anything in this directory -- the specification bans a static cache API by
 * name, and an architecture test holds it. What that ban buys is ordinary: a
 * class that takes a Cache can be given one that stores nothing, or one that
 * counts what it was asked, and its test does not need a cache at all.
 *
 * The split is the same one the logging layer uses. A CacheStore is where bytes
 * go -- memory, a file, one day a Redis -- and this is the thing with the
 * behaviour: key rules, namespaces, a default lifetime, and remember(). Adding
 * a backend means writing five methods, not reimplementing any of this.
 *
 *     $this->cache->remember('customers.count', fn() => $this->query->total(), 300);
 *
 * A namespace is a prefix, and namespace() hands back another Cache rather than
 * mutating this one, so a module can hold its own without affecting whoever
 * gave it out:
 *
 *     $billing = $cache->namespace('billing');   // keys become "billing:..."
 *     $billing->clear();                         // and only those go
 */
final class Cache
{
    public const MAX_KEY = 128;

    public const KEY_PATTERN = '/^[A-Za-z0-9._:-]+$/';

    public const NAMESPACE_PATTERN = '/^[a-z0-9][a-z0-9._-]*$/';

    public const SEPARATOR = ':';

    public function __construct(
        private readonly CacheStore $store,
        public readonly string $namespace = '',
        /** Seconds. null means entries live until something removes them. */
        private readonly ?int $defaultTtl = null,
    ) {
        if ($namespace !== '' && \preg_match(self::NAMESPACE_PATTERN, $namespace) !== 1) {
            throw CacheException::unusableNamespace($namespace);
        }
    }

    public function store(): CacheStore
    {
        return $this->store;
    }

    public function defaultTtl(): ?int
    {
        return $this->defaultTtl;
    }

    /**
     * A cache of the same store, under a namespace.
     *
     * Nested namespaces compose, so a module handed its own can subdivide it
     * without knowing where it sits.
     */
    public function namespace(string $namespace): self
    {
        return new self(
            $this->store,
            $this->namespace === '' ? $namespace : $this->namespace . '.' . $namespace,
            $this->defaultTtl,
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $entry = $this->store->get($this->qualify($key));

        // Not $entry?->value ?? $default: a stored null is a hit, and coalescing
        // here would hand back the default for it and call that a miss.
        return $entry === null ? $default : $entry->value;
    }

    /**
     * Whether something is stored, including a stored null.
     *
     * has() and get() !== null are different questions, and conflating them is
     * how a cached "no such customer" turns into a query on every request.
     */
    public function has(string $key): bool
    {
        return $this->store->get($this->qualify($key)) !== null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $qualified = $this->qualify($key);

        // Refused here rather than in each store, because it is not a backend's
        // opinion: a value that cannot leave this process is not cacheable
        // anywhere, and the memory store would otherwise accept it happily and
        // the file store would fail on the same line in production.
        if ($value instanceof \Closure || \is_resource($value)) {
            throw CacheException::notStorable($key, \get_debug_type($value));
        }

        return $this->store->put($qualified, $value, $ttl ?? $this->defaultTtl);
    }

    public function delete(string $key): bool
    {
        return $this->store->forget($this->qualify($key));
    }

    /** Everything in this namespace, and nothing outside it. */
    public function clear(): bool
    {
        return $this->store->flush($this->namespace === '' ? '' : $this->namespace . self::SEPARATOR);
    }

    /**
     * The value, computed once.
     *
     * The pattern the specification asks for, and the only one most code needs.
     * What matters here is what it does with a computed null: it stores it.
     * Anything else turns "this customer has no outstanding invoice" into a
     * query that is never cached, which is usually the query somebody wanted
     * cached.
     *
     * @template T
     *
     * @param \Closure(): T $compute
     *
     * @return T
     */
    public function remember(string $key, \Closure $compute, ?int $ttl = null): mixed
    {
        $qualified = $this->qualify($key);
        $entry = $this->store->get($qualified);

        if ($entry !== null) {
            /** @var T */
            return $entry->value;
        }

        $value = $compute();

        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * Compute it again whatever is stored, and store the answer.
     *
     * For the caller that has just changed the thing being cached and knows the
     * cached copy is wrong.
     *
     * @template T
     *
     * @param \Closure(): T $compute
     *
     * @return T
     */
    public function refresh(string $key, \Closure $compute, ?int $ttl = null): mixed
    {
        $value = $compute();

        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * A lock named $name, held for at most $seconds. See Lock.
     *
     * @param ?string $owner another holder's token, to release a lock taken elsewhere
     *
     * @throws CacheException when the store cannot lock (the null store, or one of your own without AtomicStore)
     */
    public function lock(string $name, int $seconds, ?string $owner = null): Lock
    {
        if (!$this->store instanceof AtomicStore) {
            throw CacheException::cannotLock($this->store->describe());
        }

        return new Lock($this->store, $this->qualify(Lock::PREFIX . $name), \max(1, $seconds), $owner);
    }

    /** The key as the store sees it, namespace included. */
    public function qualify(string $key): string
    {
        $this->assertUsable($key);

        return $this->namespace === '' ? $key : $this->namespace . self::SEPARATOR . $key;
    }

    /**
     * Keys are checked rather than escaped.
     *
     * A key becomes a filename in one store and part of a wire protocol in
     * another, and the set of characters that is safe in both is small enough
     * to simply require. Escaping instead would mean every store agreeing on
     * the same escaping, which is a thing to get subtly wrong per backend.
     */
    private function assertUsable(string $key): void
    {
        if ($key === '') {
            throw CacheException::unusableKey($key, 'it is empty');
        }

        if (\strlen($key) > self::MAX_KEY) {
            throw CacheException::unusableKey($key, 'it is longer than ' . self::MAX_KEY . ' characters');
        }

        if (\preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw CacheException::unusableKey($key, 'it contains something other than the allowed characters');
        }
    }
}
