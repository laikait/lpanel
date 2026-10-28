<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Modules\Operations\Catalogue\Catalogue;
use App\Modules\Operations\Store\Operation;
use App\Modules\Operations\Store\OperationStore;
use App\Modules\Servers\Agent\AgentSettings;
use App\Modules\Servers\Agent\Protocol;
use App\Modules\Servers\Store\Server;
use App\Modules\Shared\Ui\Pages;

/**
 * The cards Operations adds to a server's page (the servers.detail.panels
 * filter): live resource use and service state from the latest results, and
 * the operations the agent can run, with the most recent ones.
 */
final class ServerPanels
{
    public function __construct(
        private readonly OperationStore $operations,
        private readonly OperationViews $views,
        private readonly Catalogue $catalogue,
        private readonly AgentSettings $agents,
        private readonly Authorizer $authorizer,
        private readonly Pages $pages,
    ) {}

    /**
     * @param array<mixed> $panels what other modules added
     *
     * @return array<mixed>
     */
    public function __invoke(array $panels, Server $server, Identity $identity): array
    {
        if ($server->state === Server::PENDING) {
            return $panels;
        }

        $online = $server->status($this->agents->offlineAfter()) === 'online' && \in_array(Protocol::VERSION, $server->protocols, true);
        $runUrl = $this->pages->url('operations.request', ['id' => $server->uid]);
        $live = [];
        $recent = [];

        foreach ($this->operations->page(8, null, null, $server->id) as $op) {
            $recent[] = $this->views->row($op, null);

            if ($op->isLive()) {
                $live[$op->op] ??= $this->views->row($op, null);
            }
        }

        // What can be asked for: in the catalogue, on this agent, and allowed to this person.
        $runnable = [];

        foreach ($this->catalogue->all() as $definition) {
            // An account's or a website's operation is asked for from its own screen.
            if ($definition->scope !== 'server') {
                continue;
            }

            $supported = \in_array($definition->version, $server->capabilities[$definition->op] ?? [], true);
            $runnable[$definition->op] = [
                'definition' => $definition,
                'allowed' => $online && $supported && $this->authorizer->allows($identity, $definition->capability),
                'supported' => $supported,
                'live' => $live[$definition->op] ?? null,
            ];
        }

        $metrics = $this->operations->latestSucceeded($server, 'system.metrics');
        $services = $this->operations->latestSucceeded($server, 'service.list_status');
        $context = [
            'ops_server' => $server,
            'ops_online' => $online,
            'ops_run_url' => $runUrl,
            'ops_runnable' => $runnable,
        ];

        $panels[] = ['template' => '@Operations/server_resources.twig', 'column' => 'main', 'context' => $context + [
            'ops_metrics' => OperationViews::metrics($metrics, $this->operations->latestSucceeded($server, 'system.info')),
            'ops_metrics_url' => $metrics === null ? null : $this->pages->url('operations.show', ['id' => $metrics->uid]),
            'ops_services' => OperationViews::services($services),
            'ops_services_at' => $services?->completedAt,
            'ops_services_failure' => $this->lastFailure($server, 'service.list_status', $services),
        ]];

        $panels[] = ['template' => '@Operations/server_operations.twig', 'column' => 'side', 'context' => $context + [
            'ops_recent' => $recent,
            'ops_index_url' => $this->pages->url('operations.index', ['server' => $server->uid]),
        ]];

        return $panels;
    }

    /**
     * The newest run of $op, if it failed after the last success: what to explain instead of a result.
     *
     * @return ?array{code: ?string, message: ?string, url: string}
     */
    private function lastFailure(Server $server, string $op, ?Operation $succeeded): ?array
    {
        foreach ($this->operations->page(10, null, 'failed', $server->id) as $failed) {
            if ($failed->op === $op && ($succeeded === null || $failed->id > $succeeded->id)) {
                return ['code' => $failed->errorCode, 'message' => $failed->message, 'url' => $this->pages->url('operations.show', ['id' => $failed->uid])];
            }
        }

        return null;
    }
}
