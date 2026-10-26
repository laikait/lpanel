<?php

declare(strict_types=1);

namespace App\Engine\Auth\Social;

use App\Engine\Auth\Account;

/**
 * A UserProvider that can remember which outside accounts are whose.
 *
 * Implemented on the class bound as UserProvider, over a table such as
 * (account_id, provider, provider_id) with (provider, provider_id) unique.
 * SocialLogin refuses, naming this interface, when the provider lacks it.
 */
interface SocialAccounts
{
    /** The account already linked to this provider's id, or null. */
    public function findBySocial(string $provider, string $id): ?Account;

    /** The account with this email address, or null: for linking on a verified address. */
    public function findByEmail(string $email): ?Account;

    /** Remember that $user is $account from now on. */
    public function linkSocial(Account $account, SocialUser $user): void;

    /**
     * A new account for somebody signing in for the first time, linked to
     * $user. Called only when auth.social.register is true. It has no
     * password, so password login refuses it until one is set.
     */
    public function createFromSocial(SocialUser $user): Account;
}
