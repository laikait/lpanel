<?php

declare(strict_types=1);

namespace App\Engine\Queue;

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;
use App\Engine\Queue\Stores\DatabaseStore;

/**
 * The table the database queue store keeps its jobs in, created by `migrate`
 * while queue.store is "database".
 *
 * One table for waiting, reserved and failed jobs: failed_at says which a row
 * is, so failing a job is one UPDATE and retrying one is another. The index on
 * (queue, available_at) is the one a worker's reserve() walks. Times are Unix
 * seconds; the payload is serialize() output in base64, since it is bytes.
 */
final class QueueTableMigration implements Reversible
{
    /** Its name, after the session and cache tables'. */
    public const NAME = '2026_09_19_000002_create_jobs';

    public function __construct(private readonly string $table = DatabaseStore::DEFAULT_TABLE) {}

    public function up(Tables $tables): void
    {
        if ($tables->exists($this->table)) {
            return;
        }

        $tables->create($this->table, static function (Table $table): void {
            $table->string('id', 64)->primary();
            $table->string('queue', 100);
            $table->string('class', 255);
            $table->text('payload');
            $table->integer('attempts')->default(0);
            $table->bigInteger('available_at');
            $table->bigInteger('reserved_until')->nullable();
            $table->bigInteger('created_at');
            $table->bigInteger('failed_at')->nullable()->index();
            $table->text('error')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->index('queue', 'available_at');
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop($this->table);
    }
}
