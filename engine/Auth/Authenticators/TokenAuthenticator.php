<?php

declare(strict_types=1);

namespace App\Engine\Auth\Authenticators;

use App\Engine\Auth\Authenticator;
use App\Engine\Auth\Identity;
use App\Engine\Auth\TokenProvider;
use App\Engine\Http\Request;

/**
 * The machine case: `Authorization: Bearer <token>`.
 *
 * Registered only when the application's provider implements TokenProvider, so
 * an application without tokens does not carry a listener that can never
 * succeed.
 *
 * **This is the reason an API may opt out of CSRF.** A bearer token is not an
 * ambient credential: a browser does not attach it on its own, so another site
 * cannot make a request that carries it. A session cookie is the opposite, and
 * an API authenticated by one needs CSRF exactly as much as a form does -- see
 * Security\Guard, where the exemption has to be written out by hand for that
 * reason.
 *
 * **The token never appears in a message, a log or an exception.** It is a
 * credential in plaintext; a 401 that echoed it back would put it in every
 * intermediary's error log.
 */
final class TokenAuthenticator implements Authenticator
{
    public const HEADER = 'Authorization';

    public const SCHEME = 'Bearer';

    /**
     * Tokens are high-entropy, so this is not a password hash and must not be
     * one. bcrypt on every API request would make a read endpoint slower than
     * the query behind it, and the attack a slow hash defends against --
     * guessing a low-entropy secret offline -- does not apply to 32 random
     * bytes. What is needed is that a stolen database of tokens is not a
     * database of usable credentials, and a fast hash gives that.
     */
    public const ALGORITHM = 'sha256';

    public function __construct(private readonly TokenProvider $provider) {}

    public function describe(): string
    {
        return 'bearer token';
    }

    public function identify(Request $request): ?Identity
    {
        $token = self::tokenFrom($request);

        if ($token === null) {
            return null;
        }

        $account = $this->provider->byToken($token);

        return $account !== null && $account->active ? $account->identity : null;
    }

    /**
     * The token from the header, or null.
     *
     * The scheme is matched case-insensitively because RFC 9110 says it is
     * case-insensitive, and half the clients in the world send "bearer".
     */
    public static function tokenFrom(Request $request): ?string
    {
        $header = $request->header(self::HEADER);

        if ($header === null) {
            return null;
        }

        $prefix = self::SCHEME . ' ';

        if (\strlen($header) <= \strlen($prefix) || \strcasecmp(\substr($header, 0, \strlen($prefix)), $prefix) !== 0) {
            return null;
        }

        $token = \trim(\substr($header, \strlen($prefix)));

        return $token === '' ? null : $token;
    }

    /** What a provider should store and look up, rather than the token itself. */
    public static function fingerprint(string $token): string
    {
        return \hash(self::ALGORITHM, $token);
    }
}
