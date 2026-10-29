<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\Backup\Cli;

use App\Engine\Cli\Output;
use App\Engine\System\Command\CommandFailedException;
use App\Tests\Fixtures\Modules\Backup\BackupService;

/**
 * Back up one connection's database, whichever of the four drivers it is.
 *
 * Never needs --force: the filename is timestamped, so it never collides
 * with a backup already there.
 */
final class BackupMakeCommand
{
    public function __construct(private readonly BackupService $backups) {}

    public function __invoke(Output $output, ?string $connection = null): int
    {
        try {
            $outcome = $this->backups->make($connection);
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

        $output->success('Backup written.');
        $output->pairs([
            'connection' => $outcome->connection,
            'driver' => $outcome->driver,
            'path' => $outcome->path,
            'size' => self::humanSize($outcome->bytes),
            'took' => \sprintf('%.1fs', $outcome->seconds),
        ]);

        return 0;
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        $index = 0;

        while ($value >= 1024 && $index < \count($units) - 1) {
            $value /= 1024;
            ++$index;
        }

        return \sprintf('%.1f %s', $value, $units[$index]);
    }
}
