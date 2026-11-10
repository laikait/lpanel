<?php

declare(strict_types=1);

namespace App\Modules\Agent;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;

/**
 * The Admin panel dashboard's server card: live facts from the agent, or a
 * clear warning when the agent cannot be reached.
 */
final class AgentListeners
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly Authorizer $authorizer,
    ) {}

    /**
     * @param list<array<string, mixed>> $cards
     *
     * @return list<array<string, mixed>>
     */
    public function dashboard(array $cards, string $area, Identity $identity): array
    {
        if ($area !== 'admin' || !$this->authorizer->allows($identity, 'system.view')) {
            return $cards;
        }

        try {
            $reply = $this->agent->call('system.facts', timeout: 5.0);
            $data = $reply->done()
                ? ['facts' => $reply->result, 'problem' => null]
                : ['facts' => null, 'problem' => $reply->errorCode . ': ' . $reply->errorMessage];
        } catch (AgentUnavailable $e) {
            $data = ['facts' => null, 'problem' => $e->getMessage()];
        }

        \array_unshift($cards, ['template' => '@Agent/server-card', 'order' => 0, 'width' => 'full', 'data' => $data]);

        return $cards;
    }
}
