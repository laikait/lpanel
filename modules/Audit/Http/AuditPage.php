<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Modules\Audit\AuditLog;
use App\Modules\Shared\Ui\Pages;

/** GET /audit (audit.read): the audit log, newest first, filtered and paged by keyset. */
final class AuditPage
{
    private const PER_PAGE = 50;

    /** The filters offered, as action prefixes. */
    public const GROUPS = [
        '' => 'All activity',
        'auth.' => 'Sign-ins and security',
        'user.' => 'User changes',
        'api_credential.' => 'API tokens',
        'server.' => 'Servers',
        'agent.' => 'Agents',
        'operation.' => 'Operations',
    ];

    /** Plain words for the actions people will see most. Anything else shows its name. */
    public const LABELS = [
        'auth.login' => 'Signed in',
        'auth.logout' => 'Signed out',
        'auth.failed' => 'Sign-in failed',
        'auth.throttled' => 'Sign-in throttled',
        'auth.root.failed' => 'Root sign-in refused',
        'auth.two_factor_enabled' => 'Two-factor turned on',
        'auth.two_factor_disabled' => 'Two-factor turned off',
        'auth.two_factor_failed' => 'Wrong two-factor code',
        'auth.two_factor_throttled' => 'Two-factor throttled',
        'auth.recovery_code_used' => 'Recovery code used',
        'auth.password_reset_requested' => 'Password reset asked for',
        'auth.password_reset' => 'Password reset',
        'user.create' => 'User created',
        'user.update' => 'User changed',
        'user.suspend' => 'User suspended',
        'user.activate' => 'User reactivated',
        'user.password_set' => 'Password set by an administrator',
        'user.password_change' => 'Password changed',
        'user.reset_link' => 'Reset link sent',
        'user.two_factor_reset' => 'Two-factor reset by an administrator',
        'user.sessions_end' => 'Signed out everywhere',
        'api_credential.create' => 'API token created',
        'api_credential.revoke' => 'API token revoked',
        'server.create' => 'Server added',
        'agent.enrollment_create' => 'Pairing command made',
        'agent.enroll' => 'Agent pairing',
        'agent.rotation_requested' => 'Key rotation asked for',
        'agent.key_rotated' => 'Agent key rotated',
        'agent.revoke' => 'Agent revoked',
        'operation.request' => 'Operation asked for',
        'operation.cancel' => 'Operation cancelled',
        'operation.finish' => 'Operation finished',
        'operation.invalid_result' => 'Agent result refused',
    ];

    public function __construct(private readonly Pages $pages, private readonly AuditLog $audit) {}

    public function __invoke(Request $request, Identity $identity): Response
    {
        $group = $request->query('group');
        $group = \is_string($group) && isset(self::GROUPS[$group]) ? $group : '';
        $outcome = $request->query('outcome');
        $outcome = \is_string($outcome) && \in_array($outcome, AuditLog::OUTCOMES, true) ? $outcome : '';
        $before = \filter_var($request->query('before'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $before = \is_int($before) ? $before : null;

        $events = $this->audit->latest(self::PER_PAGE, $before, $group === '' ? null : $group, $outcome === '' ? null : $outcome);
        $filters = \array_filter(['group' => $group, 'outcome' => $outcome], static fn(string $v): bool => $v !== '');
        $last = $events === [] ? null : $events[\count($events) - 1]['id'];

        $groups = [];

        foreach (self::GROUPS as $key => $label) {
            $groups[] = [
                'label' => $label,
                'active' => $key === $group,
                'url' => $this->pages->url('audit.index', \array_filter(['group' => $key, 'outcome' => $outcome], static fn(string $v): bool => $v !== '')),
            ];
        }

        return $this->pages->render($request, '@Audit/index', [
            'events' => \array_map(self::present(...), $events),
            'groups' => $groups,
            'outcomes' => AuditLog::OUTCOMES,
            'group' => $group,
            'outcome' => $outcome,
            'paged' => $before !== null,
            'index_url' => $this->pages->url('audit.index'),
            'newest_url' => $this->pages->url('audit.index', $filters),
            'older_url' => \count($events) === self::PER_PAGE && \is_int($last) ? $this->pages->url('audit.index', $filters + ['before' => $last]) : null,
        ], identity: $identity, section: 'audit');
    }

    /**
     * An event as the page shows it: a label in plain words, and metadata as an array.
     *
     * @param array<string, mixed> $event from AuditLog::latest()
     *
     * @return array<string, mixed>
     */
    public static function present(array $event): array
    {
        $action = \is_string($event['action'] ?? null) ? $event['action'] : '';
        $event['label'] = self::LABELS[$action] ?? $action;
        $event['metadata'] = \is_array($event['metadata'] ?? null) ? $event['metadata'] : [];

        return $event;
    }
}
