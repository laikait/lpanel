<?php

declare(strict_types=1);

namespace App\Modules\Agent;

/**
 * The agent's answer to one request.
 *
 * status is done, failed, accepted (running in the background; ask op.status),
 * running, or refused (it never started; the error code says why).
 */
final class AgentReply
{
    /** @param array<string, mixed> $result */
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly array $result = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function fromLine(string $line): self
    {
        $data = \json_decode($line, true);

        if (!\is_array($data) || ($data['v'] ?? null) !== Protocol::VERSION || !\is_string($data['status'] ?? null)) {
            throw new AgentUnavailable('The agent answered with something that is not a version-1 response.');
        }

        $error = \is_array($data['error'] ?? null) ? $data['error'] : [];

        return new self(
            \is_string($data['id'] ?? null) ? $data['id'] : '',
            $data['status'],
            \is_array($data['result'] ?? null) ? $data['result'] : [],
            \is_string($error['code'] ?? null) ? $error['code'] : null,
            \is_string($error['message'] ?? null) ? $error['message'] : null,
        );
    }

    public function done(): bool
    {
        return $this->status === 'done';
    }

    public function finished(): bool
    {
        return \in_array($this->status, ['done', 'failed', 'refused'], true);
    }
}
