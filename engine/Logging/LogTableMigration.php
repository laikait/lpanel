<?php

declare(strict_types=1);

namespace App\Engine\Logging;

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Logging\Writers\DatabaseWriter;
use App\Engine\Migration\Reversible;

/**
 * The table the database log writer keeps its records in, created by
 * `migrate` while logging.writers names "database".
 *
 * The level is kept twice, and neither copy is redundant: `level` is the RFC
 * 5424 code, so "error or worse" is `level <= 3`, and `level_name` is what
 * somebody reading the table sees. The context is JSON; a record is written
 * once and read by people, so it gets no columns of its own.
 */
final class LogTableMigration implements Reversible
{
    /** Its name, after the queue table's. */
    public const NAME = '2026_09_19_000003_create_logs';

    public function __construct(private readonly string $table = DatabaseWriter::DEFAULT_TABLE) {}

    public function up(Tables $tables): void
    {
        if ($tables->exists($this->table)) {
            return;
        }

        $tables->create($this->table, static function (Table $table): void {
            $table->id();
            $table->dateTime('logged_at')->index();
            $table->integer('level')->index();
            $table->string('level_name', 10);
            $table->string('channel', 100)->index();
            $table->text('message');
            $table->text('context')->nullable();
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop($this->table);
    }
}
