<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cache;

use App\Engine\Cache\AtomicStore;
use App\Engine\Cache\Cache;
use App\Engine\Cache\CacheException;
use App\Engine\Cache\CacheStore;
use App\Engine\Cache\CacheTableMigration;
use App\Engine\Cache\Stores\ArrayStore;
use App\Engine\Cache\Stores\DatabaseStore;
use App\Engine\Cache\Stores\FileStore;
use App\Engine\Cache\Stores\NullStore;
use App\Engine\Database\Connection;
use App\Engine\Support\Sealer;
use App\Tests\Support\TestCase;
use App\Tests\Support\TestDatabases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;

/**
 * What it means to be a store, asserted against every store there is.
 *
 * This is the test that makes "abstract the backend" a claim rather than a
 * hope. Each case runs against memory and against files, and a Redis or APCu
 * store added later becomes conformant by being added to one provider here and
 * passing without changing a line of it.
 *
 * That matters more than it looks. The interesting disagreements between cache
 * backends are not in the obvious methods -- they are in whether a stored null
 * is a hit, whether an expired entry is a miss or an error, whether clearing a
 * namespace clears anything else, and whether a value survives being stored as
 * the same value. Every one of those is below.
 */
final class StoreConformanceTest extends TestCase
{
    /** Its own name, so a server's real cache table is never touched. */
    private const TABLE = 'laika_test_cache';

    /** @var list<string> */
    private static array $directories = [];

