<?php

declare(strict_types=1);

namespace App\Modules\Servers\Http;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Filter\FilterEngine;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Modules\Audit\AuditLog;
use App\Modules\Servers\Agent\AgentSettings;
use App\Modules\Servers\Agent\OperationKey;
use App\Modules\Servers\Agent\PanelEndpoint;
use App\Modules\Servers\Store\Server;
use App\Modules\Servers\Store\ServerStore;
use App\Modules\Shared\Ui\Pages;

/**
 * /servers: the managed servers, adding one (an enrollment token and the
 * command that uses it, shown once), and per server its facts, keys, and the
 * two levers an administrator has over an agent: rotate its key, or revoke it.
 *
 * Other modules add cards to a server's page through the
 * `servers.detail.panels` filter: a list of
 * ['template' => '@Module/x.twig', 'column' => 'main'|'side', 'context' => [...]],
 * given the Server and the viewer's Identity.
 */
final class ServersPage
{
    public function __construct(
        private readonly Pages $pages,
        private readonly ServerStore $servers,
        private readonly AgentSettings $settings,
        private readonly PanelEndpoint $endpoint,
        private readonly OperationKey $operationKey,
        private readonly Authorizer $authorizer,
        private readonly AuditLog $audit,
        private readonly FilterEngine $filters,
    ) {}

    public function index(Request $request, Identity $identity): Response
    {
        $offline = $this->settings->offlineAfter();

        return $this->pages->render($request, '@Servers/index', [
            'rows' => \array_map(fn(Server $s): array => [
                'server' => $s,
                'status' => $s->status($offline),
                'url' => $this->pages->url('servers.show', ['id' => $s->uid]),
            ], $this->servers->all()),
            'counts' => $this->servers->counts($offline),
            'can_manage' => $this->authorizer->allows($identity, 'servers.manage'),
            'create_url' => $this->pages->url('servers.create'),
        ], identity: $identity, section: 'servers');
    }

    public function create(Request $request, Identity $identity): Response
    {
        return $this->form($request, $identity, '', null);
    }

    public function store(Request $request, Identity $identity): Response
    {
        $name = $request->input('name');
        $name = \is_string($name) ? \trim($name) : '';

        if (\preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._-]{0,99}$/u', $name) !== 1) {
            return $this->form($request, $identity, $name, 'Give the server a name of up to 100 letters, digits, spaces, ".", "_" or "-".', 422);
        }

        if ($this->endpoint->problems($this->operationKey) !== []) {
            return $this->form($request, $identity, $name, 'The panel is not ready to pair servers yet. See the steps below.', 409);
        }

        $server = $this->servers->create($name, $identity->id);
        $this->audit->record('server.create', 'succeeded', $identity, 'server', $server->uid, ['name' => $name]);

