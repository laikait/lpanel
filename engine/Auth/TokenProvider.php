<?php

declare(strict_types=1);

namespace App\Engine\Auth;

/**
 * A UserProvider that can also answer for an API token.
 *
 * Separate from UserProvider on purpose. Most applications start with logins
 * and add tokens later, or never; folding byToken() into the one interface
 * would make every provider implement a method returning null, and a method
 * that always returns null is worse than one that is absent -- it looks
 * supported.
 *
 * TokenAuthenticator is registered only when the bound provider implements
 * this, so an application without tokens does not carry a listener that can
 * never succeed.
 */
interface TokenProvider extends UserProvider
{
    /**
     * The account this token belongs to, or null.
     *
     * **The token arrives raw and should never be stored raw.** A tokens table
     * holding usable credentials is a password table that skipped the last
     * thirty years: anyone who reads it can be any of those clients. Store a
     * hash and look the hash up -- a token is high-entropy, so a fast hash is
     * enough and bcrypt would only make every API request slow.
     *
     * The token is also attacker-controlled, so an implementation must not put
     * it in a log line or an error message.
     */
    public function byToken(string $token): ?Account;
}
