<?php

declare(strict_types=1);

namespace App\Modules\Shared\Auth;

use App\Engine\Auth\Social\SocialException;
use App\Engine\Auth\Social\SocialLogin;
use App\Engine\Auth\TwoFactorResult;
use App\Engine\Config\Config;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Routing\Router;

/**
 * GET /auth/{provider} and GET|POST /auth/{provider}/callback: signing in
 * with the providers under auth.social.providers.
 *
 *     <a href="/auth/google?return=/account">Sign in with Google</a>
 *
 * A provider that is not configured is a 404, so on a fresh installation
 * these routes answer nothing. Replace them -- declare the same paths in your
 * module, which registers later -- for a different answer when somebody has
 * no account, or a page instead of a redirect.
 *
 * An account with two-factor turned on is sent to the route named by
 * auth.two_factor.route (two_factor.challenge) to type its code, and is not
 * logged in until TwoFactor::challenge() accepts one.
 */
final class SocialSignIn
{
    public function __construct(
        private readonly SocialLogin $social,
        private readonly Router $router,
        private readonly Config $config,
    ) {}

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        if (!$this->social->has($provider)) {
            throw HttpException::notFound();
        }

        $return = $request->query('return');

        return $this->social->redirect($provider, $request, \is_string($return) ? $return : null);
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        if (!$this->social->has($provider)) {
            throw HttpException::notFound();
        }

        try {
            $user = $this->social->callback($provider, $request);
        } catch (SocialException $e) {
            if ($e->isClientError()) {
                throw HttpException::badRequest($e->getMessage());
            }

            throw $e;
        }

        $result = $this->social->attempt($user);

        if ($result === TwoFactorResult::Failed) {
            throw HttpException::forbidden(\sprintf('No account here belongs to that %s account.', \ucfirst($provider)));
        }

        // Two-factor is on for the account: the provider was the first factor,
        // and the application's code form is the second.
        if ($result === TwoFactorResult::ChallengeRequired) {
            $route = $this->config->string('auth.two_factor.route', 'two_factor.challenge') ?? '';

            if ($route === '' || $this->router->route($route) === null) {
                throw HttpException::forbidden('This account has two-factor login turned on, and there is no page to enter the code on.');
            }

            return (new RedirectResponse($this->router->url($route)))->withCookie($this->social->forgetCookie());
        }

        return (new RedirectResponse($this->social->returnTo($request)))->withCookie($this->social->forgetCookie());
    }
}
