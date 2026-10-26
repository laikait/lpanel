<?php

declare(strict_types=1);

namespace App\Engine\Auth;

/**
 * A UserProvider that records whether an account's email address is proven.
 *
 * Optional: EmailVerification refuses, with an error that names this
 * interface, when the provider does not implement it.
 */
interface EmailVerifiable
{
    /** Where to mail this account, or null when it has no address. */
    public function emailFor(Account $account): ?string;

    public function isEmailVerified(Account $account): bool;

    public function markEmailVerified(Account $account): void;
}
