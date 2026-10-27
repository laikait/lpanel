<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Engine\Auth\AuthManager;
use App\Engine\Auth\TwoFactor;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Security\Secret;
use App\Modules\Identity\Auth\LoginResult;
use App\Modules\Identity\Auth\LoginService;
use App\Modules\Shared\Ui\Pages;

/**
 * GET/POST /login, GET/POST /login/code, POST /logout.
 *
 * Every failure says the same thing: wrong login, wrong password, root from a
 * refused address, a suspended account. The reason is in the audit log.
 */
final class LoginPage
{
    private const FAILED = 'Those details are not right.';

    public function __construct(
        private readonly Pages $pages,
        private readonly LoginService $login,
        private readonly AuthManager $auth,
        private readonly TwoFactor $twoFactor,
    ) {}

    public function show(Request $request): Response
    {
        if ($this->auth->check()) {
            return new RedirectResponse($this->pages->url('home'), 303);
        }

        return $this->pages->render($request, '@Identity/login', ['error' => null, 'old' => '']);
    }

    public function submit(Request $request): Response
    {
        $login = $request->input('login');
        $password = $request->input('password');

        if (!\is_string($login) || !\is_string($password) || \trim($login) === '' || $password === ''
            || \mb_strlen($login) > 190 || \strlen($password) > 1024) {
            return $this->form($request, 'Enter your login name and password.', \is_string($login) ? $login : '', 422);
        }

        // Wrapped at once: from here the plaintext cannot reach a log or an error page.
        $result = $this->login->attempt($login, new Secret($password), $request->ip() ?? '');

        return match ($result->outcome) {
            LoginResult::LOGGED_IN => new RedirectResponse($this->pages->url('home'), 303),
            LoginResult::CHALLENGE => new RedirectResponse($this->pages->url('login.code'), 303),
            LoginResult::THROTTLED => $this->form($request, \sprintf('Too many attempts. Try again in %d minutes.', (int) \ceil($result->retryAfter / 60)), $login, 429)
                ->withHeader('Retry-After', (string) $result->retryAfter),
            default => $this->form($request, self::FAILED, $login, 422),
        };
    }

    public function showCode(Request $request): Response
    {
        if (!$this->twoFactor->pending()) {
            return new RedirectResponse($this->pages->url('login.show'), 303);
        }

        return $this->pages->render($request, '@Identity/code', ['error' => null]);
    }

    public function submitCode(Request $request): Response
    {
        $code = $request->input('code');

        if (\is_string($code) && \strlen($code) <= 64 && $this->twoFactor->challenge(\trim($code)) !== null) {
            return new RedirectResponse($this->pages->url('home'), 303);
        }

        if (!$this->twoFactor->pending()) {
            // Five wrong codes, or the five minutes ran out: the password again.
            $this->pages->flash('warning', 'Sign in again.');

            return new RedirectResponse($this->pages->url('login.show'), 303);
        }

        return $this->pages->render($request, '@Identity/code', ['error' => 'That code is not right.'], 422);
    }

    public function logout(): Response
    {
        $this->auth->logout();

        return new RedirectResponse($this->pages->url('login.show'), 303);
    }

    private function form(Request $request, string $error, string $old, int $status): Response
    {
        return $this->pages->render($request, '@Identity/login', ['error' => $error, 'old' => \mb_substr($old, 0, 190)], $status);
    }
}
