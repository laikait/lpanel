<?php

declare(strict_types=1);

namespace App\Modules\Accounts;

use App\Modules\Identity\PanelUsers;
use App\Modules\Packages\Packages;

/**
 * Accounts as screens and the API show them: with their package, owner and
 * disk use against the package's limit.
 */
final class AccountView
{
    /** @var array<int, array<string, mixed>|null> */
    private array $packageCache = [];

    /** @var array<int, string> */
    private array $ownerCache = [];

    public function __construct(
        private readonly Packages $packages,
        private readonly PanelUsers $users,
    ) {}

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function enrich(array $row): array
    {
        $package = $this->package((int) $row['package_id']);
        $limitBytes = $package !== null && (int) $package['disk_mb'] > 0 ? (int) $package['disk_mb'] * 1048576 : 0;
        // Files in the home and databases together count against the package.
        $used = $row['disk_used_bytes'] === null ? null : (int) $row['disk_used_bytes'] + (int) ($row['db_used_bytes'] ?? 0);

        $row['package'] = $package;
        $row['package_name'] = $package['name'] ?? '?';
        $row['owner_name'] = $row['owner_id'] === null ? null : $this->owner((int) $row['owner_id']);
        $row['disk_limit_bytes'] = $limitBytes;
        $row['disk_total_bytes'] = $used;
        $row['disk_percent'] = $used !== null && $limitBytes > 0 ? (int) \min(100, \round($used * 100 / $limitBytes)) : null;
        $row['busy'] = $row['op_id'] !== null;

        return $row;
    }

    /**
     * An account as the API shows it.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function present(array $row): array
    {
        $row = $this->enrich($row);

        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'domain' => (string) $row['domain'],
            'status' => (string) $row['status'],
            'suspend_reason' => $row['suspend_reason'],
            'package' => $row['package'] === null ? null : ['id' => (int) $row['package']['id'], 'name' => (string) $row['package']['name']],
            'owner' => $row['owner_name'],
            'uid' => $row['uid'] === null ? null : (int) $row['uid'],
            'home' => $row['home'],
            'disk' => [
                'used_bytes' => $row['disk_used_bytes'] === null ? null : (int) $row['disk_used_bytes'],
                'databases_bytes' => (int) ($row['db_used_bytes'] ?? 0),
                'limit_bytes' => $row['disk_limit_bytes'] ?: null,
                'inodes_used' => $row['inodes_used'] === null ? null : (int) $row['inodes_used'],
                'read_at' => $row['usage_at'],
            ],
            'pending_operation' => $row['op'],
            'last_error' => $row['op_error'],
            'created_at' => $row['created_at'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function package(int $id): ?array
    {
        if (!\array_key_exists($id, $this->packageCache)) {
            $this->packageCache[$id] = $this->packages->byId($id);
        }

        return $this->packageCache[$id];
    }

    private function owner(int $id): string
    {
        return $this->ownerCache[$id] ??= (string) ($this->users->row((string) $id)['username'] ?? '?');
    }
}
