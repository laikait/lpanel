<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session;

use App\Engine\Database\Connection;
use App\Engine\Session\SessionException;
use App\Engine\Session\SessionId;
use App\Engine\Session\SessionRecord;
use App\Engine\Session\SessionStore;
use App\Engine\Session\SessionTableMigration;
use App\Engine\Session\Stores\ArrayStore;
use App\Engine\Session\Stores\DatabaseStore;
use App\Engine\Session\Stores\FileStore;
use App\Tests\Support\TestCase;
use App\Tests\Support\TestDatabases;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What it means to store a session, asserted against every store there is.
 *
 * The fifth conformance suite, after the cache's, the queue's, the schedule
 * lock's and the counters'. The reason this one matters more than most: a
 * session store is the piece an application swaps late -- one machine becomes
 * three, the file store becomes the database store -- and it swaps on the day
 * the traffic arrived. Whatever differences exist between the two are found
 * then.
 *
 * So every behaviour the manager relies on is pinned here once and run against
 * all of them: that commit() sees what is currently stored rather than what the
 * caller last read, that returning null from the closure deletes, that an id
 * this framework did not issue is refused before it reaches a filename or a
 * query, and that a payload which cannot be encoded is refused by the memory
 * store exactly as loudly as by the ones that have to write it down.
 *
 * A Redis store added later becomes conformant by appearing in one provider
 * here and passing without a line of this file changing.
 */
final class StoreConformanceTest extends TestCase
{
    /** Its own name, so a server's real sessions table is never touched. */
    private const TABLE = 'laika_test_sessions';

    /** @var list<string> */
    private static array $directories = [];

