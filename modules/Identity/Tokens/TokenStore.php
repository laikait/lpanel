<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tokens;

use App\Engine\Auth\Authenticators\TokenAuthenticator;
use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionManager;
use App\Modules\Shared\Support\Ulid;

/**
 * API tokens: issued, listed, revoked, and looked up by what a client sends.
 *
 * A token is "lpat_" and 43 characters of base64url (32 random bytes). It is
 * returned once by issue() and stored only as its SHA-256 fingerprint, so a
 * copy of the table is not a set of working credentials.
 */
final class TokenStore
{
    public const PREFIX = 'lpat_';

    /** Lifetimes a person can choose, in days. There is no "never". */
    public const LIFETIMES = [30, 90, 180, 365];

    public const MAX_ACTIVE = 25;

    /** last_used_at is written at most this often, so a busy client is not a write per request. */
    private const TOUCH_SECONDS = 60;

    public function __construct(private readonly ConnectionManager $connections) {}

    private function db(): Connection
    {
        return $this->connections->connection();
    }

    /**
     * @return array{ApiToken, string} the token, and its plaintext: shown once, then gone
     */
    public function issue(int $userId, string $name, int $days): array
    {
        if (!\in_array($days, self::LIFETIMES, true)) {
            throw new \InvalidArgumentException(\sprintf('A token lasts %s days, not %d.', \implode(', ', self::LIFETIMES), $days));
        }

        $plain = self::PREFIX . \rtrim(\strtr(\base64_encode(\random_bytes(32)), '+/', '-_'), '=');
        $uid = Ulid::generate();
        $now = self::now();

        $this->db()->table('api_tokens')->insert([
            'uid' => $uid,
            'user_id' => $userId,
            'name' => $name,
            'prefix' => \substr($plain, 0, 12),
            'fingerprint' => TokenAuthenticator::fingerprint($plain),
            'expires_at' => $now->modify(\sprintf('+%d days', $days)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$this->byUid($uid) ?? throw new \LogicException('A token just issued cannot be read back.'), $plain];
    }

    /** The usable token a client sent, or null. Nothing about the token is logged. */
    public function find(string $plain): ?ApiToken
    {
        if (!\str_starts_with($plain, self::PREFIX) || \strlen($plain) > 100) {
            return null;
        }

        $token = $this->hydrate($this->db()->table('api_tokens')->where('fingerprint', TokenAuthenticator::fingerprint($plain))->first());

        return $token !== null && $token->isUsable() ? $token : null;
    }

    public function byUid(string $uid): ?ApiToken
    {
        return Ulid::isValid($uid) ? $this->hydrate($this->db()->table('api_tokens')->where('uid', $uid)->first()) : null;
    }

    /** @return list<ApiToken> newest first */
    public function forUser(int $userId): array
    {
        $tokens = [];

        foreach ($this->db()->table('api_tokens')->where('user_id', $userId)->orderByDesc('id')->get() as $row) {
            $token = $this->hydrate($row);

            if ($token !== null) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    public function activeCount(int $userId): int
    {
        return $this->db()->table('api_tokens')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', self::now())
            ->count();
    }

    /** Revoke one of this user's tokens. False when it is not theirs or was already revoked. */
    public function revoke(int $userId, string $uid): bool
    {
        if (!Ulid::isValid($uid)) {
            return false;
        }

        return $this->db()->table('api_tokens')
            ->where('uid', $uid)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => self::now(), 'updated_at' => self::now()]) > 0;
    }

    /** @return int how many were revoked */
    public function revokeAll(int $userId): int
    {
        return $this->db()->table('api_tokens')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => self::now(), 'updated_at' => self::now()]);
    }

    public function touch(ApiToken $token, ?string $ip): void
    {
        $last = $token->lastUsedAt === null ? null : new \DateTimeImmutable($token->lastUsedAt, new \DateTimeZone('UTC'));
        $now = self::now();

        if ($last !== null && $now->getTimestamp() - $last->getTimestamp() < self::TOUCH_SECONDS && $token->lastUsedIp === $ip) {
            return;
        }

        $this->db()->table('api_tokens')->where('id', $token->id)->update(['last_used_at' => $now, 'last_used_ip' => $ip]);
    }

    /** @param array<string, mixed>|null $row */
    private function hydrate(?array $row): ?ApiToken
    {
        if ($row === null) {
            return null;
        }

        return new ApiToken(
            id: (int) $row['id'],
            uid: (string) $row['uid'],
            userId: (int) $row['user_id'],
            name: (string) $row['name'],
            prefix: (string) $row['prefix'],
            expiresAt: new \DateTimeImmutable((string) $row['expires_at'], new \DateTimeZone('UTC')),
            lastUsedAt: isset($row['last_used_at']) ? (string) $row['last_used_at'] : null,
            lastUsedIp: isset($row['last_used_ip']) ? (string) $row['last_used_ip'] : null,
            revokedAt: isset($row['revoked_at']) ? (string) $row['revoked_at'] : null,
            createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null,
        );
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
