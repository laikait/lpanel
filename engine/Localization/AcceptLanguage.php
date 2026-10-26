<?php

declare(strict_types=1);

namespace App\Engine\Localization;

/**
 * The Accept-Language header, as a list of locales in preference order.
 *
 * The header is client input of any length and any shape, so the parser is
 * bounded before it is clever: the first kilobyte, the first twenty entries.
 * A real browser sends a fraction of either. Past that, anything malformed is
 * dropped rather than reported -- a bad header is the client's problem, and
 * the answer to it is "use the next thing", never an error page.
 */
final class AcceptLanguage
{
    private const MAX_LENGTH = 1024;

    private const MAX_ENTRIES = 20;

    /** @return list<string> normalized locales, highest quality first, without duplicates */
    public static function parse(?string $header): array
    {
        if ($header === null || \trim($header) === '') {
            return [];
        }

        $entries = \array_slice(\explode(',', \substr($header, 0, self::MAX_LENGTH)), 0, self::MAX_ENTRIES);
        $weighted = [];

        foreach ($entries as $position => $entry) {
            $parameters = \explode(';', $entry);
            $locale = Locale::normalize(\array_shift($parameters));
            $quality = self::quality($parameters);

            if ($locale === null || $quality === null || $quality <= 0.0) {
                continue;
            }

            $weighted[] = ['locale' => $locale, 'quality' => $quality, 'position' => $position];
        }

        // Stable: equal quality keeps the order the client wrote.
        \usort($weighted, static fn(array $a, array $b): int => [$b['quality'], $a['position']] <=> [$a['quality'], $b['position']]);

        return \array_values(\array_unique(\array_column($weighted, 'locale')));
    }

    /**
     * The q parameter, 1.0 when there is none, null when it is malformed.
     *
     * @param list<string> $parameters
     */
    private static function quality(array $parameters): ?float
    {
        foreach ($parameters as $parameter) {
            $pair = \explode('=', \trim($parameter), 2);

            if (\count($pair) !== 2 || \strtolower(\trim($pair[0])) !== 'q') {
                continue;
            }

            $value = \trim($pair[1]);

            return \preg_match('/^(?:0(?:\.[0-9]{0,3})?|1(?:\.0{0,3})?)$/', $value) === 1 ? (float) $value : null;
        }

        return 1.0;
    }
}
