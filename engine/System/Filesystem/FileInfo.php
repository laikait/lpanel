<?php

declare(strict_types=1);

namespace App\Engine\System\Filesystem;

/** What a path on the server is, at the moment it was inspected. */
final class FileInfo
{
    public function __construct(
        /** The resolved real path, after symbolic links. */
        public readonly string $path,
        /** "file", "directory" or "other" (a device, a socket, a fifo). */
        public readonly string $type,
        /** Bytes; 0 for a directory. */
        public readonly int $size,
        /** Unix timestamp of the last modification. */
        public readonly int $modified,
        /** The permission bits, 0 to 0777. */
        public readonly int $permissions,
        /** Numeric owner and group; null where the platform has none (Windows). */
        public readonly ?int $owner,
        public readonly ?int $group,
    ) {}

    public function isFile(): bool
    {
        return $this->type === 'file';
    }

    public function isDirectory(): bool
    {
        return $this->type === 'directory';
    }

    /** Permissions as a string an operator reads: "0644". */
    public function mode(): string
    {
        return \sprintf('%04o', $this->permissions);
    }
}
