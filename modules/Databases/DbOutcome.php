<?php

declare(strict_types=1);

namespace App\Modules\Databases;

/**
 * What became of a database change: done (on the server now), pending
 * (recorded; db:settle finishes it), failed (the server refused it), refused
 * (the panel refused it before anything changed: input or a rule; the
 * message is a language key) or skipped (the account is being made or
 * removed).
 */
final class DbOutcome
{
    public const DONE = 'done';
    public const PENDING = 'pending';
    public const FAILED = 'failed';
    public const REFUSED = 'refused';
    public const SKIPPED = 'skipped';

    /** @param array<string, mixed> $result */
    private function __construct(
        public readonly string $status,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
        public readonly array $result = [],
    ) {}

    /** @param array<string, mixed> $result */
    public static function done(array $result = []): self
    {
        return new self(self::DONE, result: $result);
    }

    public static function pending(?string $why = null): self
    {
        return new self(self::PENDING, message: $why);
    }

    public static function failed(string $code, string $message): self
    {
        return new self(self::FAILED, $code, $message);
    }

    /** @param string $key a language key, such as Databases.error_name */
    public static function refused(string $key, string $field = ''): self
    {
        return new self(self::REFUSED, $field, $key);
    }

    public static function skipped(): self
    {
        return new self(self::SKIPPED);
    }

    public function ok(): bool
    {
        return $this->status === self::DONE || $this->status === self::PENDING || $this->status === self::SKIPPED;
    }
}
