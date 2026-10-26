<?php

declare(strict_types=1);

namespace App\Engine\Session;

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;
use App\Engine\Session\Stores\DatabaseStore;

/**
 * The table DatabaseStore reads and writes, created by `migrate` while
 * session.store is "database".
 *
 * It is the framework's migration rather than a module's, because the store
 * is the framework's; the bootstrap hands it to the Migrator only while the
 * store is in use, so an application keeping sessions in files gets no table.
 *
 * The id is the key, 64 characters, because session ids are. Both times are
 * Unix seconds in 64-bit integers, so the sweep is an index range rather than
 * a date function; touched_at carries the index because that is what the
 * sweep deletes by. The payload is text, since a session is usually small and
 * occasionally is not.
 *
 * A table made earlier by hand, from the statement session:table used to
 * print, is left as it is: up() creates the table only when it is not there,
 * so an application moving to migrations records this one without a failure.
 */
final class SessionTableMigration implements Reversible
{
    /** Its name, which sorts it the way a module's migration sorts. */
    public const NAME = '2026_09_19_000000_create_sessions';

    public function __construct(private readonly string $table = DatabaseStore::DEFAULT_TABLE) {}

    public function up(Tables $tables): void
    {
        if ($tables->exists($this->table)) {
            return;
        }

        $tables->create($this->table, static function (Table $table): void {
            $table->string('id', 64)->primary();
            $table->text('payload');
            $table->bigInteger('created_at');
            $table->bigInteger('touched_at')->index();
            $table->string('successor', 64)->nullable();
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop($this->table);
    }
}
