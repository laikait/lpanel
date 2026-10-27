<?php

declare(strict_types=1);

namespace App\Modules\Operations\Store;

/**
 * One operation, as stored (ADR-0006):
 *
 *   queued -> claimed -> running -> succeeded | failed | needs_attention
 *   queued -> cancelled | expired
 *   claimed/running -> queued (lease lapsed, idempotent, attempts left)
 *   claimed/running -> failed | needs_attention | cancelled (lease lapsed otherwise)
 *
 * "claimed" is an agent holding the lease; "running" is that agent having
 * extended it at least once. A quick operation goes from claimed to its result.
 */
final class Operation
{
    public const QUEUED = 'queued';

    public const CLAIMED = 'claimed';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const NEEDS_ATTENTION = 'needs_attention';

    public const EXPIRED = 'expired';

    /** Still to happen, or happening. */
    public const LIVE = [self::QUEUED, self::CLAIMED, self::RUNNING];

    /** With an agent, holding a lease. */
    public const LEASED = [self::CLAIMED, self::RUNNING];

    public const STATUSES = [self::QUEUED, self::CLAIMED, self::RUNNING, self::SUCCEEDED, self::FAILED, self::CANCELLED, self::NEEDS_ATTENTION, self::EXPIRED];

    public function __construct(
        public readonly int $id,
        public readonly string $uid,
        public readonly int $serverId,
        public readonly string $op,
        public readonly int $opVersion,
        public readonly string $targetType,
        public readonly string $targetUid,
        public readonly string $payload,
        public readonly ?string $idempotencyKey,
        public readonly bool $mutating,
        public readonly bool $idempotent,
        public readonly string $status,
        public readonly int $attempt,
        public readonly int $maxAttempts,
        public readonly bool $cancelRequested,
        public readonly int $leaseSeconds,
        public readonly int $timeoutSeconds,
        public readonly ?string $leaseUntil,
        public readonly string $notAfter,
        public readonly ?string $requestedBy,
        public readonly ?string $requestedByName,
        public readonly ?string $errorCode,
        public readonly ?string $message,
        public readonly ?string $result,
        public readonly ?int $resultAttempt,
        public readonly ?string $agentVersion,
        public readonly ?string $startedAt,
        public readonly ?string $finishedAt,
        public readonly string $queuedAt,
        public readonly ?string $claimedAt,
        public readonly ?string $completedAt,
    ) {}

    public function isLive(): bool
    {
        return \in_array($this->status, self::LIVE, true);
    }

    /** The stored result, decoded; null when there is none. JSON objects stay objects. */
    public function resultValue(): mixed
    {
        return $this->result === null ? null : \json_decode($this->result, false, 32);
    }

    /**
     * The stored result as arrays, for templates.
     *
     * @return ?array<mixed>
     */
    public function resultArray(): ?array
    {
        $decoded = $this->result === null ? null : \json_decode($this->result, true, 32);

        return \is_array($decoded) ? $decoded : null;
    }

    /** Seconds from the agent taking it to its result, or null while it has none. */
    public function duration(): ?int
    {
        if ($this->claimedAt === null || $this->completedAt === null) {
            return null;
        }

        $utc = new \DateTimeZone('UTC');

        return \max(0, (new \DateTimeImmutable($this->completedAt, $utc))->getTimestamp() - (new \DateTimeImmutable($this->claimedAt, $utc))->getTimestamp());
    }

    /** @return array<string, mixed> what the API shows */
    public function toArray(): array
    {
        return [
            'id' => $this->uid,
            'op' => $this->op,
            'op_version' => $this->opVersion,
            'target' => ['type' => $this->targetType, 'id' => $this->targetUid],
            'status' => $this->status,
            'attempt' => $this->attempt,
            'max_attempts' => $this->maxAttempts,
            'cancel_requested' => $this->cancelRequested,
            'error_code' => $this->errorCode,
            'message' => $this->message,
            'result' => $this->resultValue(),
            'agent_version' => $this->agentVersion,
            'requested_by' => $this->requestedBy,
            'queued_at' => $this->queuedAt,
            'claimed_at' => $this->claimedAt,
            'completed_at' => $this->completedAt,
            'not_after' => $this->notAfter,
        ];
    }
}
