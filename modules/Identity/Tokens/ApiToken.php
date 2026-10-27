<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tokens;

/** One row of api_tokens. The token itself is never in here: only its fingerprint, in the table. */
final class ApiToken
{
    public function __construct(
        public readonly int $id,
        public readonly string $uid,
        public readonly int $userId,
        public readonly string $name,
        public readonly string $prefix,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly ?string $lastUsedAt,
        public readonly ?string $lastUsedIp,
        public readonly ?string $revokedAt,
        public readonly ?string $createdAt,
    ) {}

    public function isUsable(?\DateTimeImmutable $now = null): bool
    {
        return $this->revokedAt === null && $this->expiresAt > ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    /** active, expired or revoked */
    public function state(?\DateTimeImmutable $now = null): string
    {
        return match (true) {
            $this->revokedAt !== null => 'revoked',
            !$this->isUsable($now) => 'expired',
            default => 'active',
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->uid,
            'name' => $this->name,
            'prefix' => $this->prefix,
            'state' => $this->state(),
            'expires_at' => $this->expiresAt->format('Y-m-d H:i:s'),
            'last_used_at' => $this->lastUsedAt,
            'created_at' => $this->createdAt,
        ];
    }
}
