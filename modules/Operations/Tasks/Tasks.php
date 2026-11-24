<?php

declare(strict_types=1);

namespace App\Modules\Operations\Tasks;

use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;

/**
 * What the server's agent has done and is doing (the journal of its
 * operations): backups running, PHP being installed, what failed. The agent
 * answers without the arguments or results of any operation, so nothing
 * secret is in it.
 */
final class Tasks
{
    public const STATES = ['running', 'done', 'failed', 'interrupted'];

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * The newest operations, newest first; null when the agent does not answer.
     *
     * @return list<array<string, mixed>>|null
     */
    public function list(string $state = '', int $limit = 50): ?array
    {
        if ($state !== '' && !\in_array($state, self::STATES, true)) {
            $state = '';
        }

        try {
            $reply = $this->agent->call('tasks.list', ['state' => $state, 'limit' => \max(1, \min(200, $limit))], timeout: 30.0);
        } catch (AgentUnavailable) {
            return null;
        }

        if (!$reply->done()) {
            return null;
        }

        $out = [];

        foreach ((array) ($reply->result['tasks'] ?? []) as $t) {
            if (\is_array($t) && \is_string($t['op'] ?? null)) {
                $out[] = $t;
            }
        }

        return $out;
    }
}
