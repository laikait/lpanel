<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\Backup\Cli;

use App\Engine\Cli\Output;
use App\Engine\System\Command\CommandFailedException;
use App\Tests\Fixtures\Modules\Backup\RestoreService;

/**
 * Replace a connection's database with what a backup file holds.
 *
 * Refuses without --force, on every environment, not only production: there
 * is no down() for a restore, and "which database did that just overwrite"
 * is not a question this command lets a typo answer for you -- --connection
 * has no default, either.
 */
final class BackupRestoreCommand
{
    public function __construct(private readonly RestoreService $restores) {}

    public function __invoke(Output $output, string $path, ?string $connection = null, bool $force = false): int
    {
        if (!$force) {
            $output->error(
                'Restoring replaces the target database with what the backup file holds. '
                . 'Pass --force to confirm, and --connection to say which one.',
            );

            return 1;
        }

        try {
            $outcome = $this->restores->restore($connection, $path);
        } catch (CommandFailedException $e) {
            $output->error($e->getMessage());

            if (\trim($e->result->stderr()) !== '') {
                $output->line('  ' . \trim($e->result->stderr()));
            }

            return 1;
        } catch (\Throwable $e) {
            $output->error($e->getMessage());

            return 1;
        }

        $output->success('Restored.');
        $output->pairs([
            'connection' => $outcome->connection,
            'driver' => $outcome->driver,
            'from' => $outcome->path,
            'took' => \sprintf('%.1fs', $outcome->seconds),
        ]);

        return 0;
    }
}
