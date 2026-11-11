<?php

declare(strict_types=1);

namespace App\Modules\Web;

use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;

/**
 * Server-wide web settings, by name: default_php (the version new sites get).
 */
final class WebSettings
{
    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(private readonly ConnectionManager $connections) {}

    public function get(string $name, ?string $default = null): ?string
    {
        if ($this->cache === null) {
            $this->cache = [];

            foreach ($this->table()->get() as $row) {
                $this->cache[(string) $row['name']] = (string) $row['value'];
            }
        }

        return $this->cache[$name] ?? $default;
    }

    public function set(string $name, string $value): void
    {
        $this->table()->upsert([['name' => $name, 'value' => $value]], ['name'], ['value']);
        $this->cache = null;
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('web_settings');
    }
}
