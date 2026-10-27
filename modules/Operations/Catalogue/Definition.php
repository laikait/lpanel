<?php

declare(strict_types=1);

namespace App\Modules\Operations\Catalogue;

/**
 * One operation at one version, as AGENT_PROTOCOL.md §6.2 declares it. The
 * agent's registry holds the same definition in Go; CatalogueTest checks the
 * two against the agent's tests/Fixtures/lpa/catalogue.json.
 */
final class Definition
{
    public const AUDIT_INFO = 'info';

    public const AUDIT_NOTICE = 'notice';

    public const AUDIT_CRITICAL = 'critical';

    public function __construct(
        public readonly string $op,
        public readonly int $version,
        public readonly string $label,
        public readonly string $summary,
        public readonly bool $mutating,
        public readonly bool $idempotent,
        // The capability a person needs to ask for it.
        public readonly string $capability,
        public readonly string $scope,
        public readonly int $timeoutSeconds,
        public readonly int $maxResultBytes,
        // Deliveries before a lapsed lease stops being retried. Idempotent operations only.
        public readonly int $maxAttempts,
        public readonly string $audit,
        public readonly Shape $payload,
        public readonly Shape $result,
    ) {
        if ($maxAttempts < 1 || $maxAttempts > 10 || (!$idempotent && $maxAttempts !== 1)) {
            throw new \LogicException(\sprintf('%s: max_attempts must be 1-10, and 1 unless it is idempotent.', $op));
        }
    }

    /** How long a claim holds before it lapses: the run time and a margin. The agent extends it while it runs. */
    public function leaseSeconds(): int
    {
        return \min(3600, \max(30, $this->timeoutSeconds + 30));
    }

    /** @return array<string, mixed> what the API shows */
    public function toArray(): array
    {
        return [
            'op' => $this->op,
            'op_version' => $this->version,
            'label' => $this->label,
            'summary' => $this->summary,
            'mutating' => $this->mutating,
            'idempotent' => $this->idempotent,
            'capability' => $this->capability,
            'scope' => $this->scope,
            'timeout_seconds' => $this->timeoutSeconds,
            'max_attempts' => $this->maxAttempts,
        ];
    }
}
