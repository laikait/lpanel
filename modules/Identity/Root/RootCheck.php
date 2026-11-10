<?php

declare(strict_types=1);

namespace App\Modules\Identity\Root;

/** The answer to "is this the root password?" */
final class RootCheck
{
    public const ALLOWED = 'allowed';
    public const DENIED = 'denied';
    /** authd stopped answering for this address for a while. */
    public const LOCKED = 'locked';
    /** Nothing could check it: authd is not running. */
    public const UNAVAILABLE = 'unavailable';

    private function __construct(
        public readonly string $result,
        public readonly int $retryAfter = 0,
    ) {}

    public static function allowed(): self
    {
        return new self(self::ALLOWED);
    }

    public static function denied(): self
    {
        return new self(self::DENIED);
    }

    public static function locked(int $retryAfter): self
    {
        return new self(self::LOCKED, \max(1, $retryAfter));
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE);
    }

    public function ok(): bool
    {
        return $this->result === self::ALLOWED;
    }
}
