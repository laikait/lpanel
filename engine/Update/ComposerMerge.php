<?php

declare(strict_types=1);

namespace App\Engine\Update;

/**
 * A three-way merge of composer.json: the application's, the old release's and the new one's.
 *
 *     [$merged, $notes] = ComposerMerge::merge($local, $oldRelease, $newRelease);
 *
 * Key by key, at every depth:
 *
 * - the application left it as the old release had it  → take the new release's
 *   (a bumped constraint arrives; a package the framework dropped goes);
 * - the release left it as it was                        → keep the application's
 *   (its own packages, scripts and autoload entries stay);
 * - both changed it                                      → in require and
 *   require-dev the release wins, because the framework's code needs its
 *   constraint -- unless the release dropped the package, which the
 *   application then keeps; anywhere else the application's stays. Either way
 *   it is noted.
 *
 * Lists (a "files" autoload, a script's command list) are values, compared whole.
 */
final class ComposerMerge
{
    /** Where the release's value wins a conflict. */
    private const RELEASE_WINS = ['require', 'require-dev'];

    /**
     * @param array<string, mixed> $local
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     *
     * @return array{array<string, mixed>, list<string>} the merged composer.json and a note per conflict
     */
    public static function merge(array $local, array $old, array $new): array
    {
        $notes = [];
        $merged = self::mergeMaps($local, $old, $new, '', $notes);

        return [$merged, $notes];
    }

    /**
     * composer.json as Composer writes it: four spaces, slashes unescaped, a final newline.
     *
     * @param array<string, mixed> $composer
     */
    public static function encode(array $composer): string
    {
        return \json_encode($composer, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @param array<array-key, mixed> $local
     * @param array<array-key, mixed> $old
     * @param array<array-key, mixed> $new
     * @param list<string>            $notes
     *
     * @return array<array-key, mixed>
     */
    private static function mergeMaps(array $local, array $old, array $new, string $path, array &$notes): array
    {
        $result = [];

        // The application's order first, then what the release adds, so a
        // merge that changes one constraint does not reorder the whole file.
        foreach (\array_keys($local + $new + $old) as $key) {
            $here = $path === '' ? (string) $key : $path . '.' . $key;
            $l = \array_key_exists($key, $local) ? $local[$key] : null;
            $o = \array_key_exists($key, $old) ? $old[$key] : null;
            $n = \array_key_exists($key, $new) ? $new[$key] : null;
            $value = self::mergeValue($l, $o, $n, $here, $notes);

            if ($value !== null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** @param list<string> $notes */
    private static function mergeValue(mixed $l, mixed $o, mixed $n, string $path, array &$notes): mixed
    {
        if ($l === $o) {
            return $n;
        }

        if ($n === $o) {
            return $l;
        }

        if ($l === $n) {
            return $l;
        }

        if (self::isMap($l) && self::isMap($o ?? []) && self::isMap($n ?? [])) {
            /** @var array<array-key, mixed> $l */
            return self::mergeMaps($l, \is_array($o) ? $o : [], \is_array($n) ? $n : [], $path, $notes);
        }

        $section = \explode('.', $path)[0];

        // A package the release dropped that the application pinned itself is
        // likely one the application uses: it stays.
        if (\in_array($section, self::RELEASE_WINS, true) && $n !== null) {
            $notes[] = \sprintf('%s: the release\'s %s replaced this application\'s %s.', $path, self::show($n), self::show($l));

            return $n;
        }

        $notes[] = \sprintf('%s: kept this application\'s %s; the release has %s.', $path, self::show($l), self::show($n));

        // Including a key the application removed: it stays removed.
        return $l;
    }

    private static function isMap(mixed $value): bool
    {
        return \is_array($value) && ($value === [] || !\array_is_list($value));
    }

    private static function show(mixed $value): string
    {
        return $value === null ? 'nothing' : \json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }
}
