<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Showcase\Shared\Auth;

use App\Engine\Auth\AuthManager;
use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Security\Secret;

/**
 * Logging in, logging out, and saying who you are.
 *
 * **The framework ships no login route**, and that is not an omission. A login
 * endpoint decides what a login identifier is, what the response looks like,
 * where it redirects, whether there is a second factor and what a failure says
 * -- every one of which is the application's to answer. What the framework
 * supplies is attempt(), login() and logout(); this class is one application's
 * use of them, living in the module that owns the user.
 *
 * The rate limit on the login route is the point of the security phase meeting
 * this one: a password endpoint with no limit is an offline attack conducted
 * online, and the route declares it in one line of metadata.
 */
final class SessionEndpoints
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly Authorizer $authorizer,
    ) {}

    /**
     * Exchange a username and password for an authenticated session.
     *
     * A failure says "those credentials are not right" and does not say which
     * half was wrong. "No account with that name" is a way to find out which
     * names are registered, and the usual next step is to try them elsewhere.
     */
    public function login(Request $request): JsonResponse
    {
        $login = $request->input('username');
        $password = $request->input('password');

        if (!\is_string($login) || !\is_string($password) || $login === '' || $password === '') {
            throw HttpException::badRequest('Send a username and a password.');
        }

        // Wrapped immediately, and never unwrapped here. From this line on, the
        // plaintext cannot reach a log, a var_dump or a JSON response by
        // accident -- only password_verify() calls reveal().
        $identity = $this->auth->attempt($login, new Secret($password));

        if ($identity === null) {
            throw HttpException::unauthorized('Those credentials are not right.');
        }

        return new JsonResponse($this->describe($identity));
    }

    /** Ends the session as well as the login. See AuthManager::logout(). */
    public function logout(): JsonResponse
    {
        $this->auth->logout();

        return new JsonResponse(['authenticated' => false]);
    }

    /**
     * Who the caller is, and what they may do.
     *
     * The capability list is here because a UI needs it: a screen that renders
     * a button it will only refuse is a worse experience than one that does not
     * render it. The list is a convenience for drawing, never the check itself
     * -- the check happens on the route, where a client cannot skip it.
     */
    public function me(Identity $identity): JsonResponse
    {
        return new JsonResponse($this->describe($identity));
    }

    /** @return array<string, mixed> */
    private function describe(Identity $identity): array
    {
        return [
            'authenticated' => !$identity->isGuest(),
            'id' => $identity->id,
            'name' => $identity->name,
            'roles' => $identity->roles,
            'capabilities' => $this->authorizer->capabilitiesFor($identity),
        ];
    }
}
