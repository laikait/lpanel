<?php

declare(strict_types=1);

namespace App\Modules\Servers\Store;

use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionManager;
use App\Engine\Hook\HookEngine;
use App\Modules\Servers\Agent\Protocol;
use App\Modules\Shared\Support\Ulid;

/**
 * Servers, their agents' keys, enrollment tokens and seen request ids. The
 * only code that touches those tables.
 *
 * Hooks: `servers.revoked` (Server), inside the revoking transaction, so
 * whatever a listener changes is revoked with the agent or not at all.
 *
 * The query builder is immutable: every condition is chained or assigned back.
 */
final class ServerStore
{
    public function __construct(private readonly ConnectionManager $connections, private readonly HookEngine $hooks) {}

    private function db(): Connection
    {
        return $this->connections->connection();
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    // ---- servers ------------------------------------------------------------

    public function create(string $name, ?string $createdBy): Server
    {
        $uid = Ulid::generate();
        $now = self::now();
        $this->db()->table('servers')->insert([
            'uid' => $uid,
            'name' => $name,
            'state' => Server::PENDING,
            'rotate_requested' => false,
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->byUid($uid) ?? throw new \LogicException('A server just created cannot be read back.');
    }

    public function byUid(string $uid): ?Server
    {
        return Ulid::isValid($uid) ? $this->hydrate($this->db()->table('servers')->where('uid', $uid)->first()) : null;
    }

    public function byAgentId(string $agentId): ?Server
    {
        return \preg_match(Protocol::UUID, $agentId) === 1
            ? $this->hydrate($this->db()->table('servers')->where('agent_id', $agentId)->first())
            : null;
    }

    public function byId(int $id): ?Server
    {
        return $this->hydrate($this->db()->table('servers')->where('id', $id)->first());
    }

    /** @return list<Server> by name */
    public function all(): array
    {
        $servers = [];

        foreach ($this->db()->table('servers')->orderBy('name')->get() as $row) {
            $server = $this->hydrate($row);

            if ($server !== null) {
                $servers[] = $server;
            }
        }

        return $servers;
    }

    /** @return array{total: int, online: int, offline: int, pending: int, revoked: int} */
    public function counts(int $offlineAfter): array
    {
        $counts = ['total' => 0, 'online' => 0, 'offline' => 0, 'pending' => 0, 'revoked' => 0];

        foreach ($this->all() as $server) {
            ++$counts['total'];

            match ($server->status($offlineAfter)) {
                'online' => ++$counts['online'],
                'pending' => ++$counts['pending'],
                'revoked' => ++$counts['revoked'],
                default => ++$counts['offline'],
            };
        }

        return $counts;
    }

    /**
     * What a heartbeat says, and that the server was seen.
     *
     * @param list<int>                $protocols
     * @param array<string, list<int>> $capabilities
     * @param array<string, mixed>     $health
     */
    public function recordHeartbeat(Server $server, ?string $ip, string $agentVersion, array $protocols, array $capabilities, array $health): void
    {
        $this->db()->table('servers')->where('id', $server->id)->update([
            'agent_version' => $agentVersion,
            'protocols' => \json_encode($protocols, \JSON_THROW_ON_ERROR),
            'capabilities' => \json_encode((object) $capabilities, \JSON_THROW_ON_ERROR),
            'health' => \json_encode($health, \JSON_THROW_ON_ERROR),
            'last_seen_at' => self::now(),
            'last_seen_ip' => $ip,
            'updated_at' => self::now(),
        ]);
    }

    public function requestRotation(Server $server): void
    {
        $this->db()->table('servers')->where('id', $server->id)->update(['rotate_requested' => true, 'updated_at' => self::now()]);
    }

    /** The agent is cut off: every key and outstanding enrollment revoked. Re-adding it needs a new enrollment. */
    public function revoke(Server $server): void
    {
        $now = self::now();
        $hooks = $this->hooks;

        $this->db()->transaction(static function (Connection $db) use ($server, $now, $hooks): void {
            $db->table('agent_credentials')->where('server_id', $server->id)->where('state', '!=', AgentCredential::REVOKED)
                ->update(['state' => AgentCredential::REVOKED, 'revoked_at' => $now, 'updated_at' => $now]);
            $db->table('agent_enrollments')->where('server_id', $server->id)->whereNull('consumed_at')->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'updated_at' => $now]);
            $db->table('servers')->where('id', $server->id)
                ->update(['state' => Server::REVOKED, 'rotate_requested' => false, 'updated_at' => $now]);
            $hooks->do('servers.revoked', $server);
        });
    }

    /**
     * What the agent's system.info found, shown as the server's facts. An
     * empty value leaves the one already known.
     *
     * @param array{hostname: string, os_id: string, os_version: string, kernel: string} $facts
     */
    public function recordFacts(Server $server, array $facts): void
    {
        $limits = ['hostname' => 255, 'os_id' => 64, 'os_version' => 64, 'kernel' => 128];
        $changes = [];

        foreach ($limits as $column => $max) {
            if ($facts[$column] !== '') {
                $changes[$column] = \substr($facts[$column], 0, $max);
            }
        }

        if ($changes !== []) {
            $this->db()->table('servers')->where('id', $server->id)->update($changes + ['updated_at' => self::now()]);
        }
    }

    // ---- credentials ----------------------------------------------------------

    /** @return list<AgentCredential> newest first */
    public function credentials(Server $server): array
    {
        $list = [];

        foreach ($this->db()->table('agent_credentials')->where('server_id', $server->id)->orderByDesc('id')->get() as $row) {
            $list[] = self::credential($row);
        }

        return $list;
    }

    public function credentialByKid(Server $server, string $kid): ?AgentCredential
    {
        $row = $this->db()->table('agent_credentials')->where('server_id', $server->id)->where('kid', $kid)->first();

        return $row === null ? null : self::credential($row);
    }

    /**
     * Swap in a new key. The old one stays valid for $overlapSeconds, for
     * requests already in flight, and is then revoked by the sweep.
     */
    public function rotate(Server $server, AgentCredential $old, string $newPublicKey, string $newCertSha256, int $overlapSeconds): string
    {
        $kid = Protocol::keyId($newPublicKey);
        $now = self::now();

        $this->db()->transaction(static function (Connection $db) use ($server, $old, $newPublicKey, $newCertSha256, $kid, $now, $overlapSeconds): void {
            // One key rotates out at a time: anything still overlapping from a previous rotation ends now.
            $db->table('agent_credentials')->where('server_id', $server->id)->where('state', AgentCredential::ROTATING)
                ->update(['state' => AgentCredential::REVOKED, 'revoked_at' => $now, 'updated_at' => $now]);
            $db->table('agent_credentials')->where('id', $old->id)
                ->update(['state' => AgentCredential::ROTATING, 'retire_at' => $now->modify(\sprintf('+%d seconds', $overlapSeconds)), 'updated_at' => $now]);
            $db->table('agent_credentials')->insert([
                'server_id' => $server->id,
                'kid' => $kid,
                'public_key' => Protocol::b64($newPublicKey),
                'tls_cert_sha256' => $newCertSha256,
                'state' => AgentCredential::ACTIVE,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $db->table('servers')->where('id', $server->id)->update(['rotate_requested' => false, 'updated_at' => $now]);
        });

        return $kid;
    }

    public function kidExists(string $kid): bool
    {
        return $this->db()->table('agent_credentials')->where('kid', $kid)->exists();
    }

    // ---- enrollment -------------------------------------------------------------

    /**
     * A new single-use token for this server. Only its hash is stored; the
     * token is returned once, to be shown once.
     */
    public function createEnrollment(Server $server, ?string $createdBy, int $minutes): string
    {
        $token = Protocol::b64(\random_bytes(32));
        $now = self::now();

        $this->db()->transaction(static function (Connection $db) use ($server, $createdBy, $minutes, $token, $now): void {
            // A newer token replaces any earlier one still outstanding.
            $db->table('agent_enrollments')->where('server_id', $server->id)->whereNull('consumed_at')->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'updated_at' => $now]);
            $db->table('agent_enrollments')->insert([
                'server_id' => $server->id,
                'token_hash' => Protocol::sha256($token),
                'expires_at' => $now->modify(\sprintf('+%d minutes', $minutes)),
                'created_by' => $createdBy,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return $token;
    }

    /** @return ?array{id: int, server_id: int, token_hash: string, expires_at: string, consumed_at: ?string, revoked_at: ?string} */
    public function enrollmentByToken(string $token): ?array
    {
        $row = $this->db()->table('agent_enrollments')->where('token_hash', Protocol::sha256($token))->first();

        if ($row === null || !\hash_equals((string) $row['token_hash'], Protocol::sha256($token))) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'server_id' => (int) $row['server_id'],
            'token_hash' => (string) $row['token_hash'],
            'expires_at' => (string) $row['expires_at'],
            'consumed_at' => isset($row['consumed_at']) ? (string) $row['consumed_at'] : null,
            'revoked_at' => isset($row['revoked_at']) ? (string) $row['revoked_at'] : null,
        ];
    }

    /** When the outstanding token for this server expires, if there is one. */
    public function outstandingEnrollment(Server $server): ?string
    {
        $row = $this->db()->table('agent_enrollments')
            ->where('server_id', $server->id)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', self::now())
            ->orderByDesc('id')
            ->first();

        return $row === null ? null : (string) $row['expires_at'];
    }

    /**
     * Spend the token and bind the agent to the server, in one transaction.
     *
     * @param array<string, string> $facts
     *
     * @return ?string the new agent_id, or null when the token was spent by a racing request
     */
    public function enroll(int $enrollmentId, Server $server, ?string $ip, string $publicKey, string $certSha256, array $facts): ?string
    {
        $agentId = Protocol::uuid();
        $now = self::now();

        return $this->db()->transaction(static function (Connection $db) use ($enrollmentId, $server, $ip, $publicKey, $certSha256, $facts, $agentId, $now): ?string {
            $spent = $db->table('agent_enrollments')
                ->where('id', $enrollmentId)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->update(['consumed_at' => $now, 'consumed_from_ip' => $ip, 'updated_at' => $now]);

            if ($spent !== 1) {
                return null;
            }

            // A server enrolled before (and revoked since) starts again with this agent alone.
            $db->table('agent_credentials')->where('server_id', $server->id)->where('state', '!=', AgentCredential::REVOKED)
                ->update(['state' => AgentCredential::REVOKED, 'revoked_at' => $now, 'updated_at' => $now]);
            $db->table('agent_credentials')->insert([
                'server_id' => $server->id,
                'kid' => Protocol::keyId($publicKey),
                'public_key' => Protocol::b64($publicKey),
                'tls_cert_sha256' => $certSha256,
                'state' => AgentCredential::ACTIVE,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $db->table('servers')->where('id', $server->id)->update([
                'agent_id' => $agentId,
                'state' => Server::ACTIVE,
                'hostname' => $facts['hostname'] ?: null,
                'os_id' => $facts['os_id'] ?: null,
                'os_version' => $facts['os_version'] ?: null,
                'kernel' => $facts['kernel'] ?: null,
                'machine_id_sha256' => $facts['machine_id_sha256'] ?: null,
                'agent_version' => $facts['agent_version'] ?: null,
                'protocols' => null,
                'capabilities' => null,
                'health' => null,
                'rotate_requested' => false,
                'enrolled_at' => $now,
                'last_seen_at' => $now,
                'last_seen_ip' => $ip,
                'updated_at' => $now,
            ]);

            return $agentId;
        });
    }

    // ---- replay protection ---------------------------------------------------

    /**
     * Remember a request id until $expiresAt. False when it was already seen:
     * a replay. The unique index decides, so two copies racing cannot both pass.
     */
    public function rememberRequest(string $agentId, string $requestId, int $expiresAt): bool
    {
        try {
            $this->db()->table('agent_seen_requests')->insert(['agent_id' => $agentId, 'request_id' => $requestId, 'expires_at' => $expiresAt]);
        } catch (\Throwable $e) {
            // The unique index refused it: a replay, unless the row is not there and something else failed.
            if ($this->db()->table('agent_seen_requests')->where('agent_id', $agentId)->where('request_id', $requestId)->exists()) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    /**
     * Forget request ids past their window, and retire keys whose overlap has ended.
     *
     * @return array{requests: int, keys: int}
     */
    public function sweep(?\DateTimeImmutable $now = null): array
    {
        $now ??= self::now();

        return [
            'requests' => $this->db()->table('agent_seen_requests')->where('expires_at', '<', $now->getTimestamp())->delete(),
            'keys' => $this->db()->table('agent_credentials')
                ->where('state', AgentCredential::ROTATING)
                ->where('retire_at', '<=', $now)
                ->update(['state' => AgentCredential::REVOKED, 'revoked_at' => $now, 'updated_at' => $now]),
        ];
    }

    // ---- rows ----------------------------------------------------------------------

    /** @param array<string, mixed> $row */
    private static function credential(array $row): AgentCredential
    {
        return new AgentCredential(
            id: (int) $row['id'],
            serverId: (int) $row['server_id'],
            kid: (string) $row['kid'],
            publicKey: Protocol::unb64((string) $row['public_key']) ?? '',
            tlsCertSha256: (string) $row['tls_cert_sha256'],
            state: (string) $row['state'],
            retireAt: isset($row['retire_at']) ? (string) $row['retire_at'] : null,
            createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null,
        );
    }

    /** @param array<string, mixed>|null $row */
    private function hydrate(?array $row): ?Server
    {
        if ($row === null) {
            return null;
        }

        $json = static function (mixed $value): array {
            $decoded = \is_string($value) ? \json_decode($value, true) : null;

            return \is_array($decoded) ? $decoded : [];
        };
        $text = static fn(mixed $v): ?string => $v === null || $v === '' ? null : (string) $v;

        /** @var list<int> $protocols */
        $protocols = \array_values(\array_filter($json($row['protocols'] ?? null), 'is_int'));
        /** @var array<string, list<int>> $capabilities */
        $capabilities = $json($row['capabilities'] ?? null);

        return new Server(
            id: (int) $row['id'],
            uid: (string) $row['uid'],
            name: (string) $row['name'],
            state: (string) $row['state'],
            agentId: $text($row['agent_id'] ?? null),
            hostname: $text($row['hostname'] ?? null),
            osId: $text($row['os_id'] ?? null),
            osVersion: $text($row['os_version'] ?? null),
            kernel: $text($row['kernel'] ?? null),
            agentVersion: $text($row['agent_version'] ?? null),
            protocols: $protocols,
            capabilities: $capabilities,
            health: $json($row['health'] ?? null),
            rotateRequested: (bool) ($row['rotate_requested'] ?? false),
            enrolledAt: $text($row['enrolled_at'] ?? null),
            lastSeenAt: $text($row['last_seen_at'] ?? null),
            lastSeenIp: $text($row['last_seen_ip'] ?? null),
            createdAt: $text($row['created_at'] ?? null),
        );
    }
}
