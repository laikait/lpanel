<?php

declare(strict_types=1);

namespace App\Engine\Localization;

/**
 * What a locale tag may look like, and nothing else.
 *
 * A locale arrives from a cookie, a header, a country map and a caller, and
 * every one of those ends as part of a file name. So every one of them passes
 * through here first, and what comes out is one of a small, closed set of
 * shapes: a language, optionally a script, optionally a region.
 *
 *     en, bn, en-US, pt-BR, es-419, zh-Hant, zh-Hant-TW
 *
 * Anything else -- a path, a script tag, a 4 KB string -- is null, which every
 * caller treats as "not given". Normalising is what makes `EN_us` from a
 * browser and `en-US.php` on disk the same thing.
 */
final class Locale
{
    /** Longer than any tag this accepts, so an oversized value is refused before it is split. */
    private const MAX_LENGTH = 16;

    /** Codes a CDN sends when it does not know: Cloudflare's XX, and the ISO "unknown" ZZ. */
    private const UNKNOWN_COUNTRIES = ['XX', 'ZZ'];

    /** The canonical form of $tag, or null when it is not a locale. */
    public static function normalize(string $tag): ?string
    {
        // Spaces only: trim()'s default would also strip a NUL, and a value
        // carrying one is not a locale that happens to be padded.
        $tag = \trim($tag, " \t");

        if ($tag === '' || \strlen($tag) > self::MAX_LENGTH) {
            return null;
        }

        $parts = \explode('-', \str_replace('_', '-', $tag));
        $language = \array_shift($parts);

        if (\preg_match('/^[A-Za-z]{2,3}$/', $language) !== 1) {
            return null;
        }

        $normalized = [\strtolower($language)];

        if ($parts !== [] && \preg_match('/^[A-Za-z]{4}$/', $parts[0]) === 1) {
            $normalized[] = \ucfirst(\strtolower(\array_shift($parts)));
        }

        if ($parts !== []) {
            $region = \array_shift($parts);

            if (\preg_match('/^(?:[A-Za-z]{2}|[0-9]{3})$/', $region) !== 1) {
                return null;
            }

            $normalized[] = \strtoupper($region);
        }

        return $parts === [] ? \implode('-', $normalized) : null;
    }

    /**
     * $locale and every less specific form of it, most specific first.
     *
     *     zh-Hant-TW → zh-Hant-TW, zh-Hant, zh
     *
     * @return list<string>
     */
    public static function candidates(string $locale): array
    {
        $locale = self::normalize($locale);

        if ($locale === null) {
            return [];
        }

        $candidates = [];
        $parts = \explode('-', $locale);

        while ($parts !== []) {
            $candidates[] = \implode('-', $parts);
            \array_pop($parts);
        }

        return $candidates;
    }

    /** An ISO 3166 alpha-2 code in upper case, or null when $value is not one. */
    public static function country(?string $value): ?string
    {
        if ($value === null || \preg_match('/^[A-Za-z]{2}$/', \trim($value)) !== 1) {
            return null;
        }

        $country = \strtoupper(\trim($value));

        return \in_array($country, self::UNKNOWN_COUNTRIES, true) ? null : $country;
    }
}
