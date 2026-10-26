<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Migration\MigrationException;
use App\Engine\Migration\Migrator;

/**
 * Every migration, whether it ran and in which batch. Changes nothing.
 *
 * A migration recorded as run whose file has since gone is listed too, and
 * called out: it cannot be rolled back, and somebody deleted a file that a
 * database somewhere still remembers.
 */
final class MigrateStatusCommand
{
    public function __construct(private readonly Migrator $migrator) {}

    public function __invoke(Output $output, ?string $connection = null): int
    {
        try {
            $status = $this->migrator->status($connection);
        } catch (MigrationException $e) {
            $output->error($e->getMessage());

            return 1;
        }

        if ($status === []) {
            $output->line('No module has a Database/Migrations directory with anything in it.');

            return 0;
        }

        $rows = [];
        $pending = 0;
        $missing = 0;

        foreach ($status as $migration) {
            $pending += $migration['batch'] === null ? 1 : 0;
            $missing += $migration['file'] ? 0 : 1;

            $rows[] = [
                $migration['id'],
                $migration['batch'] === null ? 'pending' : 'ran',
                $migration['batch'] === null ? '' : (string) $migration['batch'],
                $migration['file'] ? '' : 'file missing',
            ];
        }

        $output->table(['MIGRATION', 'STATE', 'BATCH', ''], $rows);
        $output->line();
        $output->line(\sprintf('%d pending.', $pending));

        if ($missing > 0) {
            $output->warning(\sprintf('%d ran but has no file any more, and cannot be rolled back.', $missing));
        }

        return 0;
    }
}
