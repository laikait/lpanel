<?php

declare(strict_types=1);

namespace App\Modules\Servers\Store;

/** One row of servers. Whether it is online is a question of when it was last seen; see status(). */
final class Server
{
    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const REVOKED = 'revoked';

    /**
     * @param list<int>               $protocols
     * @param array<string, list<int>> $capabilities op => versions
     * @param array<string, mixed>    $health
     */
    public function __construct(
        public readonly int $id,
        public readonly string $uid,
        public readonly string $name,
        public readonly string $state,
        public readonly ?string $agentId,
        public readonly ?string $hostname,
        public readonly ?string $osId,
        public readonly ?string $osVersion,
        public readonly ?string $kernel,
        public readonly ?string $agentVersion,
        public readonly array $protocols,
        public readonly array $capabilities,
        public readonly array $health,
        public readonly bool $rotateRequested,
        public readonly ?string $enrolledAt,
        public readonly ?string $lastSeenAt,
        public readonly ?string $lastSeenIp,
        public readonly ?string $createdAt,
    ) {}

    /**
     * pending, online, offline or revoked.
     *
     * @param int $offlineAfter seconds without a heartbeat before a server counts as offline
     */
    public function status(int $offlineAfter, ?\DateTimeImmutable $now = null): string
    {
        if ($this->state !== self::ACTIVE) {
            return $this->state;
        }

        if ($this->lastSeenAt === null) {
            return 'offline';
        }

        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $seen = new \DateTimeImmutable($this->lastSeenAt, new \DateTimeZone('UTC'));

        return $now->getTimestamp() - $seen->getTimestamp() <= $offlineAfter ? 'online' : 'offline';
    }

    /** "Ubuntu 24.04", or what is known of it. */
    public function osLabel(): ?string
    {
        if ($this->osId === null || $this->osId === '') {
            return null;
        }

        $names = ['ubuntu' => 'Ubuntu', 'debian' => 'Debian'];

        return \trim(($names[$this->osId] ?? $this->osId) . ' ' . ($this->osVersion ?? ''));
    }

    /** @return array<string, mixed> what the API shows */
    public function toArray(int $offlineAfter): array
    {
        return [
            'id' => $this->uid,
            'name' => $this->name,
            'status' => $this->status($offlineAfter),
            'agent_id' => $this->agentId,
            'hostname' => $this->hostname,
            'os' => ['id' => $this->osId, 'version' => $this->osVersion, 'kernel' => $this->kernel],
            'agent_version' => $this->agentVersion,
            'protocols' => $this->protocols,
            'capabilities' => (object) $this->capabilities,
            'enrolled_at' => $this->enrolledAt,
            'last_seen_at' => $this->lastSeenAt,
        ];
    }
}
