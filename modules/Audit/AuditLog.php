<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Http\Request;

/**
 * The append-only record of who did what (THREAT_MODEL.md A8).
 *
 * Other modules call record(); nothing updates or deletes a row except
 * prune(), which enforces Audit.retention_days. Metadata is redacted on the
 * way in: a key that looks like it holds a secret is dropped, whatever the
 * caller meant, so a careless call cannot put a password in the log.
 */
final class AuditLog
{
    public const OUTCOMES = ['allowed', 'denied', 'succeeded', 'failed'];

    private const SECRET_KEY = '/pass|secret|token|code|key|otp/i';

    private const MAX_METADATA_BYTES = 4096;

    /** What an action name, or a prefix of one, may contain. */
    public const ACTION = '/^[a-z0-9_.]{1,100}$/';

    private ?Request $request = null;

    public function __construct(private readonly ConnectionManager $connections) {}

    /** request.received: remember the request, for the address of whatever is recorded during it. */
    public function onRequest(Request $request): void
    {
        $this->request = $request;
    }

    /** @param array<string, mixed> $metadata */
    public function record(
        string $action,
        string $outcome,
        ?Identity $actor = null,
        ?string $targetType = null,
        ?string $targetId = null,
        array $metadata = [],
    ): void {
        if (!\in_array($outcome, self::OUTCOMES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown audit outcome "%s".', $outcome));
        }

        $known = $actor !== null && !$actor->isGuest();
        $via = $known ? $actor->attribute('token') : null;

        if (\is_string($via)) {
            // Which API token acted: its id, never the token. "via" is not a key redact() drops.
            $metadata = ['via' => 'api:' . $via] + $metadata;
        }

        $clean = self::redact($metadata);
        $encoded = $clean === [] ? null : \json_encode($clean, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        if ($encoded !== null && \strlen($encoded) > self::MAX_METADATA_BYTES) {
            $encoded = \json_encode(['truncated' => true], \JSON_THROW_ON_ERROR);
        }

        $this->connections->connection()->table('audit_events')->insert([
            'occurred_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'actor_type' => match (true) {
                !$known => 'anonymous',
                $actor->attribute('agent') === true => 'agent',
                $actor->attribute('system') === true => 'system',
                \is_string($via) => 'token',
                default => 'user',
            },
            'actor_id' => $known ? $actor->id : null,
            'actor_name' => $known ? \mb_substr($actor->name, 0, 190) : null,
            'action' => $action,
            'outcome' => $outcome,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip' => $this->request?->ip(),
            'metadata' => $encoded,
        ]);
    }

    /**
     * The newest events first, after $beforeId when paging.
     *
     * @param ?string $action  an exact action, or a prefix ending in "." ("auth.")
     * @param ?string $outcome one of OUTCOMES
     *
     * @return list<array<string, mixed>>
     */
    public function latest(int $limit = 50, ?int $beforeId = null, ?string $action = null, ?string $outcome = null): array
    {
        // The builder is immutable: every condition is assigned back, or it is silently dropped.
        $query = $this->connections->connection()->table('audit_events')->orderByDesc('id')->limit(\max(1, \min($limit, 200)));

        if ($beforeId !== null) {
            $query = $query->where('id', '<', $beforeId);
        }

        // Actions are [a-z0-9_.] (ACTION): no LIKE wildcard but "_", which only widens a prefix by a character.
        if ($action !== null && \preg_match(self::ACTION, $action) === 1) {
            $query = \str_ends_with($action, '.')
                ? $query->where('action', 'like', $action . '%')
                : $query->where('action', $action);
        }

        if ($outcome !== null && \in_array($outcome, self::OUTCOMES, true)) {
            $query = $query->where('outcome', $outcome);
        }

        $events = [];

        foreach ($query->get() as $row) {
            $metadata = \is_string($row['metadata'] ?? null) ? \json_decode($row['metadata'], true) : null;
            $events[] = [
                'id' => (int) $row['id'],
                'occurred_at' => (string) $row['occurred_at'],
                'actor' => \in_array($row['actor_type'], ['user', 'token', 'agent', 'system'], true) ? ['id' => $row['actor_id'], 'name' => $row['actor_name'], 'type' => $row['actor_type']] : null,
                'action' => (string) $row['action'],
                'outcome' => (string) $row['outcome'],
                'target' => $row['target_type'] === null ? null : ['type' => $row['target_type'], 'id' => $row['target_id']],
                'ip' => $row['ip'],
                'metadata' => \is_array($metadata) ? $metadata : new \stdClass(),
            ];
        }

        return $events;
    }

    /** How many events since $since, optionally of one outcome and action prefix. */
    public function countSince(\DateTimeImmutable $since, ?string $outcome = null, ?string $actionPrefix = null): int
    {
        $query = $this->connections->connection()->table('audit_events')->where('occurred_at', '>=', $since);

        if ($outcome !== null) {
            $query = $query->where('outcome', $outcome);
        }

        if ($actionPrefix !== null && \preg_match(self::ACTION, $actionPrefix) === 1) {
            $query = $query->where('action', 'like', $actionPrefix . '%');
        }

        return $query->count();
    }

    /** Delete events older than $days. The only way a row leaves this table. */
    public function prune(int $days, ?\DateTimeImmutable $now = null): int
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('Audit retention must be at least one day.');
        }

        $cutoff = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify(\sprintf('-%d days', $days));

        return $this->connections->connection()->table('audit_events')->where('occurred_at', '<', $cutoff)->delete();
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private static function redact(array $metadata): array
    {
        $clean = [];

        foreach ($metadata as $key => $value) {
            // A list's keys are integers: nothing to judge, the values are still redacted below.
            if (\preg_match(self::SECRET_KEY, (string) $key) === 1) {
                continue;
            }

            $clean[$key] = \is_array($value) ? self::redact($value) : (\is_scalar($value) || $value === null ? $value : \get_debug_type($value));
        }

        return $clean;
    }
}
