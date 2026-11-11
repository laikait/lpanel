<?php

declare(strict_types=1);

namespace App\Modules\Packages;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;

/**
 * Hosting packages: the limits and features an account is sold with.
 *
 * A package belongs to the server (owner_id null; made by root or an
 * administrator) or to one reseller. Logins with packages.all see every
 * package; a reseller sees only their own.
 *
 * Other modules hear about changes through hooks and filters:
 *   packages.updated   (array $row)          its limits or features changed
 *   packages.in_use    (int $count, int $id) how many accounts use it
 *   packages.validate  (array $errors, array $clean, ?int $ownerId, ?int $id)  more rules
 */
final class Packages
{
    /** Numeric limits; 0 means unlimited. */
    public const LIMITS = ['disk_mb', 'inodes', 'bandwidth_mb', 'domains', 'subdomains', 'mailboxes', 'databases', 'ftp_accounts'];

    /** What an account on the package may use. Each is checked by the module that offers it. */
    public const FEATURES = ['domains', 'file_manager', 'email', 'databases', 'dns', 'ssl', 'backups', 'cron', 'ftp', 'ssh', 'php_settings', 'stats'];

    /** A new package offers everything but shell access. */
    public const DEFAULT_FEATURES = ['domains', 'file_manager', 'email', 'databases', 'dns', 'ssl', 'backups', 'cron', 'ftp', 'php_settings', 'stats'];

