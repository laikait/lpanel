<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

use App\Engine\Auth\TwoFactor;
use App\Engine\Auth\TwoFactorResult;
use App\Engine\Http\HttpException;
use App\Engine\Security\Secret;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\Users\UserStore;

/**
 * The one login decision, for both kinds of account.
 *
 * "root" goes to lpanel-authd and never to a password hash. Everyone else
 * goes through TwoFactor::attempt(), which is AuthManager's throttled,
 * constant-time check plus the second factor. Both end in TwoFactor, so a
 * second factor turned on applies whichever first factor was used.
 */
final class LoginService
{
    public function __construct(
        private readonly TwoFactor $twoFactor,
        private readonly UserStore $users,
        private readonly RootVerifier $root,
        private readonly AuditLog $audit,
    ) {}

    public function attempt(string $login, Secret $password, string $ip): LoginResult
    {
        $login = UserStore::normalize($login);

        if ($login === UserStore::ROOT_LOGIN) {
            return $this->root($password, $ip);
        }

        try {
            return self::fromTwoFactor($this->twoFactor->attempt($login, $password));
        } catch (HttpException $e) {
            if ($e->status() !== 429) {
                throw $e;
            }

            return LoginResult::throttled((int) ($e->headers()['Retry-After'] ?? 60));
        }
    }

    private function root(Secret $password, string $ip): LoginResult
    {
        $verdict = $this->root->verify(UserStore::ROOT_LOGIN, $password, $ip);

        if (!$verdict->ok) {
            $this->audit->record('auth.root.failed', 'denied', null, 'user', UserStore::ROOT_LOGIN, ['reason' => $verdict->code]);

            return $verdict->code === RootVerdict::LOCKED ? LoginResult::throttled($verdict->retryAfter) : LoginResult::failed();
        }

        $user = $this->users->ensureRoot();

        return self::fromTwoFactor($this->twoFactor->start(PanelUserProvider::account($user)));
    }

    private static function fromTwoFactor(TwoFactorResult $result): LoginResult
    {
        return match ($result) {
            TwoFactorResult::LoggedIn => LoginResult::loggedIn(),
            TwoFactorResult::ChallengeRequired => LoginResult::challenge(),
            TwoFactorResult::Failed => LoginResult::failed(),
        };
    }
}
