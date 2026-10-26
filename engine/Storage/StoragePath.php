<?php

declare(strict_types=1);

namespace App\Engine\Storage;

/**
 * The one rule for what a storage path may be, shared by every disk.
 *
 * Refused rather than repaired: "../x" normalised to "x" would make a bug
 * that tried to escape look like a request that succeeded.
 */
final class StoragePath
{
    /** @throws StorageException */
    public static function file(string $path): string
    {
        if ($path === '') {
            throw StorageException::invalidPath($path, 'it is empty.');
        }

        return self::check($path);
    }

    /**
     * A prefix for files(): "" for everything, otherwise a path whose
     * trailing slash is optional.
     *
     * @throws StorageException
     */
    public static function prefix(string $prefix): string
    {
        $prefix = \rtrim($prefix, '/');

        return $prefix === '' ? '' : self::check($prefix);
    }

    /**
     * file(), plus what a real filesystem makes dangerous: the local disk's rule.
     *
     * Every name below is one Windows treats as something other than a file
     * of that name, so a path that passes file() can still reach somewhere it
     * does not say:
     *
     *   report.txt:hidden   an alternate data stream of report.txt
     *   report.php.         report.php -- the trailing dot is dropped
     *   report.php<space>   report.php, likewise
     *   CON, NUL, COM1...   a device, with or without an extension
     *
     * Refused on every operating system rather than only on Windows, so a path
     * that works in development cannot fail on the server, and a file stored
     * on Linux can be copied to Windows. S3 and memory keys are not files and
     * keep the shorter rule, where ":" is an ordinary character.
     *
     * @throws StorageException
     */
    public static function local(string $path): string
    {
        self::checkLocal(self::file($path));

        return $path;
    }

    /** prefix(), with local()'s rule. @throws StorageException */
    public static function localPrefix(string $prefix): string
    {
        $prefix = self::prefix($prefix);

        if ($prefix !== '') {
            self::checkLocal($prefix);
        }

        return $prefix;
    }

    private const RESERVED = '/^(?:CON|PRN|AUX|NUL|COM[0-9\x{B9}\x{B2}\x{B3}]|LPT[0-9\x{B9}\x{B2}\x{B3}])(?:\..*)?$/iu';

    private static function checkLocal(string $path): void
    {
        if (\str_contains($path, ':')) {
            throw StorageException::invalidPath($path, 'it contains ":", which names an alternate data stream on Windows.');
        }

        foreach (\explode('/', $path) as $segment) {
            if (\str_ends_with($segment, '.') || \str_ends_with($segment, ' ')) {
                throw StorageException::invalidPath($path, 'a segment ends in "." or a space, which Windows drops.');
            }

            if (\preg_match(self::RESERVED, $segment) === 1) {
                throw StorageException::invalidPath($path, \sprintf('"%s" is a device name on Windows.', $segment));
            }
        }
    }

    private static function check(string $path): string
    {
        if (\preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw StorageException::invalidPath($path, 'it contains a control character.');
        }

        if (\str_contains($path, '\\')) {
            throw StorageException::invalidPath($path, 'it contains a backslash.');
        }

        if (\str_starts_with($path, '/') || \preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw StorageException::invalidPath($path, 'it is absolute.');
        }

        foreach (\explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw StorageException::invalidPath($path, 'it has an empty, "." or ".." segment.');
            }
        }

        return $path;
    }
}