        return $this->enrollment($request, $identity, $server, 201);
    }

    public function show(Request $request, Identity $identity, string $id): Response
    {
        $server = $this->server($id);
        $offline = $this->settings->offlineAfter();
        $url = fn(string $name): string => $this->pages->url($name, ['id' => $server->uid]);

        return $this->pages->render($request, '@Servers/show', [
            'server' => $server,
            'status' => $server->status($offline),
            'credentials' => $this->servers->credentials($server),
            'outstanding' => $this->servers->outstandingEnrollment($server),
            'can_manage' => $this->authorizer->allows($identity, 'servers.manage'),
            'offline_after' => $offline,
            'panels' => self::panels($this->filters->apply('servers.detail.panels', [], $server, $identity)),
            'index_url' => $this->pages->url('servers.index'),
            'actions' => [
                'enroll' => $url('servers.enroll'),
                'rotate' => $url('servers.rotate'),
                'revoke' => $url('servers.revoke'),
            ],
        ], identity: $identity, section: 'servers');
    }

    /** A fresh token for a server with no agent, or one whose agent was revoked. */
    public function enroll(Request $request, Identity $identity, string $id): Response
    {
        $server = $this->server($id);

        if ($server->state === Server::ACTIVE) {
            $this->pages->flash('warning', 'This server already has an agent. Revoke it first to pair a new one.');

            return new RedirectResponse($this->pages->url('servers.show', ['id' => $server->uid]), 303);
        }

        if ($this->endpoint->problems($this->operationKey) !== []) {
            $this->pages->flash('danger', 'The panel is not ready to pair servers yet: ' . \implode(' ', $this->endpoint->problems($this->operationKey)));

            return new RedirectResponse($this->pages->url('servers.show', ['id' => $server->uid]), 303);
        }

        return $this->enrollment($request, $identity, $server, 201);
    }

    public function rotate(Identity $identity, string $id): Response
    {
        $server = $this->server($id);

        if ($server->state === Server::ACTIVE) {
            $this->servers->requestRotation($server);
            $this->audit->record('agent.rotation_requested', 'succeeded', $identity, 'server', $server->uid);
            $this->pages->flash('success', 'The agent will replace its key at its next heartbeat, within a minute or so.');
        }

        return new RedirectResponse($this->pages->url('servers.show', ['id' => $server->uid]), 303);
    }

    public function revoke(Identity $identity, string $id): Response
    {
        $server = $this->server($id);

        if ($server->state !== Server::REVOKED) {
            $this->servers->revoke($server);
            $this->audit->record('agent.revoke', 'succeeded', $identity, 'server', $server->uid, ['agent_id' => $server->agentId]);
            $this->pages->flash('success', 'The agent is revoked. Its next request is refused; pair it again with a new token to bring it back.');
        }

        return new RedirectResponse($this->pages->url('servers.show', ['id' => $server->uid]), 303);
    }

    /** The token and the command, on this response only (no-store); the panel keeps only a hash. */
    private function enrollment(Request $request, Identity $identity, Server $server, int $status): Response
    {
        $minutes = $this->settings->enrollmentMinutes();
        $token = $this->servers->createEnrollment($server, $identity->id, $minutes);
        $this->audit->record('agent.enrollment_create', 'succeeded', $identity, 'server', $server->uid, ['minutes' => $minutes]);

        return $this->pages->render($request, '@Servers/enroll', [
            'server' => $server,
            'command' => \sprintf('sudo lpa enroll --panel %s --pin %s --token %s', (string) $this->endpoint->url(), (string) $this->endpoint->pin(), $token),
            'pin' => $this->endpoint->pin(),
            'panel_url' => $this->endpoint->url(),
            'minutes' => $minutes,
            'show_url' => $this->pages->url('servers.show', ['id' => $server->uid]),
            'index_url' => $this->pages->url('servers.index'),
        ], $status, $identity, 'servers');
    }

    private function form(Request $request, Identity $identity, string $name, ?string $error, int $status = 200): Response
    {
        return $this->pages->render($request, '@Servers/create', [
            'name' => $name,
            'error' => $error,
            'problems' => $this->endpoint->problems($this->operationKey),
            'minutes' => $this->settings->enrollmentMinutes(),
            'store_url' => $this->pages->url('servers.store'),
            'index_url' => $this->pages->url('servers.index'),
        ], $status, $identity, 'servers');
    }

    /** @return array{main: list<array{template: string, context: array<string, mixed>}>, side: list<array{template: string, context: array<string, mixed>}>} */
    private static function panels(mixed $given): array
    {
        $panels = ['main' => [], 'side' => []];

        foreach (\is_array($given) ? $given : [] as $panel) {
            if (\is_array($panel) && \is_string($panel['template'] ?? null) && \in_array($panel['column'] ?? null, ['main', 'side'], true)) {
                $panels[$panel['column']][] = ['template' => $panel['template'], 'context' => \is_array($panel['context'] ?? null) ? $panel['context'] : []];
            }
        }

        return $panels;
    }

    private function server(string $id): Server
    {
        return $this->servers->byUid($id) ?? throw HttpException::notFound();
    }
}
