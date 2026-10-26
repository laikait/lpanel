<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Engine\Migration\MigrationException;
use App\Engine\Migration\SeederFile;
use App\Engine\Migration\SeedRunner;

/**
 * Run every module's seeders, or one module's.
 *
 * In production it asks for --force: seeders write rows, and rows meant for a
 * developer's database do not belong in the live one by accident.
 */
final class DbSeedCommand
{
    public function __construct(
        private readonly SeedRunner $seeds,
        private readonly Config $config,
    ) {}

    public function __invoke(Output $output, ?string $connection = null, ?string $module = null, bool $force = false): int
    {
        if (!$force && (string) $this->config->get('app.env', 'production') === 'production') {
            $output->error('This is production, and seeders write rows into it. Pass --force to do it anyway.');

            return 1;
        }

        try {
            $count = $this->seeds->seed(
                $connection,
                $module,
                static function (SeederFile $file, int $nanoseconds) use ($output): void {
                    $output->line(\sprintf('  seeded  %s  (%.1f ms)', $file->id(), $nanoseconds / 1e6));
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
            $output->success('No seeders to run.');

            return 0;
        }

        $output->line();
        $output->success(\sprintf('%d seeder(s) ran.', $count));

        return 0;
    }
}
