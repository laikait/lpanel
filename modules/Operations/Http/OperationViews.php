<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http;

use App\Modules\Operations\Catalogue\Catalogue;
use App\Modules\Operations\Store\Operation;
use App\Modules\Servers\Store\Server;
use App\Modules\Shared\Ui\Pages;

/**
 * Operations as the screens show them: a label for every code, results
 * turned into gauges and tables, sizes in units people read. Every value
 * from an agent is still escaped by Twig; nothing here builds HTML.
 */
final class OperationViews
{
    /** What each state is called on screen, and what it means. */
    public const STATES = [
        Operation::QUEUED => 'Waiting for the agent to take it.',
        Operation::CLAIMED => 'The agent has taken it.',
        Operation::RUNNING => 'The agent is running it.',
        Operation::SUCCEEDED => 'Done.',
        Operation::FAILED => 'It did not complete.',
        Operation::CANCELLED => 'Called off.',
        Operation::NEEDS_ATTENTION => 'Whether it changed the server is not known. Check the server before trying again.',
        Operation::EXPIRED => 'No agent took it in time.',
    ];

    public function __construct(private readonly Catalogue $catalogue, private readonly Pages $pages) {}

    public function label(Operation $op): string
    {
        return $this->catalogue->find($op->op, $op->opVersion)->label ?? $op->op;
    }

    /** @return array<string, mixed> */
    public function row(Operation $op, ?Server $server): array
    {
        return [
            'op' => $op,
            'label' => $this->label($op),
            'url' => $this->pages->url('operations.show', ['id' => $op->uid]),
            'watch' => $op->isLive() ? $this->pages->url('api.v1.operations.show', ['id' => $op->uid]) : null,
            'server' => $server === null ? null : ['name' => $server->name, 'url' => $this->pages->url('servers.show', ['id' => $server->uid])],
            'duration' => self::duration($op->duration()),
        ];
    }

    /**
     * system.metrics' result, as gauges.
     *
     * @return ?array<string, mixed>
     */
    public static function metrics(?Operation $op, ?Operation $info): ?array
    {
        $m = $op?->resultArray();

        if ($op === null || $m === null) {
            return null;
        }

        $cpus = (int) ($info?->resultArray()['cpu_count'] ?? 0);
        $load = \is_array($m['load'] ?? null) ? $m['load'] : [];
        $memory = \is_array($m['memory'] ?? null) ? $m['memory'] : [];
        $total = (int) ($memory['total_bytes'] ?? 0);
        $used = \max(0, $total - (int) ($memory['available_bytes'] ?? 0));
        $swapTotal = (int) ($memory['swap_total_bytes'] ?? 0);
        $swapUsed = \max(0, $swapTotal - (int) ($memory['swap_free_bytes'] ?? 0));
        $disks = [];

        foreach (\is_array($m['disks'] ?? null) ? $m['disks'] : [] as $d) {
            if (!\is_array($d)) {
                continue;
            }

            $size = (int) ($d['total_bytes'] ?? 0);
            $inodes = (int) ($d['total_inodes'] ?? 0);
            $disks[] = [
                'mount' => (string) ($d['mount'] ?? ''),
                'fs' => (string) ($d['fs_type'] ?? ''),
                'used' => self::bytes((int) ($d['used_bytes'] ?? 0)),
                'total' => self::bytes($size),
                'free' => self::bytes((int) ($d['available_bytes'] ?? 0)),
                'pct' => self::percent($size - (int) ($d['available_bytes'] ?? 0), $size),
                'inode_pct' => $inodes > 0 ? self::percent($inodes - (int) ($d['free_inodes'] ?? 0), $inodes) : null,
            ];
        }

        $one = (float) ($load['one'] ?? 0);

        return [
            'load' => ['one' => $one, 'five' => (float) ($load['five'] ?? 0), 'fifteen' => (float) ($load['fifteen'] ?? 0)],
            'cpus' => $cpus > 0 ? $cpus : null,
            // Load per CPU: 100% is every CPU busy.
            'load_pct' => $cpus > 0 ? (int) \min(100, \round($one / $cpus * 100)) : null,
            'memory' => ['used' => self::bytes($used), 'total' => self::bytes($total), 'pct' => self::percent($used, $total)],
            'swap' => $swapTotal > 0 ? ['used' => self::bytes($swapUsed), 'total' => self::bytes($swapTotal), 'pct' => self::percent($swapUsed, $swapTotal)] : null,
            'uptime' => self::compact((int) ($m['uptime_seconds'] ?? 0)),
            'uptime_full' => self::duration((int) ($m['uptime_seconds'] ?? 0), 2),
            'disks' => $disks,
            'at' => $op->completedAt,
        ];
    }

    /**
     * service.list_status' result, as rows.
     *
     * @return ?list<array{unit: string, state: string, label: string, sub: string, enabled: string}>
     */
    public static function services(?Operation $op): ?array
    {
        $result = $op?->resultArray();

        if ($result === null) {
            return null;
        }

        $rows = [];

        foreach (\is_array($result['services'] ?? null) ? $result['services'] : [] as $s) {
            if (!\is_array($s)) {
                continue;
            }

            $active = (string) ($s['active_state'] ?? '');
            [$state, $label] = match (true) {
                ($s['load_state'] ?? '') === 'not-found' => ['absent', 'Not installed'],
                $active === 'active' => ['running', 'Running'],
                $active === 'failed' => ['failed', 'Failed'],
                \in_array($active, ['activating', 'reloading', 'deactivating'], true) => ['changing', \ucfirst($active)],
                default => ['stopped', 'Stopped'],
            };
            $rows[] = [
                'unit' => (string) ($s['unit'] ?? ''),
                'state' => $state,
                'label' => $label,
                'sub' => (string) ($s['sub_state'] ?? ''),
                'enabled' => (string) ($s['unit_file_state'] ?? ''),
            ];
        }

        return $rows;
    }

    /** 1.5 GiB */
    public static function bytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $value = (float) \max(0, $bytes);
        $i = 0;

        while ($value >= 1024 && $i < \count($units) - 1) {
            $value /= 1024;
            ++$i;
        }

        return $i === 0 ? \sprintf('%d B', (int) $value) : \sprintf($value >= 100 ? '%.0f %s' : '%.1f %s', $value, $units[$i]);
    }

    /** "3 days 4 h", "12 min", "4 s"; the largest $parts units. */
    public static function duration(?int $seconds, int $parts = 1): ?string
    {
        if ($seconds === null) {
            return null;
        }

        if ($seconds < 60) {
            return $seconds . ' s';
        }

        $out = [];

        foreach (['day' => 86400, 'h' => 3600, 'min' => 60] as $unit => $size) {
            if ($seconds >= $size && \count($out) < $parts) {
                $n = intdiv($seconds, $size);
                $out[] = $unit === 'day' ? \sprintf('%d %s', $n, $n === 1 ? 'day' : 'days') : $n . ' ' . $unit;
                $seconds %= $size;
            }
        }

        return \implode(' ', $out);
    }

    /** "23 d", "5 h", "12 min": one unit, for a figure in a small tile. */
    private static function compact(int $seconds): string
    {
        return match (true) {
            $seconds >= 86400 => intdiv($seconds, 86400) . ' d',
            $seconds >= 3600 => intdiv($seconds, 3600) . ' h',
            default => intdiv(\max(0, $seconds), 60) . ' min',
        };
    }

    private static function percent(int $part, int $whole): int
    {
        return $whole > 0 ? (int) \max(0, \min(100, \round($part / $whole * 100))) : 0;
    }
}
