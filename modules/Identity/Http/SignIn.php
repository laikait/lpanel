<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Engine\Auth\Account;
use App\Engine\Auth\AuthManager;
use App\Engine\Auth\Identity;
use App\Engine\Auth\TwoFactor;
use App\Engine\Auth\TwoFactorResult;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Security\Secret;
use App\Engine\Session\Session;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Identity\Root\RootSignIn;
use App\Modules\Panel\Ui\PanelPage;

/**
 * Signing in and out of either interface.
 *
 * Each interface has its own sign-in page and accepts only its own kinds of
 * login: root, admins and resellers on the Admin panel; account owners and
 * sub-users on the User panel. A login used on the wrong page fails exactly as
 * a wrong password does, so the page does not reveal which logins exist.
 *
 * root's password is checked by authd (RootSignIn); everybody else's by the
 * framework against panel_users. Both then go through the same two-factor step.
 */
final class SignIn
{
    private const PENDING_AREA = 'identity.pending_area';
    private const NEXT = 'identity.next';

    public function __construct(
        private readonly AuthManager $auth,
        private readonly TwoFactor $twoFactor,
        private readonly RootSignIn $root,
        private readonly PanelUsers $users,
        private readonly AuditLog $audit,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    public function show(Request $request, Session $session): Response
    {
        $area = PanelPage::areaOf($request);
        if ($this->signedInHere($area)) {
            return $this->toDashboard($area);
        }

        $next = $request->query('next');

        if (\is_string($next) && self::isLocalPath($next, $area, $request)) {
            $session->set(self::NEXT, $next);
        }

        return $this->form($request, $session, $area);
    }

    public function submit(Request $request, Session $session): Response
    {
        $area = PanelPage::areaOf($request);
        $username = PanelUsers::normalise((string) $request->input('username', ''));
        $password = $request->input('password');

        if ($username === '' || !\is_string($password) || $password === '') {
            return $this->form($request, $session, $area, $username, 'Identity.error_missing', 422);
        }

        $secret = new Secret($password);
        $account = $area === 'admin' && $username === 'root'
            ? $this->root->verify($secret, $request)
            : ($username === 'root' ? null : $this->auth->verify($username, $secret));

        if ($account !== null && $this->kind($account)?->area() !== $area) {
            // Right password, wrong door: refused like a wrong password.
            $this->audit->record('auth.wrong_area', $account->identity, $request, $area, AuditLog::DENIED);
            $account = null;
        }

        if ($account === null) {
            return $this->form($request, $session, $area, $username, 'Identity.error_credentials', 422);
        }

        return match ($this->twoFactor->start($account)) {
            TwoFactorResult::LoggedIn => $this->signedIn($request, $session, $account->identity, $area, 'password'),
            TwoFactorResult::ChallengeRequired => $this->askForCode($session, $area),
            TwoFactorResult::Failed => $this->form($request, $session, $area, $username, 'Identity.error_credentials', 422),
        };
    }

    public function showCode(Request $request, Session $session): Response
    {
        $area = PanelPage::areaOf($request);
        if (!$this->twoFactor->pending() || $session->get(self::PENDING_AREA) !== $area) {
            return new RedirectResponse($this->router->url($area . '.login'), 303);
        }

        return $this->codeForm($request, $session, $area);
    }

    public function submitCode(Request $request, Session $session): Response
    {
        $area = PanelPage::areaOf($request);
        if (!$this->twoFactor->pending() || $session->get(self::PENDING_AREA) !== $area) {
            PanelPage::notice($session, 'info', 'Identity.code_expired');

            return new RedirectResponse($this->router->url($area . '.login'), 303);
        }

        $code = \preg_replace('/\s+/', '', (string) $request->input('code', '')) ?? '';
        $identity = $code === '' ? null : $this->twoFactor->challenge($code);

        if ($identity === null) {
            if (!$this->stillWaiting()) {
                PanelPage::notice($session, 'error', 'Identity.code_too_many');

                return new RedirectResponse($this->router->url($area . '.login'), 303);
            }

            return $this->codeForm($request, $session, $area, 'Identity.error_code', 422);
        }

        $session->forget(self::PENDING_AREA);

        return $this->signedIn($request, $session, $identity, $area, 'password+code');
    }

    public function logout(Request $request): Response
    {
        $area = PanelPage::areaOf($request);
        $identity = $this->auth->identity();

        if (!$identity->isGuest()) {
            $this->audit->record('auth.logout', $identity, $request);
        }

        $this->auth->logout();

        return new RedirectResponse($this->router->url($area . '.login'), 303);
    }

    private function signedIn(Request $request, Session $session, Identity $identity, string $area, string $method): Response
    {
        $this->users->signedIn($identity->id, $request->ip());
        $this->audit->record('auth.login', $identity, $request, $area, AuditLog::OK, ['method' => $method]);

        // login() gave the session a new id and kept its data, so the page the
        // person was sent away from is still there. Checked again: it is input.
        $next = $session->pull(self::NEXT);

        if (\is_string($next) && self::isLocalPath($next, $area, $request)) {
            return new RedirectResponse($next, 303);
        }

        return $this->toDashboard($area);
    }

    /**
     * Whether the code form is still open. A wrong code can close it (five
     * tries), so this is asked again after each challenge.
     *
     * @phpstan-impure
     */
    private function stillWaiting(): bool
    {
        return $this->twoFactor->pending();
    }

    private function askForCode(Session $session, string $area): Response
    {
        $session->set(self::PENDING_AREA, $area);

        return new RedirectResponse($this->router->url($area . '.login.code'), 303);
    }

    private function form(Request $request, Session $session, string $area, string $username = '', ?string $error = null, int $status = 200): Response
    {
        return $this->pages->bare($request, $session, $area, '@Identity/login', [
            'action' => $this->router->url($area . '.login'),
            'username' => $username,
            'error' => $error,
        ], title: 'Identity.sign_in', status: $status);
    }

    private function codeForm(Request $request, Session $session, string $area, ?string $error = null, int $status = 200): Response
    {
        return $this->pages->bare($request, $session, $area, '@Identity/code', [
            'action' => $this->router->url($area . '.login.code'),
            'back' => $this->router->url($area . '.login'),
            'error' => $error,
        ], title: 'Identity.two_step', status: $status);
    }

    private function signedInHere(string $area): bool
    {
        $identity = $this->auth->identity();

        return !$identity->isGuest() && Kind::tryFrom((string) $identity->attribute('kind'))?->area() === $area;
    }

    private function toDashboard(string $area): RedirectResponse
    {
        return new RedirectResponse($this->router->url($area . '.dashboard'), 303);
    }

    private function kind(Account $account): ?Kind
    {
        return Kind::tryFrom((string) $account->identity->attribute('kind'));
    }

    /** Only a path inside this interface, never another site (an open redirect). */
    private static function isLocalPath(string $path, string $area, Request $request): bool
    {
        $prefix = $request->basePath() . ($area === 'admin' ? '/admin' : '/panel');

        return \str_starts_with($path, $prefix . '/') || $path === $prefix
            ? \preg_match('#^/[A-Za-z0-9/_.\-?=&%]*$#', $path) === 1 && !\str_contains($path, '//')
            : false;
    }
}
