<?php

declare(strict_types=1);

namespace App\Modules\Servers\Agent;

use App\Engine\Config\Config;

/** The Servers.* settings the agent protocol and the screens read, each with its bounds. */
final class AgentSettings
{
    public function __construct(private readonly Config $config) {}

    /** @return array{idle: int, max: int} what the agent is told about polling */
    public function poll(): array
    {
        $idle = $this->int('Servers.poll.idle', 5, 1, 600);

        return ['idle' => $idle, 'max' => $this->int('Servers.poll.max', 300, $idle, 3600)];
    }

    /** Seconds without a heartbeat before a server shows as offline: three missed heartbeats by default. */
    public function offlineAfter(): int
    {
        return $this->int('Servers.offline_after', 180, 30, 86400);
    }

    public function enrollmentMinutes(): int
    {
        return $this->int('Servers.enrollment_minutes', 15, 1, 1440);
    }

    /** How long an old key keeps working after the agent registers a new one. */
    public function rotationOverlap(): int
    {
        return $this->int('Servers.rotation_overlap', 86400, 60, 7 * 86400);
    }

    private function int(string $key, int $default, int $min, int $max): int
    {
        return \max($min, \min($max, $this->config->int($key, $default) ?? $default));
    }
}
