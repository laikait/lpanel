<?php

declare(strict_types=1);

namespace App\Engine\Auth;

/**
 * A UserProvider that stores what two-factor login needs.
 *
 * **Store the secret encrypted** -- a model property marked #[Encrypted] does
 * it -- because whoever reads it can make codes. Recovery codes arrive here
 * already hashed.
 */
interface TwoFactorAccounts
{
    /** The TOTP secret (Base32), or null while two-factor login is off. */
    public function twoFactorSecret(Account $account): ?string;

    /** The time step of the last code used, so it cannot be used again. */
    public function lastTwoFactorStep(Account $account): ?int;

    public function recordTwoFactorStep(Account $account, int $step): void;

    /** @return list<string> the recovery codes' hashes */
    public function recoveryCodes(Account $account): array;

    /** @param list<string> $hashes the recovery codes that are left, hashed */
    public function replaceRecoveryCodes(Account $account, array $hashes): void;

    /** @param list<string> $recoveryHashes */
    public function enableTwoFactor(Account $account, string $secret, array $recoveryHashes): void;

    public function disableTwoFactor(Account $account): void;
}
