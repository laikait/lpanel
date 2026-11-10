<?php

declare(strict_types=1);

namespace App\Modules\Agent;

/**
 * Carries one request line to the agent and brings one answer line back.
 * UnixSocketTransport on a server; tests bind their own.
 */
interface Transport
{
    /** @throws AgentUnavailable when nothing answered */
    public function exchange(string $line, float $timeout): string;
}
