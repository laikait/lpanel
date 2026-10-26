<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Engine\Auth\Identity;
use App\Engine\Auth\Totp;
use App\Engine\Auth\TwoFactor;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Session\Session;
use App\Modules\Identity\Auth\PanelUserProvider;
use App\Modules\Identity\Users\User;
use App\Modules\Identity\Users\UserStore;

/**
 * /account/security: turn the optional second factor on or off for yourself.
 *
 * On is two steps (scan, then confirm with a code), so a secret nobody scanned
 * never locks anyone out. Off needs a current code, so a session left open is
 * not enough to remove it.
 */
final class SecurityPage
{
    public function __construct(
        private readonly Pages $pages,
        private readonly TwoFactor $twoFactor,
        private readonly UserStore $users,
    ) {}

    public function show(Request $request, Identity $identity, Session $session): Response
    {
        return $this->page($request, $identity, ['status' => $session->get('security.status')]);
    }

    public function begin(Request $request, Identity $identity): Response
    {
        $user = $this->user($identity);

        if ($user->totpSecretEncrypted !== null) {
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

    public function disable(Request $request, Identity $identity, Session $session): Response
    {
        $user = $this->user($identity);
        $code = $request->input('code');
        $secret = $this->users->totpSecret($user);

        if ($secret === null || !\is_string($code) || Totp::verify($secret, \trim($code), $user->totpLastStep) === null) {
            return $this->page($request, $identity, ['error' => 'Enter a current code from your app to turn two-factor off.'], 422);
        }

        $this->twoFactor->disable(PanelUserProvider::account($user));
        $session->flash('security.status', 'Two-factor sign-in is off.');

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
            'enabled' => $user->totpSecretEncrypted !== null,
            'remaining' => \count($user->recoveryHashes),
            'urls_security' => [
                'begin' => $this->pages->url('account.2fa.begin'),
                'confirm' => $this->pages->url('account.2fa.confirm'),
                'disable' => $this->pages->url('account.2fa.disable'),
            ],
        ], $status, $identity);
    }
}