    /** @var list<Connection> */
    private static array $connections = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$directories as $directory) {
            foreach (\glob($directory . \DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @\unlink($file);
            }

            @\rmdir($directory);
        }

        foreach (self::$connections as $connection) {
            // A server keeps the table after the test; SQLite in memory does not.
            if ($connection->driver() !== 'sqlite' && $connection->tables()->exists(self::TABLE)) {
                $connection->tables()->drop(self::TABLE);
            }

            $connection->disconnect();
        }

        self::$directories = [];
        self::$connections = [];
    }

    /** @return array<string, array{\Closure(): SessionStore}> */
    public static function stores(): array
    {
        return [
            'memory' => [static fn(): SessionStore => new ArrayStore()],
            'file' => [static function (): SessionStore {
                $directory = \sys_get_temp_dir() . '/sessions-' . \bin2hex(\random_bytes(6));
                self::$directories[] = $directory;

                return new FileStore($directory);
            }],
            ...self::databaseStores(),
        ];
    }

    /**
     * The database store on every database a run can reach: SQLite always,
     * and each server TestDatabases names. The table comes from the migration
     * `migrate` runs, not from a statement written out again here -- if the
     * two ever disagreed, production would be the one to find out.
     *
     * @return array<string, array{\Closure(): SessionStore}>
     */
    private static function databaseStores(): array
    {
        $stores = [];

        foreach (TestDatabases::available() as $driver => [$config]) {
            $stores[$driver === 'sqlite' ? 'database' : 'database on ' . $driver] = [static function () use ($config): SessionStore {
                $connection = new Connection($config);
                self::$connections[] = $connection;

                $tables = $connection->tables();
                $migration = new SessionTableMigration(self::TABLE);

                if ($tables->exists(self::TABLE)) {
                    $migration->down($tables);
                }

                $migration->up($tables);

                return new DatabaseStore($connection, self::TABLE);
            }];
        }

        return $stores;
    }

    private static function id(): string
    {
        return SessionId::generate();
    }

    // ---- reading ---------------------------------------------------------

    /** @param \Closure(): SessionStore $make */
    #[DataProvider('stores')]
    public function test_an_unknown_id_reads_as_nothing(\Closure $make): void
    {
        self::assertNull($make()->read(self::id()));
    }

    /**
     * Before a filename is built or a query is run.
     *
     * The id comes from a cookie, so it is attacker-controlled. "../../etc/
     * passwd" reaching a path join is the whole of the traversal class of bug,
     * and the answer is not to escape it but to refuse anything that is not one
     * of the ids this framework issues.
     *
     * @param \Closure(): SessionStore $make
     */
    #[DataProvider('stores')]
    public function test_an_id_this_framework_did_not_issue_is_refused(\Closure $make): void
    {
        $store = $make();

        foreach (['', '../../etc/passwd', 'short', \str_repeat('z', 64), \strtoupper(self::id())] as $id) {
            self::assertNull($store->read($id), $id);
            self::assertNull($store->commit($id, static fn(): SessionRecord => SessionRecord::fresh(self::id())));
        }
    }

    // ---- committing ------------------------------------------------------

    /** @param \Closure(): SessionStore $make */
    #[DataProvider('stores')]
    public function test_a_commit_creates_and_reads_back(\Closure $make): void
    {
        $store = $make();
        $id = self::id();

        $store->commit($id, static fn(): SessionRecord => SessionRecord::fresh($id, ['user' => 7]));

        $record = $store->read($id);

        self::assertNotNull($record);
        self::assertSame($id, $record->id);
        self::assertSame(['user' => 7], $record->payload);
    }

    /**
     * The property the whole interface exists for.
     *
     * The closure is handed what is stored NOW, not what the caller read a
     * moment ago. Two requests that each set a different key both survive,
     * which is the concurrent case that a read-then-write-the-whole-array
     * design loses silently.
     *
     * @param \Closure(): SessionStore $make
     */
    #[DataProvider('stores')]
    public function test_a_commit_sees_what_is_stored_rather_than_what_was_read(\Closure $make): void
    {
        $store = $make();
        $id = self::id();

        $store->commit($id, static fn(): SessionRecord => SessionRecord::fresh($id, ['cart' => 3]));

        $seen = null;

        $store->commit($id, static function (?SessionRecord $current) use (&$seen, $id): SessionRecord {
            $seen = $current?->payload;

            return SessionRecord::fresh($id, \array_merge($current->payload ?? [], ['locale' => 'fr']));
        });

        self::assertSame(['cart' => 3], $seen, 'the closure was not shown the stored record');

        $record = $store->read($id);

        self::assertNotNull($record);
        self::assertSame(['cart' => 3, 'locale' => 'fr'], $record->payload);
    }

    /** @param \Closure(): SessionStore $make */
    #[DataProvider('stores')]
    public function test_returning_null_from_a_commit_removes_the_session(\Closure $make): void
    {
        $store = $make();
        $id = self::id();

        $store->commit($id, static fn(): SessionRecord => SessionRecord::fresh($id, ['a' => 1]));

        self::assertNull($store->commit($id, static fn(): ?SessionRecord => null));
        self::assertNull($store->read($id));
    }

    /** @param \Closure(): SessionStore $make */
    #[DataProvider('stores')]
    public function test_a_commit_on_nothing_is_shown_null(\Closure $make): void
    {
        $seen = 'not called';

        $make()->commit(self::id(), static function (?SessionRecord $current) use (&$seen): ?SessionRecord {
            $seen = $current;

            return null;
        });

        self::assertNull($seen);
    }

    // ---- the wire format -------------------------------------------------

    /**
     * The memory store refuses what the file store would refuse.
     *
     * Without this, a test suite running against memory happily accepts a model
     * object in the session and the application fails on the first machine with
     * a real store. "It worked in tests" has to mean something.
     *
     * @param \Closure(): SessionStore $make
     */
    #[DataProvider('stores')]
    public function test_a_payload_that_cannot_be_encoded_is_refused_by_every_store(\Closure $make): void
    {
        $store = $make();
        $id = self::id();

        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('JSON-serialisable');

        $store->commit(
            $id,
            static fn(): SessionRecord => SessionRecord::fresh($id, ['handle' => \fopen('php://memory', 'rb')]),
        );
    }

    /** @param \Closure(): SessionStore $make */
    #[DataProvider('stores')]
    public function test_the_timestamps_survive_a_round_trip(\Closure $make): void
    {
        $store = $make();
        $id = self::id();

        $store->commit($id, static fn(): SessionRecord => new SessionRecord($id, [], 1000, 2000));

        $record = $store->read($id);

        self::assertNotNull($record);
        self::assertSame(1000, $record->createdAt);
        self::assertSame(2000, $record->touchedAt);
    }

    /** @param \Closure(): SessionStore $make */
    #[DataProvider('stores')]
    public function test_a_successor_survives_a_round_trip(\Closure $make): void
    {
        $store = $make();
        $id = self::id();
        $next = self::id();

        $store->commit($id, static fn(): SessionRecord => SessionRecord::fresh($id)->replacedBy($next));

        $record = $store->read($id);

        self::assertNotNull($record);
        self::assertTrue($record->isPointer());
        self::assertSame($next, $record->successor);
    }

    /**
     * Everything a store physically holds, as one string: the array, every
     * file's name and contents, or every row of the table.
     */
    private static function rawStorage(SessionStore $store): string
    {
        $read = static fn(object $object, string $property): mixed => (new \ReflectionProperty($object, $property))->getValue($object);

        if ($store instanceof ArrayStore) {
            return \json_encode($read($store, 'records'), \JSON_THROW_ON_ERROR);
        }

        if ($store instanceof FileStore) {
            $raw = '';

            foreach (\glob($read($store, 'directory') . '/*') ?: [] as $file) {
                $raw .= \basename($file) . "\n" . \file_get_contents($file) . "\n";
            }

            return $raw;
        }

        /** @var Connection $connection */
        $connection = $read($store, 'connection');

        return \json_encode($connection->table(self::TABLE)->get(), \JSON_THROW_ON_ERROR);
    }

    /**
     * A session id is a live login, so no store keeps one: not as a key, a
     * column, a file name or inside the record. Whoever reads the storage --
     * a backup, a replica, a support query -- learns only hashes.
     *
     * @param \Closure(): SessionStore $make
     */
    #[DataProvider('stores')]
    public function test_the_id_itself_is_never_stored(\Closure $make): void
    {
        $store = $make();
        $id = self::id();

        $store->commit($id, static fn(): SessionRecord => SessionRecord::fresh($id, ['user' => 'ada']));

        $raw = self::rawStorage($store);

        self::assertStringContainsString('ada', $raw, 'the record is there');
        self::assertStringContainsString(SessionRecord::keyFor($id), $raw, 'under the id\'s hash');

        if (!$store instanceof ArrayStore) {
            // The array store is keyed by id in memory, for this process only;
            // what it keeps in each entry still must not repeat the id.
            self::assertStringNotContainsString($id, $raw);
        } else {
            self::assertStringNotContainsString('"' . $id . '"', \str_replace('"' . $id . '":', '', $raw));
        }

        self::assertSame(['user' => 'ada'], $store->read($id)?->payload, 'and it reads back by the id');
    }

    /** A record written before ids were hashed still reads -- by the same id, and no other. */
    public function test_a_record_from_before_the_hashing_still_decodes(): void
    {
        $id = self::id();
        $legacy = \json_encode(['id' => $id, 'payload' => ['a' => 1], 'created' => 1, 'touched' => 2, 'successor' => null], \JSON_THROW_ON_ERROR);

        self::assertSame(['a' => 1], SessionRecord::decode($legacy, $id)?->payload);
        self::assertNull(SessionRecord::decode($legacy, self::id()), 'another id does not unlock it');

        $current = SessionRecord::fresh($id, ['a' => 1])->encode();

        self::assertNull(SessionRecord::decode($current, self::id()));
        self::assertNull(SessionRecord::decode($current), 'without the id there is nothing to bind it to');
        self::assertNotNull(SessionRecord::forSweep($current), 'but a sweep can still read its age');
    }

    // ---- destroying and sweeping -----------------------------------------

    /** @param \Closure(): SessionStore $make */
    #[DataProvider('stores')]
    public function test_destroy_removes_one_session_and_says_so(\Closure $make): void
    {
        $store = $make();
        $id = self::id();

        $store->commit($id, static fn(): SessionRecord => SessionRecord::fresh($id));

        self::assertTrue($store->destroy($id));
        self::assertNull($store->read($id));
        self::assertFalse($store->destroy($id), 'destroying nothing is false, not an error');
    }

    /** @param \Closure(): SessionStore $make */
    #[DataProvider('stores')]
    public function test_gc_removes_the_idle_and_keeps_the_active(\Closure $make): void
    {
        $store = $make();
        $stale = self::id();
        $fresh = self::id();
        $now = \time();

        $store->commit($stale, static fn(): SessionRecord => new SessionRecord($stale, [], $now - 9000, $now - 9000));
        $store->commit($fresh, static fn(): SessionRecord => new SessionRecord($fresh, [], $now - 10, $now - 10));

        self::assertSame(1, $store->gc(7200));
        self::assertNull($store->read($stale));
        self::assertNotNull($store->read($fresh));
    }

    /**
     * The clock that catches a session something keeps warm.
     *
     * @param \Closure(): SessionStore $make
     */
    #[DataProvider('stores')]
    public function test_gc_honours_the_absolute_lifetime(\Closure $make): void
    {
        $store = $make();
        $id = self::id();
        $now = \time();

        // Touched a second ago, so no idle sweep would take it -- and created
        // a week ago, which is the point.
        $store->commit($id, static fn(): SessionRecord => new SessionRecord($id, [], $now - 604800, $now - 1));

        self::assertSame(0, $store->gc(7200), 'the idle clock alone must not take it');
        self::assertSame(1, $store->gc(7200, 86400));
        self::assertNull($store->read($id));
    }

    /** @param \Closure(): SessionStore $make */
    #[DataProvider('stores')]
    public function test_gc_on_an_empty_store_is_zero(\Closure $make): void
    {
        self::assertSame(0, $make()->gc(7200));
    }

    /** @param \Closure(): SessionStore $make */
    #[DataProvider('stores')]
    public function test_every_store_describes_itself_in_one_line(\Closure $make): void
    {
        $description = $make()->describe();

        self::assertNotSame('', $description);
        self::assertStringNotContainsString("\n", $description);
    }
}
