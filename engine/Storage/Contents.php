<?php

declare(strict_types=1);

namespace App\Engine\Storage;

/** What put() accepts, turned into what a disk needs. */
final class Contents
{
    /** @throws StorageException */
    public static function string(mixed $contents): string
    {
        if (\is_string($contents)) {
            return $contents;
        }

        $read = \stream_get_contents(self::stream($contents));

        if ($read === false) {
            throw StorageException::invalidContents('an unreadable stream');
        }

        return $read;
    }

    /**
     * @return resource
     *
     * @throws StorageException
     */
    public static function stream(mixed $contents): mixed
    {
        if (\is_string($contents)) {
            return self::fromString($contents);
        }

        if (!\is_resource($contents) || \get_resource_type($contents) !== 'stream') {
            throw StorageException::invalidContents(\get_debug_type($contents));
        }

        return $contents;
    }

    /** @return resource */
    public static function fromString(string $contents): mixed
    {
        $stream = \fopen('php://temp', 'w+b');
        \assert($stream !== false);
        \fwrite($stream, $contents);
        \rewind($stream);

        return $stream;
    }
}
