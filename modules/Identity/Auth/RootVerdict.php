<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

/** lpanel-authd's answer. The code goes to the audit log, never to the person logging in. */
final class RootVerdict
{
    public const DENIED = 'denied';

    public const LOCKED = 'locked';

    public const IP_NOT_ALLOWED = 'ip_not_allowed';

    public const BAD_REQUEST = 'bad_request';

    public const UNAVAILABLE = 'unavailable';

    private function __construct(
        public readonly bool $ok,
        public readonly string $code,
        public readonly int $retryAfter,
    ) {}

    public static function accepted(): self
    {
        return new self(true, '', 0);
    }

    public static function refused(string $code, int $retryAfter = 0): self
    {
        $known = [self::DENIED, self::LOCKED, self::IP_NOT_ALLOWED, self::BAD_REQUEST, self::UNAVAILABLE];

        return new self(false, \in_array($code, $known, true) ? $code : self::UNAVAILABLE, \max(0, $retryAfter));
    }
}
