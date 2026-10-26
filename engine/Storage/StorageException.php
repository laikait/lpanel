<?php

declare(strict_types=1);

namespace App\Engine\Storage;

use App\Engine\Error\FrameworkException;

final class StorageException extends FrameworkException
{
    public static function invalidPath(string $path, string $why): self
    {
        return new self(\sprintf('"%s" is not a storage path: %s Use a relative path with forward slashes, like "invoices/0042.pdf".', $path, $why));
    }

    public static function notFound(string $disk, string $path): self
    {
        return new self(\sprintf('There is no "%s" on the %s disk.', $path, $disk));
    }

    public static function unwritable(string $disk, string $path, string $why): self
    {
        return new self(\sprintf('"%s" could not be written to the %s disk: %s', $path, $disk, $why));
    }

    public static function unreadable(string $disk, string $path, string $why): self
    {
        return new self(\sprintf('"%s" could not be read from the %s disk: %s', $path, $disk, $why));
    }

    public static function invalidContents(string $type): self
    {
        return new self(\sprintf('A file\'s contents are a string or a readable stream, not %s.', $type));
    }

    /** @param list<string> $known */
    public static function unknownDisk(string $name, array $known): self
    {
        return new self(\sprintf(
            'There is no "%s" disk. Configured: %s. Disks are listed under storage.disks in config/storage.php.',
            $name,
            $known === [] ? '(none)' : \implode(', ', $known),
        ));
    }

    public static function unknownDriver(string $disk, string $driver): self
    {
        return new self(\sprintf('The %s disk has driver "%s"; use local, s3 or memory.', $disk, $driver));
    }

    public static function misconfigured(string $disk, string $what): self
    {
        return new self(\sprintf('The %s disk is misconfigured: %s', $disk, $what));
    }

    public static function noTemporaryUrls(string $disk, string $why): self
    {
        return new self(\sprintf('The %s disk cannot make temporary URLs: %s', $disk, $why));
    }

    public static function remote(string $disk, string $operation, string $path, int $status, string $code): self
    {
        return new self(\sprintf(
            '%s of "%s" on the %s disk failed with HTTP %d%s.',
            $operation,
            $path,
            $disk,
            $status,
            $code === '' ? '' : ' (' . $code . ')',
        ));
    }
}
