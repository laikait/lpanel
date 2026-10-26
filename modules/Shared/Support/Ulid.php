<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

/**
 * A ULID: 26 characters, sortable by creation time, not guessable.
 *
 * The id a resource shows outside the panel -- in URLs, the API, the audit log
 * -- so that the database's own counter, which tells a stranger how many rows
 * there are and which ones to try next, never leaves it.
 */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(?int $milliseconds = null): string
    {
        $time = $milliseconds ?? (int) \floor(\microtime(true) * 1000);
        $head = '';

        for ($i = 0; $i < 10; $i++) {
            $head = self::ALPHABET[$time % 32] . $head;
            $time = \intdiv($time, 32);
        }

        // 256 is a multiple of 32, so masking a random byte is unbiased.
        $bytes = \random_bytes(16);
        $tail = '';

        for ($i = 0; $i < 16; $i++) {
            $tail .= self::ALPHABET[\ord($bytes[$i]) & 31];
        }

        return $head . $tail;
    }

    public static function isValid(string $value): bool
    {
        return \preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) === 1;
    }
}