    /** @var list<Connection> */
    private static array $connections = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$directories as $directory) {
            if (!\is_dir($directory)) {
                continue;
            }

            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($entries as $entry) {
                if ($entry instanceof \SplFileInfo) {
                    $entry->isDir() ? @\rmdir($entry->getPathname()) : @\unlink($entry->getPathname());
                }
            }

            @\rmdir($directory);
        }

        self::$directories = [];

        foreach (self::$connections as $connection) {
            // A server keeps the table after the test; SQLite in memory does not.
            if ($connection->driver() !== 'sqlite' && $connection->tables()->exists(self::TABLE)) {
                $connection->tables()->drop(self::TABLE);
            }

            $connection->disconnect();
        }

        self::$connections = [];
    }

    /** @return array<string, array{\Closure(): CacheStore}> */
    public static function stores(): array
    {
        return [
            'array' => [static fn(): CacheStore => new ArrayStore()],
            'file' => [static function (): CacheStore {
                $directory = \sys_get_temp_dir() . '/cache-store-' . \bin2hex(\random_bytes(6));
                self::$directories[] = $directory;

                return new FileStore($directory);
            }],
            // Every behaviour again with an APP_KEY, so sealing changes none of
            // them -- forgetIf() included, which finds a row by its stored bytes.
            'file, sealed' => [static function (): CacheStore {
                $directory = \sys_get_temp_dir() . '/cache-store-' . \bin2hex(\random_bytes(6));
                self::$directories[] = $directory;

                return new FileStore($directory, sealer: new Sealer(\random_bytes(32)));
            }],
            ...self::databaseStores(),
            ...self::databaseStores(new Sealer(\random_bytes(32))),
        ];
    }

    /**
     * The database store on every database a run can reach: SQLite always,
     * and each server TestDatabases names. The table comes from the migration
     * `migrate` runs, under a name of its own so a real table is never touched.
     *
     * @return array<string, array{\Closure(): CacheStore}>
     */
    private static function databaseStores(Sealer $sealer = new Sealer()): array
    {
        $stores = [];

        foreach (TestDatabases::available() as $driver => [$config]) {
            $name = 'database on ' . $driver . ($sealer->isActive() ? ', sealed' : '');
            $stores[$name] = [static function () use ($config, $sealer): CacheStore {
                $connection = new Connection($config);
                self::$connections[] = $connection;

                $tables = $connection->tables();
                $migration = new CacheTableMigration(self::TABLE);

                if ($tables->exists(self::TABLE)) {
                    $migration->down($tables);
                }

                $migration->up($tables);

                return new DatabaseStore(static fn(): Connection => $connection, self::TABLE, $sealer);
            }];
        }

        return $stores;
    }

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_a_value_comes_back(\Closure $make): void
    {
        $store = $make();
        $store->put('greeting', 'hello');

        $entry = $store->get('greeting');

        self::assertNotNull($entry);
        self::assertSame('hello', $entry->value);
    }

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_a_key_that_was_never_set_is_a_miss(\Closure $make): void
    {
        self::assertNull($make()->get('never.set'));
    }

    /**
     * The distinction most cache APIs lose, and the one that costs the most.
     *
     * A store whose miss and whose stored null look the same turns remember()
     * around a lookup that legitimately answers null into a query on every
     * single request, silently, while appearing to work.
     *
     * @param \Closure(): CacheStore $make
     */
    #[DataProvider('stores')]
    public function test_a_stored_null_is_a_hit(\Closure $make): void
    {
        $store = $make();
        $store->put('nothing', null);

        $entry = $store->get('nothing');

        self::assertNotNull($entry, 'a stored null must not read as a miss');
        self::assertNull($entry->value);
    }

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_values_of_every_ordinary_shape_survive(\Closure $make): void
    {
        $store = $make();

        foreach ([
            'int' => 42,
            'float' => 1.5,
            'false' => false,
            'zero' => 0,
            'empty string' => '',
            'list' => [1, 2, 3],
            'map' => ['a' => ['b' => 'c']],
            'empty array' => [],
        ] as $label => $value) {
            $store->put('probe', $value);

            $entry = $store->get('probe');

            self::assertNotNull($entry, $label);
            self::assertSame($value, $entry->value, $label);
        }
    }

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_writing_again_replaces(\Closure $make): void
    {
        $store = $make();
        $store->put('k', 'first');
        $store->put('k', 'second');

        self::assertSame('second', $store->get('k')?->value);
    }

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_forgetting_removes_it(\Closure $make): void
    {
        $store = $make();
        $store->put('k', 'value');
        $store->forget('k');

        self::assertNull($store->get('k'));
    }

    /** Forgetting something that was never there is not a failure. */
    #[DataProvider('stores')]
    public function test_forgetting_what_is_not_there_succeeds(\Closure $make): void
    {
        self::assertTrue($make()->forget('never.set'));
    }

    // ---- expiry ------------------------------------------------------------

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_an_entry_within_its_ttl_is_still_there(\Closure $make): void
    {
        $store = $make();
        $store->put('k', 'value', 60);

        self::assertSame('value', $store->get('k')?->value);
    }

    /**
     * A negative TTL is already in the past, which is how this gets tested
     * without a sleep.
     *
     * @param \Closure(): CacheStore $make
     */
    #[DataProvider('stores')]
    public function test_an_expired_entry_is_a_miss(\Closure $make): void
    {
        $store = $make();
        $store->put('k', 'value', -1);

        self::assertNull($store->get('k'), 'an expired entry must read as a miss, not as a value');
    }

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_an_entry_with_no_ttl_does_not_expire(\Closure $make): void
    {
        $store = $make();
        $store->put('k', 'value');

        $entry = $store->get('k');

        self::assertNotNull($entry);
        self::assertNull($entry->expiresAt);
        self::assertNull($entry->remaining());
    }

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_a_ttl_is_reported_as_time_remaining(\Closure $make): void
    {
        $store = $make();
        $store->put('k', 'value', 60);

        $remaining = $store->get('k')?->remaining();

        self::assertNotNull($remaining);
        self::assertGreaterThan(0, $remaining);
        self::assertLessThanOrEqual(60, $remaining);
    }

    // ---- namespaces --------------------------------------------------------

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_flushing_everything_empties_it(\Closure $make): void
    {
        $store = $make();
        $store->put('a', 1);
        $store->put('billing:b', 2);

        $store->flush();

        self::assertNull($store->get('a'));
        self::assertNull($store->get('billing:b'));
    }

    /**
     * The reason a namespace exists at all: clearing one module's cached
     * answers must not clear anybody else's.
     *
     * @param \Closure(): CacheStore $make
     */
    #[DataProvider('stores')]
    public function test_flushing_a_prefix_leaves_everything_else(\Closure $make): void
    {
        $store = $make();
        $store->put('billing:invoice', 'kept elsewhere');
        $store->put('billing.reports:total', 'also billing-ish');
        $store->put('assets:app.css', 'not billing');
        $store->put('unnamespaced', 'not billing either');

        $store->flush('billing:');

        self::assertNull($store->get('billing:invoice'));
        self::assertSame('not billing', $store->get('assets:app.css')?->value);
        self::assertSame('not billing either', $store->get('unnamespaced')?->value);
        self::assertSame(
            'also billing-ish',
            $store->get('billing.reports:total')?->value,
            'a prefix is a namespace, not a string match: billing.reports is not inside billing',
        );
    }

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_flushing_a_prefix_with_nothing_under_it_succeeds(\Closure $make): void
    {
        self::assertTrue($make()->flush('never.used:'));
    }

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_every_store_can_say_what_it_is(\Closure $make): void
    {
        self::assertNotSame('', $make()->describe());
    }

    // ---- atomic: what a lock is built on --------------------------------------------

    /** @param \Closure(): CacheStore $make */
    private function atomic(\Closure $make): AtomicStore
    {
        $store = $make();
        self::assertInstanceOf(AtomicStore::class, $store, 'every shipped store but null can lock');

        return $store;
    }

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_add_takes_a_free_key_only(\Closure $make): void
    {
        $store = $this->atomic($make);

        self::assertTrue($store->add('ns:lock.a', 'first', 60));
        self::assertFalse($store->add('ns:lock.a', 'second', 60), 'held');
        self::assertSame('first', $store->get('ns:lock.a')?->value);
    }

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_add_takes_an_expired_key(\Closure $make): void
    {
        $store = $this->atomic($make);
        $store->put('ns:lock.b', 'stale', -1);

        self::assertTrue($store->add('ns:lock.b', 'fresh', 60));
        self::assertSame('fresh', $store->get('ns:lock.b')?->value);
    }

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_forget_if_removes_only_the_holders_value(\Closure $make): void
    {
        $store = $this->atomic($make);
        $store->add('ns:lock.c', 'mine', 60);

        self::assertFalse($store->forgetIf('ns:lock.c', 'yours'));
        self::assertSame('mine', $store->get('ns:lock.c')?->value);
        self::assertTrue($store->forgetIf('ns:lock.c', 'mine'));
        self::assertNull($store->get('ns:lock.c'));
        self::assertFalse($store->forgetIf('ns:lock.c', 'mine'), 'already gone');
    }

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_a_lock_is_exclusive_and_released_by_its_holder(\Closure $make): void
    {
        $cache = new Cache($this->atomic($make), namespace: 'jobs');
        $first = $cache->lock('report', 60);
        $second = $cache->lock('report', 60);

        self::assertTrue($first->get());
        self::assertFalse($second->get());
        self::assertTrue($second->isHeld());
        self::assertFalse($second->release(), 'not its lock to release');
        self::assertTrue($first->release());
        self::assertTrue($second->get());

        // Another process may release it with the holder's token.
        self::assertTrue($cache->lock('report', 60, $second->owner())->release());
        self::assertFalse($second->isHeld());
    }

    /** @param \Closure(): CacheStore $make */
    #[DataProvider('stores')]
    public function test_block_runs_the_callback_holding_the_lock_and_releases_it(\Closure $make): void
    {
        $cache = new Cache($this->atomic($make));

        self::assertSame('done', $cache->lock('task', 60)->block(1, static fn(): string => 'done'));
        self::assertFalse($cache->lock('task', 60)->isHeld());

        $cache->lock('task', 60)->get();
        $this->expectException(CacheException::class);
        $cache->lock('task', 60)->block(0.1);
    }

    public function test_the_null_store_refuses_to_lock(): void
    {
        $this->expectException(CacheException::class);
        (new Cache(new NullStore()))->lock('x', 10);
    }

    /**
     * The file store across processes: twenty children race for one key, and
     * exactly one wins.
     */
    #[RequiresOperatingSystemFamily('Linux')]
    public function test_the_file_store_lock_holds_across_processes(): void
    {
        $directory = \sys_get_temp_dir() . '/cache-race-' . \bin2hex(\random_bytes(6));
        self::$directories[] = $directory;
        $autoload = \dirname(__DIR__, 3) . '/vendor/autoload.php';
        $script = 'require ' . \var_export($autoload, true) . '; echo (new App\\Engine\\Cache\\Stores\\FileStore(' . \var_export($directory, true) . '))->add("ns:lock.race", getmypid(), 60) ? "won" : "lost";';

        $processes = [];

        for ($i = 0; $i < 20; ++$i) {
            $process = \proc_open([\PHP_BINARY, '-r', $script], [1 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $processes[] = $process;
            $outputs[$i] = $pipes[1];
        }

        $results = [];

        foreach ($processes as $i => $process) {
            $results[] = \stream_get_contents($outputs[$i]);
            \proc_close($process);
        }

        self::assertSame(1, \count(\array_keys($results, 'won', true)), \implode(',', $results));
    }
}
