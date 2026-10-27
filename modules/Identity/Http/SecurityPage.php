<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Engine\Auth\Identity;
use App\Engine\Auth\Password;
use App\Engine\Auth\Totp;
use App\Engine\Auth\TwoFactor;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Security\Secret;
use App\Engine\Session\Session;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\Auth\PanelUserProvider;
use App\Modules\Identity\Users\User;
use App\Modules\Identity\Users\UserStore;
use App\Modules\Shared\Ui\Pages;

/**
 * /account/security: your password, and the optional second factor.
 *
 * Two-factor on is two steps (scan, then confirm with a code), so a secret
 * nobody scanned never locks anyone out. Off needs a current code, and a new
 * password needs the current one, so a session left open is not enough for
 * either. A new password ends your other sessions; this one carries on.
 */
final class SecurityPage
{
    public function __construct(
        private readonly Pages $pages,
        private readonly TwoFactor $twoFactor,
        private readonly UserStore $users,
        private readonly Password $passwords,
        private readonly PanelUserProvider $provider,
        private readonly AuditLog $audit,
    ) {}

    public function show(Request $request, Identity $identity): Response
    {
        return $this->page($request, $identity, []);
    }

    public function begin(Request $request, Identity $identity): Response
    {
        $user = $this->user($identity);

        if ($user->hasTwoFactor()) {
            return new RedirectResponse($this->pages->url('account.security'), 303);
        }

        $enrolment = $this->twoFactor->begin(PanelUserProvider::account($user), $user->login . '@LPanel');

        // The QR code is SVG markup TwoFactor generated from the secret; nothing in it came from a user.
        return $this->page($request, $identity, ['enrolment' => ['qr' => $enrolment->qrSvg, 'secret' => $enrolment->secret]]);
    }

    public function confirm(Request $request, Identity $identity): Response
    {
        $code = $request->input('code');
        $codes = \is_string($code) && \strlen($code) <= 16
            ? $this->twoFactor->confirm(PanelUserProvider::account($this->user($identity)), \trim($code))
            : null;

        if ($codes === null) {
            return $this->page($request, $identity, ['error' => 'That code is not right. Start again.'], 422);
        }

        // Shown once, on this response only (no-store), never stored in plain text.
        return $this->page($request, $identity, ['recovery_codes' => $codes]);
    }

    public function disable(Request $request, Identity $identity): Response
    {
        $user = $this->user($identity);
        $code = $request->input('code');
        $secret = $this->users->totpSecret($user);

        if ($secret === null || !\is_string($code) || Totp::verify($secret, \trim($code), $user->totpLastStep) === null) {
            return $this->page($request, $identity, ['error' => 'Enter a current code from your app to turn two-factor off.'], 422);
        }

        $this->twoFactor->disable(PanelUserProvider::account($user));
        $this->pages->flash('success', 'Two-factor sign-in is off.');

        return new RedirectResponse($this->pages->url('account.security'), 303);
    }

    public function password(Request $request, Identity $identity, Session $session): Response
    {
        $user = $this->user($identity);
        $current = $request->input('current_password');
        $new = $request->input('password');
        $confirm = $request->input('password_confirmation');

        if ($user->kind !== User::KIND_PANEL) {
            return $this->page($request, $identity, ['password_error' => 'Root\'s password is the server\'s. Change it on the server with passwd.'], 422);
        }

        if (!\is_string($current) || !$this->passwords->verify(new Secret($current), $user->passwordHash)) {
            $this->audit->record('user.password_change', 'denied', $identity, 'user', $user->uid, ['reason' => 'wrong current password']);

            return $this->page($request, $identity, ['password_error' => 'Your current password is not right.'], 422);
        }

        if (!\is_string($new) || \strlen($new) < 12 || \strlen($new) > 1024) {
            return $this->page($request, $identity, ['password_error' => 'Choose a new password of at least 12 characters.'], 422);
        }

        if ($new !== $confirm) {
            return $this->page($request, $identity, ['password_error' => 'The two new passwords are not the same.'], 422);
        }

        $this->users->setPasswordHash($user->uid, $this->passwords->hash(new Secret($new)));
        // Every session moved to the new epoch; this one is carried over, under a new id.
        $session->regenerate(followable: false);
        $this->provider->rememberEpoch($identity);
        $this->audit->record('user.password_change', 'succeeded', $identity, 'user', $user->uid, ['sessions_ended' => true]);
        $this->pages->flash('success', 'Your password has changed. Your other sessions have ended.');

        return new RedirectResponse($this->pages->url('account.security'), 303);
    }

    private function user(Identity $identity): User
    {
        return $this->users->byUid($identity->id) ?? throw new \RuntimeException('The signed-in account no longer exists.');
    }

    /** @param array<string, mixed> $data */
    private function page(Request $request, Identity $identity, array $data, int $status = 200): Response
    {
        $user = $this->user($identity);

        return $this->pages->render($request, '@Identity/security', $data + [
            'user' => $user,
            'enabled' => $user->hasTwoFactor(),
            'remaining' => \count($user->recoveryHashes),
            'error' => null,
            'password_error' => null,
            'urls_security' => [
                'begin' => $this->pages->url('account.2fa.begin'),
                'confirm' => $this->pages->url('account.2fa.confirm'),
                'disable' => $this->pages->url('account.2fa.disable'),
                'password' => $this->pages->url('account.password'),
            ],
        ], $status, $identity, 'security');
    }
}
