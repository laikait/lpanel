<?php

declare(strict_types=1);

namespace App\Engine\Auth;

use App\Engine\Http\Request;

/**
 * One way a request can say who it is.
 *
 * Two ship: a session cookie and a bearer token. They are separate classes
 * rather than branches in one, because they fail differently and an application
 * may want only one of them -- an internal tool has no tokens, a machine API
 * has no cookies, and neither should carry a listener for the other.
 *
 * **An authenticator answers or declines; it never refuses.** Returning null
 * means "I have nothing to say about this request", not "this request is
 * wrong", so the next authenticator gets a turn and a request with no
 * credentials at all ends up a guest. Refusing is AuthGuard's job, and it
 * happens once, with the route in hand.
 *
 * A credential that is present but invalid -- an expired token, a session
 * naming a deleted account -- is also null. The alternative is a 401 from
 * inside a mechanism that cannot know whether the route needed a login.
 */
interface Authenticator
{
    /** One line for about, e.g. "session cookie" or "bearer token". */
    public function describe(): string;

    public function identify(Request $request): ?Identity;
}
