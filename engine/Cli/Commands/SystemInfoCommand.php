<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\System\SystemInfo\SystemInfo;

/**
 * The machine this application is running on, as engine/System sees it.
 *
 * Everything comes from SystemInfo, which starts no process: this is the same
 * answer a module gets, and a value the machine does not publish prints as
 * "unknown" rather than as a zero.
 */
final class SystemInfoCommand
{
    public function __construct(private readonly Application $application) {}

    public function __invoke(Output $output): int
    {
        $info = new SystemInfo();
        $memory = $info->memory();
        $load = $info->loadAverage();
        $uptime = $info->uptime();
        $disk = $info->disk($this->application->basePath());

        $output->pairs([
            'Operating system' => $info->os() . ' (' . $info->osFamily() . ')',
            'Kernel' => $info->kernel(),
            'Architecture' => $info->architecture(),
            'Hostname' => $info->hostname(),
            'PHP' => $info->phpVersion(),
            'CPUs online' => (string) ($info->cpuCount() ?? 'unknown'),
            'Memory' => $memory === null ? 'unknown' : \sprintf('%s available of %s', self::bytes($memory->available), self::bytes($memory->total)),
            'Load (1, 5, 15 min)' => $load === null ? 'unknown' : \implode(', ', \array_map(static fn(float $l): string => \sprintf('%.2f', $l), $load)),
            'Uptime' => $uptime === null ? 'unknown' : self::duration($uptime),
            'Disk (application)' => $disk === null ? 'unknown' : \sprintf('%s free of %s', self::bytes($disk->free), self::bytes($disk->total)),
        ]);

        return 0;
    }

    private static function bytes(int $bytes): string
    {
        foreach (['GiB' => 1 << 30, 'MiB' => 1 << 20, 'KiB' => 1 << 10] as $unit => $size) {
            if ($bytes >= $size) {
                return \sprintf('%.1f %s', $bytes / $size, $unit);
            }
        }

        return $bytes . ' B';
    }

    private static function duration(float $seconds): string
    {
        $seconds = (int) $seconds;

        return \sprintf('%dd %02dh %02dm', \intdiv($seconds, 86400), \intdiv($seconds % 86400, 3600), \intdiv($seconds % 3600, 60));
    }
}
