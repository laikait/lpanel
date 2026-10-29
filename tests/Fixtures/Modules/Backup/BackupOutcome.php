<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\Backup;

/** What a backup or a restore did, for the command's own output and for backup.completed/restore.completed. */
final class BackupOutcome
{
    public function __construct(
        public readonly string $connection,
        public readonly string $driver,
        public readonly string $path,
        public readonly int $bytes,
        public readonly float $seconds,
    ) {}
}
