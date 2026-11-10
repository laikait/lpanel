<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;

/**
 * API tokens: `Authorization: Bearer lpat_…` for the REST API.
 *
 * A token acts as the login that made it, with exactly that login's rights,
 * and stops working when the login is disabled, suspended or removed. Only a
 * SHA-256 of the token is stored (the framework's TokenAuthenticator explains
 * why a fast hash is right for 32 random bytes); the token itself is shown
 * once, when it is made.
 */
final class ApiTokens
{
    public const PREFIX = 'lpat_';

    public function __construct(private readonly ConnectionManager $connections) {}

    public static function hash(string $token): string
    {
        return \hash('sha256', $token);
    }

    /**
     * A new token for a login.
     *
     * @return array{id: string, token: string} the token, to show once
     */
    public function issue(string $userId, string $name, ?int $days = null): array
    {
        $token = self::PREFIX . \rtrim(\strtr(\base64_encode(\random_bytes(32)), '+/', '-_'), '=');
        $id = $this->table()->insert([
            'user_id' => (int) $userId,
            'name' => \mb_substr(\trim($name), 0, 80),
            'token_hash' => self::hash($token),
            'prefix' => \substr($token, 0, 12),
            'created_at' => \gmdate('Y-m-d H:i:s'),
            'expires_at' => $days === null ? null : \gmdate('Y-m-d H:i:s', \time() + $days * 86400),
        ], 'id');

        return ['id' => (string) $id, 'token' => $token];
    }

    /** @return list<array<string, mixed>> a login's tokens, newest first, without hashes */
    public function of(string $userId): array
    {
        $rows = $this->table()->where('user_id', (int) $userId)->orderBy('id', 'desc')->get();

        return \array_values(\array_map(static function (array $row): array {
            unset($row['token_hash']);

            return $row;
        }, $rows));
    }

    /** @return array<string, mixed>|null one of a login's tokens */
    public function find(string $userId, string $id): ?array
    {
        return \ctype_digit($id) ? $this->table()->where('user_id', (int) $userId)->where('id', (int) $id)->first() : null;
    }

    public function revoke(string $userId, string $id): bool
    {
        return \ctype_digit($id) && $this->table()->where('user_id', (int) $userId)->where('id', (int) $id)->delete() > 0;
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('api_tokens');
    }
}
