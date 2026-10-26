<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

final class LoginResult
{
    public const LOGGED_IN = 'logged_in';

    public const CHALLENGE = 'challenge';

    public const FAILED = 'failed';

    public const THROTTLED = 'throttled';

    private function __construct(public readonly string $outcome, public readonly int $retryAfter = 0) {}

    public static function loggedIn(): self
    {
        return new self(self::LOGGED_IN);
    }

    public static function challenge(): self
    {
        return new self(self::CHALLENGE);
    }

    public static function failed(): self
    {
        return new self(self::FAILED);
    }

    public static function throttled(int $retryAfter): self
    {
        return new self(self::THROTTLED, \max(1, $retryAfter));
    }
}
