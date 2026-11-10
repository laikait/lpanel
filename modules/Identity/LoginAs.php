<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Engine\Auth\AuthManager;
use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Http\Request;
use App\Engine\Session\SessionManager;
use App\Modules\Audit\AuditLog;

/**
 * "Log in as": root and administrators become a reseller or an account owner;
 * a reseller becomes one of their own account owners. For support, without
 * asking anybody for their password.
 *
 * - Into the Admin panel (a reseller), the session changes login and keeps
 *   who really signed in; "Return" signs that person back in.
 * - Into the User panel (an account owner), which on a server is another port
 *   and so another session cookie, the Admin panel hands over a ticket: 32
 *   random bytes, stored only as a hash, good once and for a minute.
 *
 * Either way the identity carries `impersonator` (SessionUsers), every change
 * made is recorded as on behalf of the real person, and the login's own
 * password, two-factor and API tokens cannot be changed meanwhile (routes
 * marked `no_impersonation`).
 */
final class LoginAs
{
    public const TICKET_SECONDS = 60;

    public function __construct(
        private readonly PanelUsers $users,
        private readonly AuthManager $auth,
        private readonly SessionManager $sessions,
        private readonly Authorizer $authorizer,
        private readonly ConnectionManager $connections,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Whether $actor may become the login in $target (a panel_users row).
     *
     * @param array<string, mixed> $target
     */
    public function may(Identity $actor, array $target): bool
    {
        if (!(bool) $target['active'] || (bool) ($target['suspended'] ?? false) || (string) $target['id'] === $actor->id) {
            return false;
        }

        return match (Kind::tryFrom((string) $target['kind'])) {
            Kind::Reseller => $this->authorizer->allows($actor, 'resellers.login_as'),
            Kind::User => $this->authorizer->allows($actor, 'accounts.login_as')
                && ($this->authorizer->allows($actor, 'accounts.all') || (string) $target['parent_id'] === $actor->id),
            default => false,
        };
    }

    /**
     * Become $target. Returns where to send the browser.
     *
     * @param array<string, mixed> $target checked with may() first
     */
    public function start(Identity $actor, Request $request, array $target): string
    {
        $real = self::realPerson($actor);
        $kind = Kind::from((string) $target['kind']);
        $this->audit->record('auth.login_as', $actor, $request, (string) $target['username'], AuditLog::OK, ['kind' => $kind->value]);

        if ($kind->area() === 'admin') {
            $this->become((string) $target['id'], $real);

            return $request->basePath() . '/admin';
        }

        $ticket = \bin2hex(\random_bytes(32));
        $this->tickets()->insert([
            'token_hash' => \hash('sha256', $ticket),
            'target_id' => (int) $target['id'],
            'by_id' => (int) $real['id'],
            'by_name' => $real['username'],
            'expires_at' => \gmdate('Y-m-d H:i:s', \time() + self::TICKET_SECONDS),
            'created_at' => \gmdate('Y-m-d H:i:s'),
        ]);

        return self::otherInterface($request, '/panel/login-as?ticket=' . $ticket);
    }

    /**
     * Use a ticket on the User panel. False when it is unknown, used, expired,
     * or its login can no longer sign in.
     */
    public function redeem(string $ticket): bool
    {
        if (\preg_match('/^[0-9a-f]{64}$/', $ticket) !== 1) {
            return false;
        }

        $tickets = $this->tickets()->where('token_hash', \hash('sha256', $ticket));
        $row = $tickets->first();

        // Deleted before anything else: whatever happens next, it is used.
        if ($row === null || $tickets->delete() !== 1 || (string) $row['expires_at'] <= \gmdate('Y-m-d H:i:s')) {
            return false;
        }

        $target = $this->users->byId((string) $row['target_id']);

        if ($target === null || !$target->active || Kind::tryFrom((string) $target->identity->attribute('kind'))?->area() !== 'user') {
            return false;
        }

        $this->become((string) $row['target_id'], ['id' => (string) $row['by_id'], 'username' => (string) $row['by_name']]);

        return true;
    }

    /**
     * Stop being someone else in the Admin panel: sign the real person back
     * in. Returns false when they can no longer sign in (then nobody is).
     */
    public function stop(Identity $current, Request $request): bool
    {
        $by = $this->sessions->session()->get(SessionUsers::IMPERSONATOR);
        $real = \is_array($by) && isset($by['id']) ? $this->users->byId((string) $by['id']) : null;
        $this->audit->record('auth.login_as_end', $current, $request, (string) $current->attribute('username'));

        if ($real === null || !$real->active) {
            $this->auth->logout();

            return false;
        }

        // login() fires auth.login, which forgets the impersonation.
        $this->auth->login($real->identity);

        return true;
    }

    /** Remove tickets nobody used (they expire after a minute anyway). */
    public function prune(): int
    {
        return $this->tickets()->where('expires_at', '<', \gmdate('Y-m-d H:i:s'))->delete();
    }

    /**
     * The person really signed in: the impersonator while logged in as
     * someone else, otherwise the identity itself.
     *
     * @return array{id: string, username: string}
     */
    public static function realPerson(Identity $identity): array
    {
        $id = $identity->attribute('impersonator_id');

        return \is_string($id) && $id !== ''
            ? ['id' => $id, 'username' => (string) $identity->attribute('impersonator')]
            : ['id' => $identity->id, 'username' => (string) $identity->attribute('username')];
    }

    /**
     * A URL on the other interface. On a server the Admin panel is :7443 and
     * the User panel :7444 on the same host; on a developer's machine both
     * are paths of one site.
     */
    public static function otherInterface(Request $request, string $path): string
    {
        $port = $request->port();

        if ($port === 7443 || $port === 7444) {
            return 'https://' . $request->host() . ':' . ($port === 7443 ? 7444 : 7443) . $path;
        }

        return $request->basePath() . $path;
    }

    /** @param array{id: string, username: string} $real */
    private function become(string $targetId, array $real): void
    {
        $target = $this->users->byId($targetId) ?? throw new \LogicException('login vanished');
        $this->auth->login($target->identity);
        $this->sessions->session()->set(SessionUsers::IMPERSONATOR, $real);
    }

    private function tickets(): QueryBuilder
    {
        return $this->connections->connection()->table('login_handoffs');
    }
}
