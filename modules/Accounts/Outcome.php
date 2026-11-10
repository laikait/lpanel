<?php

declare(strict_types=1);

namespace App\Modules\Accounts;

/**
 * What became of an account change.
 *
 * done:    the server was changed.
 * pending: the change is recorded and will be finished. Either the agent is
 *          working on it, or it could not be reached and `accounts:settle`
 *          will ask again with the same operation id.
 * failed:  the server was not changed; code and message say why.
 * busy:    another change to this account is still in flight.
 * refused: the change does not apply to the account as it is now.
 */
final class Outcome
{
    public const DONE = 'done';
    public const PENDING = 'pending';
    public const FAILED = 'failed';
    public const BUSY = 'busy';
    public const REFUSED = 'refused';

    private function __construct(
        public readonly string $status,
        public readonly ?int $accountId = null,
        public readonly ?string $code = null,
        public readonly string $message = '',
    ) {}

    public static function done(int $accountId): self
    {
        return new self(self::DONE, $accountId);
    }

    public static function pending(int $accountId, string $message = ''): self
    {
        return new self(self::PENDING, $accountId, null, $message);
    }

    public static function failed(?int $accountId, string $code, string $message): self
    {
        return new self(self::FAILED, $accountId, $code, $message);
    }

    public static function busy(int $accountId): self
    {
        return new self(self::BUSY, $accountId);
    }

    public static function refused(int $accountId, string $reason): self
    {
        return new self(self::REFUSED, $accountId, $reason);
    }

    public function ok(): bool
    {
        return $this->status === self::DONE || $this->status === self::PENDING;
    }
}
