<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Modules\Operations\Catalogue\Catalogue;
use App\Modules\Operations\OperationException;
use App\Modules\Operations\OperationRequests;
use App\Modules\Operations\Store\Operation;
use App\Modules\Operations\Store\OperationStore;
use App\Modules\Servers\Store\Server;
use App\Modules\Servers\Store\ServerStore;
use App\Modules\Shared\Ui\Pages;

/**
 * /operations: everything asked of the agents, newest first, filtered by
 * state and server; one operation with its result and its history; and the
 * forms that ask for one (from a server's page) and call one off.
 *
 * A page showing work still under way watches it through the API and
 * reloads when its state changes (data-lp-watch in app.js).
 */
final class OperationsPage
{
    private const GROUP_LABELS = ['' => 'All', 'active' => 'In progress', 'succeeded' => 'Succeeded', 'failed' => 'Failed', 'attention' => 'Needs attention', 'cancelled' => 'Cancelled'];

    public function __construct(
        private readonly Pages $pages,
        private readonly OperationStore $operations,
        private readonly OperationRequests $requests,
        private readonly OperationViews $views,
        private readonly ServerStore $servers,
        private readonly Catalogue $catalogue,
        private readonly Authorizer $authorizer,
    ) {}

    public function index(Request $request, Identity $identity): Response
    {
        $group = $request->query('status');
        $group = \is_string($group) && isset(OperationStore::GROUPS[$group]) ? $group : null;
        $before = \filter_var($request->query('before'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $before = $before === false ? null : $before;
        $serverUid = $request->query('server');
        $server = \is_string($serverUid) && $serverUid !== '' ? $this->servers->byUid($serverUid) : null;
        $limit = 50;

        $servers = [];

        foreach ($this->servers->all() as $s) {
            $servers[$s->id] = $s;
        }

        $page = $this->operations->page($limit, $before, $group, $server?->id);
        $query = static fn(array $more): array => \array_filter(['status' => $group, 'server' => $server?->uid] + $more, static fn($v): bool => $v !== null && $v !== '');

        return $this->pages->render($request, '@Operations/index', [
            'rows' => \array_map(fn(Operation $op): array => $this->views->row($op, $servers[$op->serverId] ?? null), $page),
            'groups' => \array_map(fn(string $key, string $label): array => [
                'label' => $label,
                'active' => ($group ?? '') === $key,
                'url' => $this->pages->url('operations.index', \array_filter(['status' => $key, 'server' => $server?->uid])),
            ], \array_keys(self::GROUP_LABELS), self::GROUP_LABELS),
            'group' => $group,
            'server' => $server,
            'servers' => \array_values($servers),
            'paged' => $before !== null,
            'older_url' => \count($page) === $limit ? $this->pages->url('operations.index', $query(['before' => $page[\count($page) - 1]->id])) : null,
            'newest_url' => $this->pages->url('operations.index', $query([])),
            'index_url' => $this->pages->url('operations.index'),
        ], identity: $identity, section: 'operations');
    }

    public function show(Request $request, Identity $identity, string $id): Response
    {
        $op = $this->operations->byUid($id) ?? throw HttpException::notFound();
        $server = $this->servers->byId($op->serverId);
        $definition = $this->catalogue->find($op->op, $op->opVersion);
        $info = $server === null ? null : $this->operations->latestSucceeded($server, 'system.info');

        return $this->pages->render($request, '@Operations/show', [
            'row' => $this->views->row($op, $server),
            'op' => $op,
            'definition' => $definition,
            'state_text' => OperationViews::STATES[$op->status] ?? '',
            'events' => $this->operations->events($op),
            'result' => $op->resultArray(),
            'result_json' => $op->result === null ? null : \json_encode($op->resultValue(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            'metrics' => $op->op === 'system.metrics' ? OperationViews::metrics($op, $info) : null,
            'services' => $op->op === 'service.list_status' ? OperationViews::services($op) : null,
            'can_cancel' => $op->isLive() && !$op->cancelRequested && $definition !== null && $this->authorizer->allows($identity, $definition->capability),
            'cancel_url' => $this->pages->url('operations.cancel', ['id' => $op->uid]),
            'again_url' => $server !== null && !$op->isLive() && $server->state === Server::ACTIVE && $definition !== null && $this->authorizer->allows($identity, $definition->capability)
                ? $this->pages->url('operations.request', ['id' => $server->uid])
                : null,
            'index_url' => $this->pages->url('operations.index'),
        ], identity: $identity, section: 'operations');
    }

    /** From a server's page: ask its agent for one operation, then go back to watch it. */
    public function store(Request $request, Identity $identity, string $id): Response
    {
        $server = $this->servers->byUid($id) ?? throw HttpException::notFound();
        $op = $request->input('op');
        $version = \filter_var($request->input('op_version'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $back = $request->input('return') === 'operation' ? 'operation' : 'server';
        $serverUrl = $this->pages->url('servers.show', ['id' => $server->uid]);

        if (!\is_string($op) || $version === false) {
            $this->pages->flash('danger', 'Choose an operation to run.');

            return new RedirectResponse($serverUrl, 303);
        }

        try {
            [$queued, $new] = $this->requests->request($identity, $server, $op, $version, new \stdClass());
        } catch (OperationException $e) {
            $this->pages->flash('danger', $e->getMessage());

            return new RedirectResponse($serverUrl, 303);
        }

        $label = $this->views->label($queued);
        $this->pages->flash($new ? 'success' : 'info', $new
            ? \sprintf('%s is queued. The agent takes it within a few seconds.', $label)
            : \sprintf('%s is already under way; this page follows that one.', $label));

        return new RedirectResponse($back === 'operation' ? $this->pages->url('operations.show', ['id' => $queued->uid]) : $serverUrl . '#operations', 303);
    }

    public function cancel(Identity $identity, string $id): Response
    {
        $op = $this->operations->byUid($id) ?? throw HttpException::notFound();

        try {
            $done = $this->requests->cancel($identity, $op);
            $this->pages->flash('success', $done === 'cancelled'
                ? 'Cancelled. No agent had taken it.'
                : 'The agent is asked to stop at its next check-in. If it finishes first, its result is kept.');
        } catch (OperationException $e) {
            $this->pages->flash('warning', $e->getMessage());
        }

        return new RedirectResponse($this->pages->url('operations.show', ['id' => $op->uid]), 303);
    }
}
