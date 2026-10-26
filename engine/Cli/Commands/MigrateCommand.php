<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Engine\Migration\MigrationException;
use App\Engine\Migration\MigrationFile;
use App\Engine\Migration\Migrator;

/**
 * Run every pending migration: a deploy step, never a request's.
 *
 * `--connection` picks the connection -- one with the right to create tables,
 * which the application's own account should not have. `--pretend` prints
 * the SQL each pending migration would send to this database, and runs none.
 */
final class MigrateCommand
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly Config $config,
    ) {}

    public function __invoke(Output $output, ?string $connection = null, bool $pretend = false): int
    {
        try {
            $count = $this->migrator->migrate(
                $connection,
                $pretend,
                static function (MigrationFile $file, array $statements, int $nanoseconds) use ($output, $pretend): void {
                    if (!$pretend) {
                        $output->line(\sprintf('  ran  %s  (%.1f ms)', $file->id(), $nanoseconds / 1e6));

                        return;
                    }

                    $output->line('  ' . $file->id());

                    foreach ($statements as $statement) {
                        // Hand-written SQL is marked: it is the part that is
                        // not the same on every database.
                        $output->line(($statement['raw'] ? '    [raw] ' : '    ') . $statement['sql'] . ';');
                    }

                    $output->line();
                },
            );
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
            $output->success('Nothing to migrate.');

            return 0;
        }

        $output->line();
        $output->success(\sprintf($pretend ? '%d migration(s) would run. Nothing was run.' : '%d migration(s) ran.', $count));

        return 0;
    }
}
