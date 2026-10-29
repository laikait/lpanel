<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cache;

use App\Engine\Cache\Cache;
use App\Engine\Cache\CacheException;
use App\Engine\Cache\Stores\ArrayStore;
use App\Engine\Cache\Stores\NullStore;
use App\Tests\Support\TestCase;

/**
 * The cache an application is handed.
 *
 * Everything here is behaviour a store does not have: key rules, namespaces, a
 * default lifetime and remember(). A backend implements five methods and
 * inherits all of it.
 */
final class CacheTest extends TestCase
{
    private ArrayStore $store;

    private Cache $cache;

    protected function setUp(): void
    {
        $this->store = new ArrayStore();
        $this->cache = new Cache($this->store);
    }

    // ---- the basics ---------------------------------------------------------

    public function test_a_value_goes_in_and_comes_out(): void
    {
        $this->cache->set('answer', 42);

        self::assertSame(42, $this->cache->get('answer'));
        self::assertTrue($this->cache->has('answer'));
    }

    public function test_a_missing_key_gives_the_default(): void
    {
        self::assertSame('fallback', $this->cache->get('nothing', 'fallback'));
        self::assertFalse($this->cache->has('nothing'));
    }

    /**
     * has() and get() !== null are different questions. Conflating them is how
     * a cached "no such customer" becomes a query on every request.
     */
    public function test_a_stored_null_is_not_a_missing_key(): void
    {
        $this->cache->set('known.absence', null);

        self::assertTrue($this->cache->has('known.absence'));
        self::assertNull($this->cache->get('known.absence', 'not this'));
    }

    public function test_deleting_removes_it(): void
    {
        $this->cache->set('k', 'v');
        $this->cache->delete('k');

        self::assertFalse($this->cache->has('k'));
    }

    // ---- remember -------------------------------------------------------------

    public function test_remember_computes_once(): void
    {
        $calls = 0;
        $compute = function () use (&$calls): string {
            ++$calls;

            return 'computed';
        };

        $first = $this->cache->remember('k', $compute);
        $second = $this->cache->remember('k', $compute);

        self::assertSame('computed', $first);
        self::assertSame('computed', $second, 'answered from the cache');
        self::assertSame(1, $calls);
    }

    /**
     * A computed null is stored, because "there is no outstanding invoice" is
     * usually exactly the answer somebody wanted cached.
     */
    public function test_remember_caches_a_computed_null(): void
    {
        $calls = 0;
        $compute = function () use (&$calls): ?string {
            ++$calls;

            return null;
        };

        self::assertNull($this->cache->remember('k', $compute));
        self::assertNull($this->cache->remember('k', $compute));
        self::assertSame(1, $calls, 'a null answer must not be recomputed forever');
    }

    public function test_remember_recomputes_once_the_entry_has_expired(): void
    {
        $this->cache->remember('k', static fn(): string => 'first', -1);

        self::assertSame('second', $this->cache->remember('k', static fn(): string => 'second'));
    }

    public function test_refresh_recomputes_whatever_is_stored(): void
    {
        $this->cache->set('k', 'stale');

        self::assertSame('fresh', $this->cache->refresh('k', static fn(): string => 'fresh'));
        self::assertSame('fresh', $this->cache->get('k'));
    }

    // ---- lifetimes ------------------------------------------------------------

    public function test_the_default_lifetime_applies_when_no_ttl_is_given(): void
    {
        (new Cache($this->store, defaultTtl: 60))->set('k', 'v');

        self::assertSame(60, $this->store->get('k')?->remaining());
    }

    public function test_an_explicit_ttl_beats_the_default(): void
    {
        (new Cache($this->store, defaultTtl: 60))->set('k', 'v', 5);

        self::assertSame(5, $this->store->get('k')?->remaining());
    }

    public function test_no_default_lifetime_means_it_does_not_expire(): void
    {
        $this->cache->set('k', 'v');

        self::assertNull($this->store->get('k')?->expiresAt);
    }

