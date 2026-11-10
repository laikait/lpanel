<?php

declare(strict_types=1);

namespace App\Modules\Identity\Root;

use App\Engine\Auth\Account;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\HttpException;
use App\Engine\Http\Request;
use App\Engine\Security\RateLimiter;
use App\Engine\Security\Secret;
use App\Modules\Identity\PanelUsers;

/**
 * Signing in as root: the panel's own throttle, then the verifier.
 *
 * The throttle comes first and is the panel's, on top of authd's own lockout,
 * so a flood of guesses never reaches PAM. Failures fire `auth.failed` like
 * any other wrong password, so the audit log and the brute-force blocker see
 * them the same way.
 */
final class RootSignIn
{
    /** Per address, and for root as a whole (many addresses guessing). */
    public const LIMIT_PER_IP = '5/15m';
    public const LIMIT_TOTAL = '30/15m';

    public function __construct(
        private readonly RootPasswordVerifier $verifier,
        private readonly PanelUsers $users,
        private readonly RateLimiter $limiter,
        private readonly HookEngine $hooks,
    ) {}

    /**
     * root's Account when the password is right, null when it is wrong.
     *
     * @throws HttpException 429 while throttled or locked, 503 when nothing can check it
     */
    public function verify(Secret $password, Request $request): ?Account
    {
        $ip = $request->ip() ?? '';
        $keys = ['root-login:ip:' . $ip => self::LIMIT_PER_IP, 'root-login' => self::LIMIT_TOTAL];

        foreach ($keys as $key => $limit) {
            $standing = $this->limiter->check($key, $limit);

            if (!$standing->allowed) {
                $this->hooks->do('auth.throttled', 'root', $request);

                throw HttpException::tooManyRequests($standing->retryAfter);
            }
        }

        $check = $this->verifier->check($password, $ip);

        if ($check->result === RootCheck::UNAVAILABLE) {
            throw HttpException::serviceUnavailable(30, 'The root password cannot be checked right now.');
        }

        if ($check->result === RootCheck::LOCKED) {
            $this->hooks->do('auth.throttled', 'root', $request);

            throw HttpException::tooManyRequests($check->retryAfter);
        }

        if (!$check->ok()) {
            foreach ($keys as $key => $limit) {
                $this->limiter->attempt($key, $limit);
            }

            $this->hooks->do('auth.failed', 'root', $request);

            return null;
        }

        $this->limiter->clear('root-login:ip:' . $ip);

        return $this->users->root();
    }
}
