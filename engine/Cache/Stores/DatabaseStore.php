<?php

declare(strict_types=1);

namespace App\Engine\Cache\Stores;

use App\Engine\Cache\AtomicStore;
use App\Engine\Cache\Cache;
use App\Engine\Cache\CacheEntry;
use App\Engine\Cache\CacheException;
use App\Engine\Cache\PrunableStore;
use App\Engine\Database\Connection;
use App\Engine\Database\DatabaseException;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Support\Sealer;

/**
 * Entries in a table, shared by every machine that shares the database.
 *
 * The file store is fast and correct on one machine; behind a load balancer
 * each host would warm and invalidate its own copy, and clearing the cache on
 * one would leave the others serving what was cleared. A table is the shared
 * cache that needs nothing installed beyond the database the application
 * already has. It is not a fast cache -- every hit is a query -- so it earns its
 * place where the value costs much more than a query to work out.
 *
 * Every statement goes through the query builder, so it runs on each database
 * the builder writes for. The table comes from CacheTableMigration.
 *
 * As the interface asks, a failure is a miss, not an error: a cache that cannot
 * reach its database is a slow application, not a broken one. A value that
 * cannot be serialised is still refused.
 *
 * The connection is looked up on first use, not when the store is built: the
 * cache exists from the start of boot, before any connection has been
 * configured, and a request that never touches the cache never opens one.
 */
final class DatabaseStore implements PrunableStore, AtomicStore
{
    public const DEFAULT_TABLE = 'cache';

    private ?Connection $connection = null;

    /** @param \Closure(): Connection $connect */
    public function __construct(
        private readonly \Closure $connect,
        private readonly string $table = self::DEFAULT_TABLE,
        private readonly Sealer $sealer = new Sealer(),
    ) {}

    public function describe(): string
    {
        return \sprintf('database %s.%s', $this->connection()->name(), $this->table);
    }

    public function get(string $key): ?CacheEntry
    {
        try {
            $row = $this->entries()->select('value', 'expires_at')->where('id', self::id($key))->first();
        } catch (DatabaseException) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        $expires = \is_numeric($row['expires_at'] ?? null) ? (int) $row['expires_at'] : null;
        $entry = $this->decode($row['value'] ?? null, $expires);

        if ($entry === null || $entry->hasExpired()) {
            // Unreadable, or past its time: either way a miss, and removed on
            // the way past so it is not one for ever.
            $this->forget($key);

            return null;
        }

        return $entry;
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $row = [
            'id' => self::id($key),
            'namespace' => self::namespaceOf($key),
            'value' => $this->encode($key, $value),
            'expires_at' => $ttl === null ? null : \time() + $ttl,
        ];

        // Delete and insert rather than an upsert, which SQL Server does not
        // have; in one transaction, so a reader never finds the key missing
        // in between. Two writers at once: one wins, the other answers false.
        try {
            $this->connection()->transaction(function () use ($row): void {
                $this->entries()->where('id', $row['id'])->delete();
                $this->entries()->insert($row);
            });
        } catch (DatabaseException) {
            return false;
        }

        return true;
    }

    public function forget(string $key): bool
    {
        try {
            $this->entries()->where('id', self::id($key))->delete();
        } catch (DatabaseException) {
            return false;
        }

        return true;
    }

    /**
     * The primary key does the work: an expired row for the key is cleared,
     * then an INSERT either succeeds or meets the row another process put
     * there first, and fails. No read-then-write gap to race through.
     */
    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        try {
            $this->entries()->where('id', self::id($key))->whereNotNull('expires_at')->where('expires_at', '<=', \time())->delete();
            $this->entries()->insert([
                'id' => self::id($key),
                'namespace' => self::namespaceOf($key),
                'value' => $this->encode($key, $value),
                'expires_at' => $ttl === null ? null : \time() + $ttl,
            ]);
        } catch (DatabaseException) {
            return false;
        }

        return true;
    }

    /** One DELETE matching the key and the value it was given -- removed only if it still holds that. */
    public function forgetIf(string $key, mixed $value): bool
    {
        try {
            return $this->entries()->where('id', self::id($key))->where('value', $this->encode($key, $value))->delete() > 0;
        } catch (DatabaseException) {
            return false;
        }
    }

    /** Everything, or one namespace: the part of the prefix before its first separator. */
    public function flush(string $prefix = ''): bool
    {
        try {
            if ($prefix === '') {
                $this->entries()->deleteAll();
            } else {
                $this->entries()->where('namespace', self::namespaceOf($prefix . Cache::SEPARATOR))->delete();
            }
        } catch (DatabaseException) {
            return false;
        }

        return true;
    }

    public function prune(): int
    {
        try {
            return $this->entries()->whereNotNull('expires_at')->where('expires_at', '<=', \time())->delete();
        } catch (DatabaseException) {
            return 0;
        }
    }

    private function connection(): Connection
    {
        return $this->connection ??= ($this->connect)();
    }

    private function entries(): QueryBuilder
    {
        return $this->connection()->table($this->table);
    }

    private static function id(string $key): string
    {
        return \hash('sha256', $key);
    }

    /** Everything before the first separator, as Cache builds a qualified key; '' for a key with none. */
    private static function namespaceOf(string $key): string
    {
        $separator = \strpos($key, Cache::SEPARATOR);

        return $separator === false ? '' : \substr($key, 0, $separator);
    }

    /**
     * serialize(), in base64: its output is bytes, not text, and a text column
     * on some databases refuses bytes that are not valid in its encoding.
     */
    private function encode(string $key, mixed $value): string
    {
        try {
            return \base64_encode($this->sealer->seal(\serialize(['value' => $value])));
        } catch (\Throwable) {
            throw CacheException::notStorable($key, \get_debug_type($value));
        }
    }

    /** Null -- a miss -- for a row this application did not write; see Sealer. */
    private function decode(mixed $stored, ?int $expires): ?CacheEntry
    {
        $sealed = \is_string($stored) ? \base64_decode($stored, true) : false;
        $bytes = $sealed === false ? null : $this->sealer->open($sealed);

        if ($bytes === null) {
            return null;
        }

        /** @var mixed $data */
        $data = @\unserialize($bytes);

        return \is_array($data) && \array_key_exists('value', $data) ? new CacheEntry($data['value'], $expires) : null;
    }
}
