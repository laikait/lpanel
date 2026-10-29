<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\Backup\Log;

use App\Engine\Logging\Level;
use App\Engine\Logging\LogManager;
use App\Tests\Fixtures\Modules\Backup\BackupOutcome;

/**
 * The bridge from backup.* and restore.* to the log, the same shape as
 * ScheduleLog (engine/Logging/ScheduleLog.php): BackupService and
 * RestoreService announce, this listens, and neither contains a reference
 * to a logger. Delete this class and backups still run; they simply stop
 * being written down.
 *
 * A nightly backup has no operator watching it run. If it silently stops
 * succeeding, a log line is the only way anyone finds out before the day
 * they actually need the backup.
 */
final class BackupLog
{
    public const CHANNEL = 'backup';

    public function __construct(private readonly LogManager $logs) {}

    public function completed(BackupOutcome $outcome): void
    {
        $this->logs->channel(self::CHANNEL)->log(Level::Info, \sprintf(
            'Backup of "%s" (%s) written to %s',
            $outcome->connection,
            $outcome->driver,
            $outcome->path,
        ), ['bytes' => $outcome->bytes, 'seconds' => $outcome->seconds]);
    }

    public function failed(string $connection, string $driver, \Throwable $error): void
    {
        $this->logs->channel(self::CHANNEL)->log(
            Level::Error,
            \sprintf('Backup of "%s" (%s) failed: %s', $connection, $driver, $error->getMessage()),
            ['exception' => $error],
        );
    }

    public function restored(BackupOutcome $outcome): void
    {
        $this->logs->channel(self::CHANNEL)->log(Level::Info, \sprintf(
            'Restored "%s" (%s) from %s',
            $outcome->connection,
            $outcome->driver,
            $outcome->path,
        ), ['bytes' => $outcome->bytes, 'seconds' => $outcome->seconds]);
    }

    public function restoreFailed(string $connection, string $driver, \Throwable $error): void
    {
        $this->logs->channel(self::CHANNEL)->log(
            Level::Error,
            \sprintf('Restore of "%s" (%s) failed: %s', $connection, $driver, $error->getMessage()),
            ['exception' => $error],
        );
    }
}
