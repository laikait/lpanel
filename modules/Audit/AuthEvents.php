<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Engine\Auth\Identity;
use App\Engine\Hook\HookEngine;

/**
 * Turns the framework's auth hooks into audit events, so every login, failure,
 * throttle and second-factor change is recorded however it happened.
 */
final class AuthEvents
{
    public function __construct(private readonly AuditLog $audit) {}

    public function listen(HookEngine $hooks): void
    {
        $hooks->add('request.received', [$this->audit, 'onRequest'], 0, 'Audit');
        $hooks->add('auth.login', fn(Identity $identity) => $this->audit->record('auth.login', 'succeeded', $identity, 'user', $identity->id), 10, 'Audit');
        $hooks->add('auth.logout', fn(Identity $identity) => $this->audit->record('auth.logout', 'succeeded', $identity, 'user', $identity->id), 10, 'Audit');
        $hooks->add('auth.failed', fn(string $login) => $this->audit->record('auth.failed', 'denied', null, null, null, ['login' => \mb_substr($login, 0, 100)]), 10, 'Audit');
        $hooks->add('auth.throttled', fn(string $limit) => $this->audit->record('auth.throttled', 'denied', null, null, null, ['limit' => $limit]), 10, 'Audit');

        foreach (['two_factor_enabled', 'two_factor_disabled', 'two_factor_failed', 'two_factor_throttled', 'recovery_code_used'] as $event) {
            $outcome = \in_array($event, ['two_factor_failed', 'two_factor_throttled'], true) ? 'denied' : 'succeeded';
            $hooks->add('auth.' . $event, fn(Identity $identity) => $this->audit->record('auth.' . $event, $outcome, $identity, 'user', $identity->id), 10, 'Audit');
        }
    }
}
