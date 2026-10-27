<?php

declare(strict_types=1);

namespace App\Modules\Servers\Store;

/** An agent's signing key and the TLS certificate that goes with it. */
final class AgentCredential
{
    public const ACTIVE = 'active';

    /** Replaced by a newer key, still accepted until retire_at. */
    public const ROTATING = 'rotating';

    public const REVOKED = 'revoked';

    public function __construct(
        public readonly int $id,
        public readonly int $serverId,
        public readonly string $kid,
        /** The raw 32-byte Ed25519 public key. */
        public readonly string $publicKey,
        public readonly string $tlsCertSha256,
        public readonly string $state,
        public readonly ?string $retireAt,
        public readonly ?string $createdAt,
    ) {}

    /** Does this key still sign for its agent? */
    public function isLive(?\DateTimeImmutable $now = null): bool
    {
        if ($this->state === self::ACTIVE) {
            return true;
        }

        if ($this->state !== self::ROTATING || $this->retireAt === null) {
            return false;
        }

        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return new \DateTimeImmutable($this->retireAt, new \DateTimeZone('UTC')) > $now;
    }
}
