<?php

declare(strict_types=1);

namespace App\Engine\Support;

/**
 * Filesystem path helpers.
 *
 * The framework runs on Windows (XAMPP) and Linux (CI, production). Every path
 * the engine builds or compares goes through here so that separator handling
 * lives in exactly one place.
 */
final class Path
{
    /**
     * Convert to forward slashes, collapse repeated separators, and drop the
     * trailing separator (except for a lone "/" or a drive root like "C:/").
     */
    public static function normalize(string $path): string
    {
        $path = \str_replace('\\', '/', $path);
        $path = (string) \preg_replace('#/{2,}#', '/', $path);

        if ($path === '/' || \preg_match('#^[A-Za-z]:/$#', $path) === 1) {
            return $path;
        }

        return \rtrim($path, '/');
    }

    /**
     * Join segments with a single separator. Empty segments are skipped, so
     * join($base, '') is just the normalized base.
     */
    public static function join(string ...$segments): string
    {
        $parts = [];

        foreach ($segments as $index => $segment) {
            $segment = \str_replace('\\', '/', $segment);
            $segment = $index === 0 ? \rtrim($segment, '/') : \trim($segment, '/');

            if ($segment !== '') {
                $parts[] = $segment;
            }
        }

        return self::normalize(\implode('/', $parts));
    }

    public static function isAbsolute(string $path): bool
    {
        $path = \str_replace('\\', '/', $path);

        return \str_starts_with($path, '/') || \preg_match('#^[A-Za-z]:/#', $path) === 1;
    }

    /**
     * True when $path resolves to $root itself or to something inside it.
     *
     * Both arguments are resolved with realpath() first, so symlinks, ".." and
     * casing differences cannot be used to escape $root. A path that does not
     * exist on disk is never "within" anything.
     */
    public static function within(string $root, string $path): bool
    {
        $realRoot = \realpath($root);
        $realPath = \realpath($path);

        if ($realRoot === false || $realPath === false) {
            return false;
        }

        $realRoot = self::normalize($realRoot);
        $realPath = self::normalize($realPath);

        if (\DIRECTORY_SEPARATOR === '\\') {
            $realRoot = \strtolower($realRoot);
            $realPath = \strtolower($realPath);
        }

        return $realPath === $realRoot || \str_starts_with($realPath, $realRoot . '/');
    }

    /**
     * $path expressed relative to $base, or unchanged when it is not under it.
     *
     * Purely textual, and deliberately so: this is for printing a path to
     * somebody reading a console listing, where "modules/Billing" is
     * the useful answer and the absolute path is noise. Nothing decides access
     * from this -- within() is the one that resolves symlinks, and the one
     * containment is checked with.
     */
    public static function relativeTo(string $base, string $path): string
    {
        $base = \rtrim(self::normalize($base), '/') . '/';
        $path = self::normalize($path);

        return \str_starts_with($path, $base) ? \substr($path, \strlen($base)) : $path;
    }
}
