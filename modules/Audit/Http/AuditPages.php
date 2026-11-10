<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Session\Session;
use App\Modules\Audit\AuditLog;
use App\Modules\Panel\Ui\PanelPage;

/**
 * The audit log screens. Administrators holding audit.view see everyone's
 * entries; everybody else sees only their own.
 */
final class AuditPages
{
    public function __construct(
        private readonly AuditLog $log,
        private readonly PanelPage $pages,
        private readonly Authorizer $authorizer,
    ) {}

    public function admin(Request $request, Session $session, Identity $identity): Response
    {
        return $this->show($request, $session, $identity, 'admin');
    }

    public function user(Request $request, Session $session, Identity $identity): Response
    {
        return $this->show($request, $session, $identity, 'user');
    }

    /** @param 'admin'|'user' $area */
    private function show(Request $request, Session $session, Identity $identity, string $area): Response
    {
        $everyone = $area === 'admin' && $this->authorizer->allows($identity, 'audit.view');
        $q = $request->query('q');
        $outcome = $request->query('outcome');
        $page = $request->query('page');

        $result = $this->log->page([
            'actor' => $everyone ? null : $identity->id,
            'q' => \is_string($q) ? \mb_substr($q, 0, 100) : null,
            'outcome' => \is_string($outcome) ? $outcome : null,
        ], \is_numeric($page) ? (int) $page : 1);

        return $this->pages->render($request, $session, $identity, $area, '@Audit/log', $result + [
            'everyone' => $everyone,
            'q' => \is_string($q) ? $q : '',
            'outcome' => \is_string($outcome) ? $outcome : '',
        ], title: $everyone ? 'Audit.audit_log' : 'Audit.my_activity', active: 'audit');
    }
}
