<?php

declare(strict_types=1);

namespace App\Modules\Web;

/**
 * What became of a web change: done (on the server now), pending (recorded;
 * web:settle finishes it), failed (the server refused it and kept what it
 * had), or skipped (the account is being made or removed).
 */
final class WebOutcome
{
    public const DONE = 'done';
    public const PENDING = 'pending';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    private function __construct(
        public readonly string $status,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
        public readonly bool $changed = false,
    ) {}

    public static function done(bool $changed = false): self
    {
        return new self(self::DONE, changed: $changed);
    }

    public static function pending(?string $why = null): self
    {
        return new self(self::PENDING, message: $why);
    }

    public static function failed(string $code, string $message): self
    {
        return new self(self::FAILED, $code, $message);
    }

    public static function skipped(): self
    {
        return new self(self::SKIPPED);
    }
}
