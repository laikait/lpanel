<?php

declare(strict_types=1);

namespace App\Modules\Files;

/**
 * What one file operation came to.
 *
 *   done      the agent did it; result holds its answer
 *   refused   it was not done, and message says why in the owner's terms
 *             (a lang key, or the agent's own sentence for a refused input)
 *   failed    the agent could not do it (detail has its error)
 */
final class FileOutcome
{
    public const DONE = 'done';
    public const REFUSED = 'refused';
    public const FAILED = 'failed';

    /** @param array<string, mixed> $result */
    private function __construct(
        public readonly string $status,
        public readonly array $result = [],
        public readonly string $code = '',
        public readonly string $message = '',
        public readonly string $detail = '',
    ) {}

    /** @param array<string, mixed> $result */
    public static function done(array $result): self
    {
        return new self(self::DONE, $result);
    }

    public static function refused(string $code, string $message, string $detail = ''): self
    {
        return new self(self::REFUSED, [], $code, $message, $detail);
    }

    public static function failed(string $code, string $detail): self
    {
        return new self(self::FAILED, [], $code, 'Files.error_failed', $detail);
    }

    public function ok(): bool
    {
        return $this->status === self::DONE;
    }
}
