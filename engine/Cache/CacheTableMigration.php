<?php

declare(strict_types=1);

namespace App\Engine\Cache;

use App\Engine\Cache\Stores\DatabaseStore;
use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

/**
 * The table the database cache store keeps its entries in, created by
 * `migrate` while cache.store is "database".
 *
 * The key is a SHA-256 of the cache key rather than the key: fixed length
 * whatever the namespace, and compared byte for byte on every database, where
 * MySQL would otherwise call "Customers" and "customers" the same key. The
 * namespace has a column of its own, so clearing one is an indexed DELETE;
 * expires_at is indexed for the sweep.
 */
final class CacheTableMigration implements Reversible
{
    /** Its name, after the session table's. */
    public const NAME = '2026_09_19_000001_create_cache';

    public function __construct(private readonly string $table = DatabaseStore::DEFAULT_TABLE) {}

    public function up(Tables $tables): void
    {
        if ($tables->exists($this->table)) {
            return;
        }

        $tables->create($this->table, static function (Table $table): void {
            $table->string('id', 64)->primary();
            $table->string('namespace', 190)->default('')->index();
            $table->text('value');
            $table->bigInteger('expires_at')->nullable()->index();
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop($this->table);
    }
}
