<?php

declare(strict_types=1);

namespace App\Engine\Storage;

/**
 * Somewhere files live: a directory on this machine, an S3 bucket, memory.
 *
 *     $disk = $storage->disk('uploads');
 *
 *     $disk->put('invoices/2026/0042.pdf', $pdf);
 *     $disk->get('invoices/2026/0042.pdf');
 *     $disk->temporaryUrl('invoices/2026/0042.pdf', time() + 600);
 *
 * **Paths are relative, with forward slashes**: "a/b.txt", never "/a/b.txt",
 * "../x" or "a\\b". Every disk refuses the others the same way, so a path
 * that works on one works on all of them, and no path reaches outside its disk.
 *
 * **Directories are not things.** A file's path implies them: put() creates
 * what it needs, delete() of the last file leaves nothing behind to tidy, and
 * files() lists files under a prefix. That is what object storage offers, and
 * pretending otherwise on a local disk would be the leak in the abstraction.
 *
 * Every implementation passes the same conformance suite
 * (tests/Unit/Storage/DiskConformanceTest.php).
 */
interface Disk
{
    /**
     * Write a file, replacing one that is there. Never half-written: a reader
     * sees the old contents or the new.
     *
     * @param string|resource $contents a string, or a readable stream
     *
     * @throws StorageException
     */
    public function put(string $path, mixed $contents): void;

    /** @throws StorageException when there is no such file */
    public function get(string $path): string;

    /**
     * A readable stream of the file, for one too big to hold in memory.
     *
     * @return resource
     *
     * @throws StorageException when there is no such file
     */
    public function readStream(string $path): mixed;

    public function exists(string $path): bool;

    /** False when there was nothing to delete. */
    public function delete(string $path): bool;

    /** @throws StorageException when there is no such file */
    public function size(string $path): int;

    /**
     * When the file was last written, as a Unix timestamp.
     *
     * @throws StorageException when there is no such file
     */
    public function lastModified(string $path): int;

    /**
     * Every file under $prefix, at any depth, sorted. A prefix is a
     * directory: "a" lists "a/b.txt", never "ab.txt".
     *
     * @return list<string>
     */
    public function files(string $prefix = ''): array;

    /**
     * A permanent public URL, or null when the disk has none configured. Only
     * for files meant to be public; see temporaryUrl() for the rest.
     */
    public function url(string $path): ?string;

    /**
     * A URL that works until $expiresAt and then stops: a presigned S3 URL, or
     * a signed link to the application for a local disk.
     *
     * @throws StorageException when the disk cannot make one
     */
    public function temporaryUrl(string $path, int $expiresAt): string;
}
