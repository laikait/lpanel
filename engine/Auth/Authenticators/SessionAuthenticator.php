<?php

declare(strict_types=1);

namespace App\Engine\Auth\Authenticators;

use App\Engine\Auth\Authenticator;
use App\Engine\Auth\Identity;
use App\Engine\Auth\UserProvider;
use App\Engine\Http\Request;
use App\Engine\Session\Session;
use App\Engine\Session\SessionManager;

/**
 * The browser case: a session cookie, and an account id inside the session.
 *
 * **The session holds an id, never an Identity.** Two reasons, and the second
 * is the one that matters. A serialised Identity in the session is a snapshot:
 * revoke somebody's role and they keep it until they log out, which is the
 * opposite of what revoking is for. And a suspended account would go on working
 * for as long as its session lasted -- here `active` is re-read on every
 * request, so suspending somebody takes effect on their next click.
 *
 * The cost is one provider lookup per authenticated request, which is why
 * UserProvider::byId() is the method worth making fast.
 *
 * **It does not start a session.** Phase 24's laziness would be undone by an
 * authenticator that resumed one on every request just in case; a request
 * carrying no session cookie is answered from the cookie's absence.
 */
final class SessionAuthenticator implements Authenticator
{
    /** Reserved, so application code cannot write it. See Session. */
    public const KEY = '_auth.id';

    public function __construct(
        private readonly SessionManager $sessions,
        private readonly UserProvider $provider,
    ) {}

    public function describe(): string
    {
        return 'session cookie';
    }

    public function identify(Request $request): ?Identity
    {
        if (!$this->sessions->isResumable($request)) {
            return null;
        }

        $id = $this->sessions->session()->reserved(self::KEY);

        if (!\is_string($id) || $id === '') {
            return null;
        }

        $account = $this->provider->byId($id);

        // A session naming an account that no longer exists, or one that has
        // been suspended, is not an error and not a refusal: it is a request
        // with nobody behind it. The guard decides what that costs.
        if ($account === null || !$account->active) {
            return null;
        }

        return $account->identity;
    }

    /** Called by AuthManager when somebody logs in. */
    public static function remember(Session $session, Identity $identity): void
    {
        $session->setReserved(self::KEY, $identity->id);
    }

    public static function forget(Session $session): void
    {
        $session->forgetReserved(self::KEY);
    }
}
