<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Engine\Auth\AuthManager;
use App\Engine\Auth\Capability;
use App\Engine\Auth\Identity;
use App\Engine\Auth\TwoFactor;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Route;
use App\Engine\Routing\Router;
use App\Engine\Session\SessionManager;

/**
 * Identity's answers to other modules' questions.
 */
final class IdentityListeners
{
    public function __construct(
        private readonly Router $router,
        private readonly PanelUsers $users,
        private readonly TwoFactor $twoFactor,
        private readonly AuthManager $auth,
        private readonly SessionManager $sessions,
    ) {}

    /**
     * Each login uses only its own interface, whatever its roles say: root's
     * "*" covers user.panel, but root has no hosting account to show there.
     * A filter on authorization.decision can only refuse, never grant.
     */
    public function ownInterfaceOnly(bool $allowed, Capability $capability, Identity $identity): bool
    {
        $area = match ($capability->name) {
            'admin.panel' => 'admin',
            'user.panel' => 'user',
            default => null,
        };

        return $allowed && ($area === null || Kind::tryFrom((string) $identity->attribute('kind'))?->area() === $area);
    }

    /**
     * Every protected route under /admin is for Admin panel logins and every
     * one under /panel for User panel logins, whatever capability the route
     * itself names. Runs right after the framework's own guard, so a guest
     * still gets the 401 that leads to the sign-in page.
     */
    public function guardArea(Route $route): void
    {
        $meta = $route->metadata();

        if (!isset($meta['can']) && empty($meta['auth'])) {
            return;
        }

        $path = $route->path();
        $area = match (true) {
            $path === '/admin' || \str_starts_with($path, '/admin/') => 'admin',
            $path === '/panel' || \str_starts_with($path, '/panel/') => 'user',
            default => null,
        };
        $identity = $this->auth->identity();

        if ($area !== null && !$identity->isGuest() && Kind::tryFrom((string) $identity->attribute('kind'))?->area() !== $area) {
            throw HttpException::forbidden();
        }

        // Signed in as someone else: their password, two-factor and tokens stay theirs.
        if (!empty($meta['no_impersonation']) && $identity->attribute('impersonator_id') !== null && $route->method() !== 'GET') {
            throw HttpException::forbidden('Not while signed in as someone else.');
        }
    }

    /**
     * auth.login: remember the login's session epoch in the new session, and
     * forget any earlier "log in as" (LoginAs sets it again after its own login).
     */
    public function signedIn(Identity $identity): void
    {
        $session = $this->sessions->session();
        $session->set(SessionUsers::EPOCH, (int) $identity->attribute('epoch', 0));
        $session->forget(SessionUsers::IMPERSONATOR);
    }

    /**
     * A 401 on a page of either interface sends a browser to that interface's
     * sign-in page, remembering where it was going. API calls and anything
     * other than GET keep the plain 401.
     */
    public function signInInstead(Response $response, \Throwable $error, Request $request): Response
    {
        if (!$error instanceof HttpException || $error->status() !== 401 || $request->method() !== 'GET') {
            return $response;
        }

        if (\str_contains((string) $request->header('Accept', ''), 'application/json')) {
            return $response;
        }

        $path = \substr($request->path(), 0, 7) === '/panel/' || $request->path() === '/panel' ? 'user' : null;
        $path ??= \substr($request->path(), 0, 7) === '/admin/' || $request->path() === '/admin' ? 'admin' : null;

        if ($path === null) {
            return $response;
        }

        $next = $request->basePath() . $request->path();

        return new RedirectResponse($this->router->url($path . '.login') . '?next=' . \rawurlencode($next), 302);
    }

    /**
     * @param list<array<string, mixed>> $cards
     *
     * @return list<array<string, mixed>>
     */
    public function dashboard(array $cards, string $area, Identity $identity): array
    {
        $account = $this->users->byId($identity->id);
        $row = $this->users->row($identity->id);

        if ($account === null || $row === null) {
            return $cards;
        }

        $cards[] = [
            'template' => '@Identity/card',
            'order' => 20,
            'data' => [
                'two_factor' => $this->twoFactor->isEnabled($account),
                'last_login_at' => $row['last_login_at'],
                'last_login_ip' => $row['last_login_ip'],
                'link' => $this->router->url($area . '.security'),
            ],
        ];

        return $cards;
    }
}
