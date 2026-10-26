<?php

declare(strict_types=1);

namespace App\Engine\Session\Stores;

use App\Engine\Database\Connection;
use App\Engine\Database\DatabaseException;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Session\SessionException;
use App\Engine\Session\SessionId;
use App\Engine\Session\SessionRecord;
use App\Engine\Session\SessionStore;

/**
 * Sessions in a table, which is what "distributed sessions" actually means.
 *
 * The file store is correct and fast and only works on one machine. The moment
 * there are two web servers behind a load balancer, a user's next request lands
 * somewhere that has never heard of them. Sticky sessions push the problem
 * around -- and lose every session on that node when it restarts. A shared
 * table is the version with no asterisk, and the database is already there.
 *
 * Every statement goes through the query builder, so the store runs on each
 * database the builder writes for.
 *
 * **The lock is a row lock, taken inside a transaction.** lockForUpdate(),
 * which is FOR UPDATE on MySQL and PostgreSQL and UPDLOCK on SQL Server,
 * gives exactly the guarantee the interface asks for: one writer at a time,
 * per session, for the length of one read-modify-write rather than the length
 * of a request.
 *
 * SQLite has no row locking; it locks the whole database file for a write. That
 * is correct but not concurrent, and under two simultaneous writers one of them
 * gets "database is locked". It is fine for development and for the test suite,
 * which is what it is used for here.
 *
 * **The table is not created on a request.** A store that issues DDL on its
 * first request needs permissions in production that nothing should have, and
 * it does it at the worst possible time. `migrate` creates it, from
 * SessionTableMigration, while session.store is "database".
 *
 * **The id column holds the session id's SHA-256, never the id.** A session id
 * is a live login, and a sessions table is read by more people than its
 * purpose suggests: a backup, a reporting replica, a support query. The JSON
 * carries the same hash (SessionRecord::encode()), the file store names its
 * files by it, and every lookup hashes the id it was given. The hash is as
 * long as an id, so the column is unchanged.
 *
 * The timestamps appear both as columns and inside the JSON, and that
 * duplication is deliberate. The columns exist so that the sweep is one
 * indexed DELETE rather than a read loop; the JSON is the canonical record, so
 * every store in this framework decodes the same bytes with the same code and
 * a session written by one can be read by another.
 */
final class DatabaseStore implements SessionStore
{
    public const DEFAULT_TABLE = 'sessions';

    public function __construct(
        private readonly Connection $connection,
        private readonly string $table = self::DEFAULT_TABLE,
    ) {}

    public function describe(): string
    {
        return \sprintf('database %s.%s', $this->connection->name(), $this->table);
    }

    public function read(string $id): ?SessionRecord
    {
        if (!SessionId::isValid($id)) {
            return null;
        }

        return $this->decode($this->row($id, lock: false), $id);
    }

    public function commit(string $id, \Closure $apply): ?SessionRecord
    {
        if (!SessionId::isValid($id)) {
            return null;
        }

        /** @var SessionRecord|null $result */
        $result = $this->connection->transaction(function () use ($id, $apply): ?SessionRecord {
            $existing = $this->row($id, lock: true);
            $record = $apply($this->decode($existing, $id));

            if ($record === null) {
                if ($existing !== null) {
                    $this->sessions()->where('id', SessionRecord::keyFor($id))->delete();
                }

                return null;
            }

            $columns = [
                'payload' => $record->encode(),
                'created_at' => $record->createdAt,
                'touched_at' => $record->touchedAt,
                'successor' => $record->successor,
            ];

            if ($existing === null) {
                $this->sessions()->insert(['id' => SessionRecord::keyFor($record->id), ...$columns]);
            } else {
                $this->sessions()->where('id', SessionRecord::keyFor($record->id))->update($columns);
            }

            return $record;
        });

        return $result;
    }

    public function destroy(string $id): bool
    {
        if (!SessionId::isValid($id)) {
            return false;
        }

        return $this->sessions()->where('id', SessionRecord::keyFor($id))->delete() > 0;
    }

    /**
     * One statement, not a read loop.
     *
     * Sweeping by selecting and deleting row by row would hold the table open
     * for as long as there are expired sessions, which on a busy site is
     * exactly when it is worst. Both clocks are conditions the database can
     * answer without sending a row anywhere.
     */
    public function gc(int $idle, int $absolute = 0): int
    {
        $now = \time();
        $query = null;

        if ($idle > 0) {
            $query = $this->sessions()->where('touched_at', '<=', $now - $idle);
        }

        if ($absolute > 0) {
            $query = $query === null
                ? $this->sessions()->where('created_at', '<=', $now - $absolute)
                : $query->orWhere('created_at', '<=', $now - $absolute);
        }

        return $query === null ? 0 : $query->delete();
    }

    private function sessions(): QueryBuilder
    {
        return $this->connection->table($this->table);
    }

    /** @return array<string, mixed>|null */
    private function row(string $id, bool $lock): ?array
    {
        $query = $this->sessions()->select('id', 'payload', 'created_at', 'touched_at', 'successor')->where('id', SessionRecord::keyFor($id));

        try {
            return ($lock ? $query->lockForUpdate() : $query)->first();
        } catch (DatabaseException $e) {
            // A missing table is a deployment mistake, and the message every
            // driver gives for it is useless to whoever has to fix it. Anything
            // else is the database being unavailable, which is not this layer's
            // to explain and is rethrown untouched.
            if ($e->meansMissingTable()) {
                throw SessionException::missingTable($this->table, $this->connection->name());
            }

            throw $e;
        }
    }

    /** @param array<string, mixed>|null $row */
    private function decode(?array $row, string $id): ?SessionRecord
    {
        if ($row === null || !isset($row['payload']) || !\is_string($row['payload'])) {
            return null;
        }

        return SessionRecord::decode($row['payload'], $id);
    }
}