    // ---- namespaces -------------------------------------------------------------

    public function test_a_namespace_prefixes_the_key_the_store_sees(): void
    {
        $billing = $this->cache->namespace('billing');
        $billing->set('total', 10);

        self::assertSame('billing:total', $billing->qualify('total'));
        self::assertSame(10, $this->store->get('billing:total')?->value);
    }

    public function test_namespaces_compose(): void
    {
        self::assertSame(
            'billing.invoices:latest',
            $this->cache->namespace('billing')->namespace('invoices')->qualify('latest'),
        );
    }

    public function test_namespacing_leaves_the_original_alone(): void
    {
        $this->cache->namespace('billing')->set('total', 1);

        self::assertFalse($this->cache->has('total'), 'the parent must not see the child\'s keys');
    }

    public function test_clearing_a_namespace_leaves_the_rest(): void
    {
        $this->cache->set('top', 'kept');
        $billing = $this->cache->namespace('billing');
        $billing->set('total', 'removed');

        $billing->clear();

        self::assertFalse($billing->has('total'));
        self::assertSame('kept', $this->cache->get('top'));
    }

    public function test_clearing_the_root_clears_everything(): void
    {
        $this->cache->set('top', 'v');
        $this->cache->namespace('billing')->set('total', 'v');

        $this->cache->clear();

        self::assertSame(0, $this->store->count());
    }

    public function test_a_namespace_that_is_not_a_name_is_refused(): void
    {
        $this->expectException(CacheException::class);

        $this->cache->namespace('Billing Reports');
    }

    // ---- what a key may be --------------------------------------------------------

    public function test_the_allowed_characters_are_accepted(): void
    {
        $this->expectNotToPerformAssertions();

        foreach (['a', 'customers.total', 'user:12', 'a-b_c', 'A1'] as $key) {
            $this->cache->set($key, 1);
        }
    }

    /**
     * Keys are checked rather than escaped: one store makes a filename out of a
     * key and another puts it on a wire, and a single set of rules both can
     * keep beats two escaping schemes that disagree.
     */
    public function test_a_key_that_could_become_a_path_is_refused(): void
    {
        foreach (['../escape', 'with/slash', 'with\\backslash', 'with space', '', "null\0byte"] as $key) {
            try {
                $this->cache->set($key, 1);
                self::fail(\sprintf('"%s" was accepted as a cache key', $key));
            } catch (CacheException $e) {
                self::assertStringContainsString('unusable', $e->getMessage());
            }
        }
    }

    public function test_an_overlong_key_is_refused(): void
    {
        $this->expectException(CacheException::class);

        $this->cache->set(\str_repeat('k', Cache::MAX_KEY + 1), 1);
    }

    /**
     * Refused by Cache rather than by a store, because it is not a backend's
     * opinion: memory would take a closure happily and the file store would
     * fail on the same line in production.
     */
    public function test_a_value_that_cannot_leave_this_process_is_refused(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessageMatches('/Closure/');

        $this->cache->set('k', static fn(): int => 1);
    }

    public function test_a_resource_is_refused(): void
    {
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        try {
            $this->cache->set('k', $stream);
            self::fail('a resource was accepted');
        } catch (CacheException $e) {
            self::assertStringContainsString('cannot be stored', $e->getMessage());
        } finally {
            \fclose($stream);
        }
    }

    // ---- the null object -----------------------------------------------------------

    /**
     * The property that makes a null store safe to default to, and safe to
     * inject into anything: an application on it is slower and correct.
     */
    public function test_a_cache_that_keeps_nothing_still_answers(): void
    {
        $cache = new Cache(new NullStore());

        self::assertSame('computed', $cache->remember('k', static fn(): string => 'computed'));
        self::assertFalse($cache->has('k'));
        self::assertSame('default', $cache->get('k', 'default'));
        self::assertTrue($cache->delete('k'));
        self::assertTrue($cache->clear());
    }
}
