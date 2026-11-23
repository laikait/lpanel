<?php

declare(strict_types=1);

namespace App\Modules\Security\Firewall;

use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;

/**
 * The firewall's address lists, as the panel keeps them: the allow list
 * (always let in) and the deny list (never let in), each address with a note
 * and, for a deny entry, perhaps a time it ends.
 */
final class Entries
{
    public const ALLOW = 'allow';
    public const DENY = 'deny';
    public const LISTS = [self::ALLOW, self::DENY];

    public function __construct(private readonly ConnectionManager $connections) {}

    /** @return list<array<string, mixed>> newest first */
    public function all(string $list): array
    {
        return \array_values($this->table()->where('list', $list)->orderByDesc('id')->get());
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->table()->where('id', $id)->first();
    }

    /** @return array<string, mixed>|null */
    public function findAddress(string $list, string $cidr): ?array
    {
        return $this->table()->where('list', $list)->where('cidr', $cidr)->first();
    }

    /**
     * The addresses of a list that are in force now, as text.
     *
     * @param list<string> $without addresses to leave out (about to be removed)
     * @param list<string> $with    addresses to add (about to be added)
     *
     * @return list<string>
     */
    public function active(string $list, array $without = [], array $with = []): array
    {
        $now = \gmdate('Y-m-d H:i:s');
        $out = [];

        foreach ($this->table()->where('list', $list)->get() as $row) {
            if ($row['expires_at'] !== null && (string) $row['expires_at'] <= $now) {
                continue;
            }

            $out[(string) $row['cidr']] = true;
        }

        foreach ($with as $cidr) {
            $out[$cidr] = true;
        }

        foreach ($without as $cidr) {
            unset($out[$cidr]);
        }

        $list = \array_map('strval', \array_keys($out));
        \sort($list);

        return $list;
    }

    public function add(string $list, string $cidr, string $note, string $source, ?int $by, ?string $expires): int
    {
        return (int) $this->table()->insert([
            'list' => $list, 'cidr' => $cidr, 'note' => \mb_substr($note, 0, 120), 'source' => $source, 'created_by' => $by,
            'expires_at' => $expires, 'created_at' => \gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function remove(int $id): void
    {
        $this->table()->where('id', $id)->delete();
    }

    /** @return list<array<string, mixed>> entries whose time has passed */
    public function expired(): array
    {
        return \array_values($this->table()->whereNotNull('expires_at')->where('expires_at', '<=', \gmdate('Y-m-d H:i:s'))->get());
    }

    /** @return list<string> */
    public function countries(): array
    {
        return \array_values(\array_map(static fn(array $r): string => (string) $r['code'], $this->connections->connection()->table('firewall_countries')->orderBy('code')->get()));
    }

    /** @param list<string> $codes */
    public function setCountries(array $codes): void
    {
        $table = $this->connections->connection()->table('firewall_countries');
        $table->deleteAll();

        foreach ($codes as $code) {
            $table->insert(['code' => $code, 'created_at' => \gmdate('Y-m-d H:i:s')]);
        }
    }

    public function setting(string $name): ?string
    {
        $row = $this->connections->connection()->table('firewall_settings')->where('name', $name)->first();

        return $row === null ? null : (string) $row['value'];
    }

    public function remember(string $name, string $value): void
    {
        $this->connections->connection()->table('firewall_settings')->upsert([['name' => $name, 'value' => $value]], ['name'], ['value']);
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('firewall_entries');
    }
}
