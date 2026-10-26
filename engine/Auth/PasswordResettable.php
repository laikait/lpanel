<?php

declare(strict_types=1);

namespace App\Engine\Auth;

/**
 * A UserProvider that can take part in "forgot your password?".
 *
 *     final class StaffProvider implements UserProvider, PasswordResettable { … }
 *
 * Optional: PasswordReset refuses, with an error that names this interface,
 * when the provider does not implement it. The framework still knows nothing
 * about where accounts live; these are the two questions it has to ask.
 */
interface PasswordResettable
{
    /** Where to mail this account, or null when it has no address. */
    public function emailFor(Account $account): ?string;

    /**
     * Store a new password hash, from Password::hash(). After this the reset
     * link that led here no longer works: it was bound to the old hash.
     */
    public function resetPassword(Account $account, string $passwordHash): void;
}
