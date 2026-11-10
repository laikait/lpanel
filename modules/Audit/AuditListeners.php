<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Http\Request;
use App\Engine\Routing\Router;

/**
 * What the Audit module hears from others: the framework's sign-in failures,
 * which happen before anybody is known so no handler can record them, and the
 * dashboard asking for a card.
 */
final class AuditListeners
{
    public function __construct(
        private readonly AuditLog $log,
        private readonly Router $router,
        private readonly Authorizer $authorizer,
    ) {}

    public function signInFailed(string $login, ?Request $request = null): void
    {
        $this->log->record('auth.failed', null, $request, \mb_substr($login, 0, 64), AuditLog::FAILED);
    }

    public function throttled(string $limit, ?Request $request = null): void
    {
        $this->log->record('auth.throttled', null, $request, $limit, AuditLog::DENIED);
    }

    public function codeFailed(Identity $identity): void
    {
        $this->log->record('auth.two_factor_failed', $identity, null, '', AuditLog::FAILED);
    }

    /**
     * @param list<array<string, mixed>> $cards
     *
     * @return list<array<string, mixed>>
     */
    public function dashboard(array $cards, string $area, Identity $identity): array
    {
        if (!$this->authorizer->allows($identity, 'audit.own')) {
            return $cards;
        }

        $cards[] = [
            'template' => '@Audit/card',
            'order' => 80,
            'data' => [
                'entries' => $this->log->latestFor($identity->id),
                'link' => $this->router->url($area . '.audit'),
            ],
        ];

        return $cards;
    }
}
