<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Engine\Auth\Account;
use App\Engine\Auth\Identity;
use App\Engine\Auth\Password;
use App\Engine\Auth\Totp;
use App\Engine\Auth\TwoFactor;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Security\Secret;
use App\Engine\Session\Session;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Identity\SessionUsers;
use App\Modules\Panel\Ui\PanelPage;

/**
 * "Sign-in and security": your own password and two-factor settings.
 *
 * Turning two-factor on is two steps (show the QR code, then confirm a code
 * from the app), so a secret nobody scanned can never lock anybody out.
 * Turning it off, or making new recovery codes, needs a current code.
 */
final class SecurityPage
{
    public const MIN_PASSWORD = 12;

    /** The secret and QR code between "turn on" and "confirm", for re-showing. */
    private const ENROLMENT = 'identity.enrolment';

    public function __construct(
        private readonly PanelUsers $users,
        private readonly TwoFactor $twoFactor,
        private readonly Password $passwords,
        private readonly AuditLog $audit,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    public function show(Request $request, Session $session, Identity $identity): Response
    {
        $area = PanelPage::areaOf($request);
        return $this->page($request, $session, $identity, $area);
    }

    public function changePassword(Request $request, Session $session, Identity $identity): Response
    {
        $area = PanelPage::areaOf($request);
        $account = $this->account($identity);

        if ($this->kind($identity) === Kind::Root) {
            throw HttpException::forbidden('root\'s password is the server\'s; change it with passwd.');
        }

        $current = (string) $request->input('current_password', '');
        $new = (string) $request->input('new_password', '');
        $repeat = (string) $request->input('repeat_password', '');
        $errors = [];

        if (!$this->passwords->verify(new Secret($current), $account->passwordHash)) {
            $errors['current_password'] = 'Identity.error_current_password';
        }

        if (\mb_strlen($new) < self::MIN_PASSWORD) {
            $errors['new_password'] = 'Identity.error_short_password';
        } elseif ($new !== $repeat) {
            $errors['repeat_password'] = 'Identity.error_repeat_password';
        }

        if ($errors !== []) {
            $this->audit->record('auth.password_change', $identity, $request, '', AuditLog::FAILED);

            return $this->page($request, $session, $identity, $area, ['password_errors' => $errors], 422);
        }

        // Every other session of this login ends; this one carries on.
        $this->users->setPassword($identity->id, new Secret($new));
        $this->keepThisSession($session, $identity);
        $this->audit->record('auth.password_change', $identity, $request);
        PanelPage::notice($session, 'success', 'Identity.password_changed');

        return $this->back($area);
    }

    /** POST …/security/sessions: sign this login out everywhere but here. */
    public function signOutElsewhere(Request $request, Session $session, Identity $identity): Response
    {
        $area = PanelPage::areaOf($request);
        $this->users->signOutEverywhere([$identity->id]);
        $this->keepThisSession($session, $identity);
        $this->audit->record('auth.sign_out_elsewhere', $identity, $request);
        PanelPage::notice($session, 'success', 'Identity.signed_out_elsewhere');

        return $this->back($area);
    }

    private function keepThisSession(Session $session, Identity $identity): void
    {
        $session->set(SessionUsers::EPOCH, (int) ($this->users->row($identity->id)['session_epoch'] ?? 0));
    }

    public function beginTwoFactor(Request $request, Session $session, Identity $identity): Response
    {
        $area = PanelPage::areaOf($request);
        $account = $this->account($identity);

        if ($this->twoFactor->isEnabled($account)) {
            return $this->back($area);
        }

        $enrolment = $this->twoFactor->begin($account, (string) $identity->attribute('username'));
        $session->set(self::ENROLMENT, ['secret' => $enrolment->secret, 'qr' => $enrolment->qrSvg]);

        return $this->back($area);
    }

    public function confirmTwoFactor(Request $request, Session $session, Identity $identity): Response
    {
        $area = PanelPage::areaOf($request);
        $codes = $this->twoFactor->confirm($this->account($identity), self::code($request));

        if ($codes === null) {
            return $this->page($request, $session, $identity, $area, ['code_error' => 'Identity.error_code'], 422);
        }

        $session->forget(self::ENROLMENT);
        $this->audit->record('auth.two_factor_enabled', $identity, $request);

        // Shown once, on this response only; never stored in plain text.
        return $this->page($request, $session, $identity, $area, ['recovery_codes' => $codes]);
    }

    public function cancelTwoFactor(Request $request, Session $session): Response
    {
        $area = PanelPage::areaOf($request);
        $session->forget(self::ENROLMENT);

        return $this->back($area);
    }

    public function disableTwoFactor(Request $request, Session $session, Identity $identity): Response
    {
        $area = PanelPage::areaOf($request);
        $account = $this->account($identity);

        if (!$this->codeIsRight($account, self::code($request))) {
            $this->audit->record('auth.two_factor_disabled', $identity, $request, '', AuditLog::FAILED);

            return $this->page($request, $session, $identity, $area, ['manage_error' => 'Identity.error_code'], 422);
        }

        $this->twoFactor->disable($account);
        $this->audit->record('auth.two_factor_disabled', $identity, $request);
        PanelPage::notice($session, 'warning', 'Identity.two_factor_off');

        return $this->back($area);
    }

    public function newRecoveryCodes(Request $request, Session $session, Identity $identity): Response
    {
        $area = PanelPage::areaOf($request);
        $account = $this->account($identity);

        if (!$this->codeIsRight($account, self::code($request))) {
            return $this->page($request, $session, $identity, $area, ['manage_error' => 'Identity.error_code'], 422);
        }

        $codes = $this->twoFactor->regenerateRecoveryCodes($account);
        $this->audit->record('auth.recovery_codes_replaced', $identity, $request);

        return $this->page($request, $session, $identity, $area, ['recovery_codes' => $codes]);
    }

    /** @param array<string, mixed> $extra */
    private function page(Request $request, Session $session, Identity $identity, string $area, array $extra = [], int $status = 200): Response
    {
        $account = $this->account($identity);
        $row = $this->users->row($identity->id) ?? [];
        $enrolment = $session->get(self::ENROLMENT);
        $base = $area . '.security';

        return $this->pages->render($request, $session, $identity, $area, '@Identity/security', $extra + [
            'row' => $row,
            'is_root' => $this->kind($identity) === Kind::Root,
            'two_factor' => $this->twoFactor->isEnabled($account),
            'recovery_left' => \count($this->users->recoveryCodes($account)),
            'enrolment' => \is_array($enrolment) ? $enrolment : null,
            'min_password' => self::MIN_PASSWORD,
            'urls' => [
                'password' => $this->router->url($base . '.password'),
                'begin' => $this->router->url($base . '.2fa.begin'),
                'confirm' => $this->router->url($base . '.2fa.confirm'),
                'cancel' => $this->router->url($base . '.2fa.cancel'),
                'disable' => $this->router->url($base . '.2fa.disable'),
                'codes' => $this->router->url($base . '.2fa.codes'),
                'sessions' => $this->router->url($base . '.sessions'),
            ],
        ], title: 'Identity.security', active: 'security', status: $status);
    }

    private function codeIsRight(Account $account, string $code): bool
    {
        $secret = $this->users->twoFactorSecret($account);

        if ($secret === null || $code === '') {
            return false;
        }

        $step = Totp::verify($secret, $code, $this->users->lastTwoFactorStep($account));

        if ($step === null) {
            return false;
        }

        $this->users->recordTwoFactorStep($account, $step);

        return true;
    }

    private function account(Identity $identity): Account
    {
        return $this->users->byId($identity->id) ?? throw HttpException::unauthorized();
    }

    private function kind(Identity $identity): ?Kind
    {
        return Kind::tryFrom((string) $identity->attribute('kind'));
    }

    private function back(string $area): RedirectResponse
    {
        return new RedirectResponse($this->router->url($area . '.security'), 303);
    }

    private static function code(Request $request): string
    {
        return \preg_replace('/\s+/', '', (string) $request->input('code', '')) ?? '';
    }
}
