<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Engine\Migration\MigrationException;
use App\Engine\Migration\MigrationFile;
use App\Engine\Migration\Migrator;

/**
 * Undo the last batch of migrations, or the last few.
 *
 * In production it asks for --force: undoing a migration usually drops a
 * table, and a table dropped is its data gone. Everywhere else it is the
 * ordinary way to try a migration again.
 */
final class MigrateRollbackCommand
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly Config $config,
    ) {}

    public function __invoke(Output $output, ?string $connection = null, int $batches = 1, bool $force = false): int
    {
        if (!$force && (string) $this->config->get('app.env', 'production') === 'production') {
            $output->error('This is production, and a rollback usually drops tables with their data. Pass --force to do it anyway.');

            return 1;
        }

        try {
            $count = $this->migrator->rollback(
                $connection,
                $batches,
                static function (MigrationFile $file, array $statements, int $nanoseconds) use ($output): void {
                    $output->line(\sprintf('  undid  %s  (%.1f ms)', $file->id(), $nanoseconds / 1e6));
                },
            );
        } catch (\InvalidArgumentException $e) {
            $output->error($e->getMessage());

            return 1;
        } catch (MigrationException $e) {
            $output->error($e->getMessage());
            $previous = $e->getPrevious();

            if ($previous !== null) {
                $output->line('  Cause: ' . ($e->disclosesCause($this->config->bool('app.debug', false))
                    ? $previous->getMessage()
                    : $previous::class . ', whose message is shown only with APP_DEBUG=1.'));
            }

            return 1;
        }

        if ($count === 0) {
            $output->success('Nothing to roll back.');

            return 0;
        }

        $output->line();
        $output->success(\sprintf('%d migration(s) undone.', $count));

        return 0;
    }
}
