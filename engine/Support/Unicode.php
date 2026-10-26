<?php

declare(strict_types=1);

namespace App\Engine\Support;

/**
 * Text that can be spelled in more than one sequence of bytes.
 *
 * "café" arrives as é, one character, from most keyboards and as e followed by
 * a combining accent from others; Bengali য় arrives as one character or as য
 * and a nukta. Each pair looks identical and compares unequal, so a route
 * declared one way is a 404 for a visitor whose system typed it the other.
 * Normalising both sides to NFC makes them the same bytes.
 *
 * NFC rather than NFD because it is what browsers, editors and most input
 * methods already produce, so for nearly every request the normalised text is
 * the text that arrived.
 */
final class Unicode
{
    /**
     * The NFC form of $text.
     *
     * ASCII -- nearly every path -- has one form and is returned after a single
     * byte scan. Text that is not valid UTF-8 has no normal form and comes back
     * unchanged: it will not match a route written in Unicode, which is the
     * right answer for it.
     */
    public static function nfc(string $text): string
    {
        if (\mb_check_encoding($text, 'ASCII')) {
            return $text;
        }

        $normalized = \Normalizer::normalize($text, \Normalizer::FORM_C);

        return \is_string($normalized) ? $normalized : $text;
    }
}
