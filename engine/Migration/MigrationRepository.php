<?php

declare(strict_types=1);

namespace App\Engine\Migration;

use App\Engine\Database\Connection;
use App\Engine\Database\Structure\Table;

/**
 * Which migrations have run on a connection: one row each, in a table of the
 * application's naming (`database.migrations.table`, "migrations" unless set).
 *
 * The table is made with the table builder and read with the query builder,
 * so it exists in every dialect the same way. **position** is the order the
 * migrations ran in across every module, which is the order a rollback undoes
 * them in reverse; **batch** groups the migrations one run of `migrate` made.
 */
final class MigrationRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function exists(): bool
    {
        return $this->connection->tables()->exists($this->table);
    }

    public function create(): void
    {
        $this->connection->tables()->create($this->table, static function (Table $table): void {
            $table->id();
            $table->string('migration', 190)->unique();
            $table->string('module', 190);
            $table->integer('batch');
            $table->integer('position');
            $table->dateTime('ran_at');
        });
    }

    /**
     * Every migration recorded, by id, oldest first.
     *
     * @return array<string, array{module: string, batch: int, position: int}>
     */
    public function ran(): array
    {
        if (!$this->exists()) {
            return [];
        }

        $ran = [];

        foreach ($this->connection->table($this->table)->select('migration', 'module', 'batch', 'position')->orderBy('position')->get() as $row) {
            $ran[(string) $row['migration']] = [
                'module' => (string) $row['module'],
                'batch' => (int) $row['batch'],
                'position' => (int) $row['position'],
            ];
        }

        return $ran;
    }

    public function record(MigrationFile $file, int $batch, int $position): void
    {
        $this->connection->table($this->table)->insert([
            'migration' => $file->id(),
            'module' => $file->module,
            'batch' => $batch,
            'position' => $position,
            'ran_at' => new \DateTimeImmutable(),
        ]);
    }

    public function forget(string $id): void
    {
        $this->connection->table($this->table)->where('migration', $id)->delete();
    }
}