    /** The largest number a limit may hold: 1 PB in MB, and plenty of everything else. */
    public const MAX_LIMIT = 1_000_000_000;

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Authorizer $authorizer,
        private readonly FilterEngine $filters,
        private readonly HookEngine $hooks,
    ) {}

    /** null when this login sees every package, else the owner id it is limited to. */
    public function scopeOf(Identity $identity): ?int
    {
        return $this->authorizer->allows($identity, 'packages.all') ? null : (int) $identity->id;
    }

    /** @return list<array<string, mixed>> the packages a login may see, by name */
    public function visibleTo(Identity $identity): array
    {
        $query = $this->table()->orderBy('name');
        $scope = $this->scopeOf($identity);

        if ($scope !== null) {
            $query = $query->where('owner_id', $scope);
        }

        return \array_values(\array_map(self::decode(...), $query->get()));
    }

    /** @return list<array<string, mixed>> a reseller's own packages */
    public function ownedBy(int $ownerId): array
    {
        return \array_values(\array_map(self::decode(...), $this->table()->where('owner_id', $ownerId)->orderBy('name')->get()));
    }

    /** @return array<string, mixed>|null a package this login may see */
    public function find(Identity $identity, string|int $id): ?array
    {
        $row = $this->byId($id);
        $scope = $this->scopeOf($identity);

        return $row !== null && ($scope === null || (int) $row['owner_id'] === $scope) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function byId(string|int $id): ?array
    {
        if (!\is_int($id) && !\ctype_digit($id)) {
            return null;
        }

        $row = $this->table()->where('id', (int) $id)->first();

        return $row === null ? null : self::decode($row);
    }

    /**
     * The packages an account owned by $ownerId may be put on by this login:
     * the owner's own, plus the server's for a login with packages.all.
     *
     * @return list<array<string, mixed>>
     */
    public function usableFor(Identity $identity, ?int $ownerId): array
    {
        $all = $this->scopeOf($identity) === null;

        return \array_values(\array_filter(
            \array_map(self::decode(...), $this->table()->orderBy('name')->get()),
            static fn(array $row): bool => ($row['owner_id'] === null && $all)
                || ($row['owner_id'] !== null && $ownerId !== null && (int) $row['owner_id'] === $ownerId),
        ));
    }

    /**
     * Check a package form or API body.
     *
     * @param array<string, mixed> $input
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>} the clean values and errors (field => lang key)
     */
    public function validate(array $input, ?int $ownerId, ?int $exceptId = null): array
    {
        $errors = [];
        $name = \trim((string) ($input['name'] ?? ''));

        if ($name === '' || \mb_strlen($name) > 64) {
            $errors['name'] = 'Packages.error_name';
        } else {
            $same = $this->table()->where('name', $name);
            $same = $ownerId === null ? $same->whereNull('owner_id') : $same->where('owner_id', $ownerId);

            if ($exceptId !== null) {
                $same = $same->where('id', '!=', $exceptId);
            }

            if ($same->first() !== null) {
                $errors['name'] = 'Packages.error_name_taken';
            }
        }

        $clean = ['name' => $name];

        foreach (self::LIMITS as $limit) {
            $value = $input[$limit] ?? 0;
            $value = \is_string($value) ? \trim($value) : $value;

            if ($value === '') {
                $value = 0;
            }

            if ((!\is_int($value) && !(\is_string($value) && \ctype_digit($value))) || (int) $value > self::MAX_LIMIT) {
                $errors[$limit] = 'Packages.error_limit';
                $value = 0;
            }

            $clean[$limit] = (int) $value;
        }

        $features = $input['features'] ?? [];
        $features = \is_array($features) ? \array_values(\array_filter($features, 'is_string')) : [];
        $unknown = \array_diff($features, self::FEATURES);

        if ($unknown !== []) {
            $errors['features'] = 'Packages.error_features';
        }

        $clean['features'] = \array_values(\array_intersect(self::FEATURES, $features));

        // Other modules' rules, e.g. a reseller's allowance (Resellers).
        /** @var mixed $more */
        $more = $this->filters->apply('packages.validate', $errors, $clean, $ownerId, $exceptId);
        $errors = \is_array($more) ? \array_filter($more, 'is_string') : $errors;

        return [$clean, $errors];
    }

    /**
     * @param array<string, mixed> $clean from validate()
     *
     * @return string the new package's id
     */
    public function create(array $clean, ?int $ownerId): string
    {
        $id = $this->table()->insert(self::encode($clean) + ['owner_id' => $ownerId, 'created_at' => self::now()], 'id');

        return (string) $id;
    }

    /** @param array<string, mixed> $clean from validate() */
    public function update(int $id, array $clean): void
    {
        $this->table()->where('id', $id)->update(self::encode($clean) + ['updated_at' => self::now()]);
        $row = $this->byId($id);

        if ($row !== null) {
            $this->hooks->do('packages.updated', $row);
        }
    }

    public function delete(int $id): void
    {
        $this->table()->where('id', $id)->delete();
    }

    /** How many accounts use a package (answered by the Accounts module). */
    public function inUse(int $id): int
    {
        return (int) $this->filters->apply('packages.in_use', 0, $id);
    }

    /**
     * A package as the API shows it.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        $limits = [];

        foreach (self::LIMITS as $limit) {
            $limits[$limit] = (int) $row[$limit];
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'owner_id' => $row['owner_id'] === null ? null : (int) $row['owner_id'],
            'limits' => $limits,
            'features' => $row['features'],
        ];
    }

    /** @param array<string, mixed> $row the stored row, with features decoded */
    public static function has(array $row, string $feature): bool
    {
        return \in_array($feature, \is_array($row['features'] ?? null) ? $row['features'] : [], true);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        $features = \json_decode((string) ($row['features'] ?? '[]'), true);
        $row['features'] = \is_array($features) ? \array_values(\array_filter($features, 'is_string')) : [];
        $row['owner_id'] = $row['owner_id'] === null ? null : (int) $row['owner_id'];

        return $row;
    }

    /**
     * @param array<string, mixed> $clean
     *
     * @return array<string, mixed>
     */
    private static function encode(array $clean): array
    {
        $clean['features'] = \json_encode(\array_values($clean['features']), \JSON_THROW_ON_ERROR);

        return $clean;
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('packages');
    }

    private static function now(): string
    {
        return \gmdate('Y-m-d H:i:s');
    }
}
