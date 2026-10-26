<?php

declare(strict_types=1);

namespace App\Engine\Auth;

/**
 * What a UserProvider hands back: an identity, plus the two things the
 * framework needs in order to decide whether to hand it out.
 *
 * Separate from Identity because the hash and the active flag are answers to
 * "may this become the current user", and once it has, nothing should be able
 * to reach them. The Identity that reaches a handler carries neither.
 *
 * **The hash is a plain string, not a Secret.** Secret exists to stop a value
 * being printed by accident, and the value it protects is the one an attacker
 * could use. A bcrypt hash is already the protected form: it cannot be replayed
 * as a password, it is meant to be stored and compared, and wrapping it would
 * put reveal() in the one place that has no choice but to call it.
 */
final class Account
{
    public function __construct(
        public readonly Identity $identity,
        public readonly ?string $passwordHash = null,
        public readonly bool $active = true,
    ) {}

    /**
     * Whether this account may log in at all.
     *
     * An account with no password hash cannot: it is one that authenticates
     * some other way -- an API token, an external provider -- and letting an
     * empty hash through is how "no password set" becomes "no password
     * needed".
     */
    public function canUsePassword(): bool
    {
        return $this->active && $this->passwordHash !== null && $this->passwordHash !== '';
    }
}
