<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Http;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Modules\Audit\AuditLog;
use App\Modules\Audit\Http\AuditPage;
use App\Modules\Identity\Users\UserStore;
use App\Modules\Operations\Store\OperationStore;
use App\Modules\Servers\Agent\AgentSettings;
use App\Modules\Servers\Store\ServerStore;
use App\Modules\Shared\Ui\Pages;

/**
 * GET /: a guest is sent to /login; anyone signed in sees what they may use.
 * An administrator gets the panel's numbers and its latest activity; each
 * figure is shown only to someone allowed to read what it counts.
 */
final class Home
{
    public function __construct(
        private readonly Pages $pages,
        private readonly Authorizer $authorizer,
        private readonly UserStore $users,
        private readonly AuditLog $audit,
        private readonly ServerStore $servers,
        private readonly AgentSettings $agents,
        private readonly OperationStore $operations,
    ) {}

    public function __invoke(Request $request, Identity $identity): Response
    {
        if ($identity->isGuest()) {
            return new RedirectResponse($this->pages->url('login.show'), 303);
        }

        $user = $this->users->byUid($identity->id);
        $canUsers = $this->authorizer->allows($identity, 'users.read');
        $canAudit = $this->authorizer->allows($identity, 'audit.read');
        $canServers = $this->authorizer->allows($identity, 'servers.read');
        $day = new \DateTimeImmutable('-24 hours', new \DateTimeZone('UTC'));

        return $this->pages->render($request, '@Dashboard/home', [
            'admin' => $this->authorizer->allows($identity, 'panel.admin'),
            'customer' => $this->authorizer->allows($identity, 'panel.customer'),
            'user' => $user,
            'counts' => $canUsers ? $this->users->counts() : null,
            'servers' => $canServers ? $this->servers->counts($this->agents->offlineAfter()) : null,
            'operations' => $canServers ? $this->operations->counts($day) : null,
            'activity' => $canAudit ? [
                'events' => \array_map(AuditPage::present(...), $this->audit->latest(8)),
                'day_total' => $this->audit->countSince($day),
                'day_denied' => $this->audit->countSince($day, 'denied', 'auth.'),
            ] : null,
            'links' => \array_filter([
                'users' => $canUsers ? $this->pages->url('users.index') : null,
                'new_user' => $this->authorizer->allows($identity, 'users.manage') ? $this->pages->url('users.create') : null,
                'audit' => $canAudit ? $this->pages->url('audit.index') : null,
                'servers' => $canServers ? $this->pages->url('servers.index') : null,
                'new_server' => $this->authorizer->allows($identity, 'servers.manage') ? $this->pages->url('servers.create') : null,
                'operations' => $canServers ? $this->pages->url('operations.index') : null,
                'operations_active' => $canServers ? $this->pages->url('operations.index', ['status' => 'active']) : null,
                'operations_attention' => $canServers ? $this->pages->url('operations.index', ['status' => 'attention']) : null,
                'operations_failed' => $canServers ? $this->pages->url('operations.index', ['status' => 'failed']) : null,
                'audit_denied' => $canAudit ? $this->pages->url('audit.index', ['group' => 'auth.', 'outcome' => 'denied']) : null,
            ]),
        ], identity: $identity, section: 'dashboard');
    }
}
