<?php

declare(strict_types=1);

namespace App\Engine\Update;

/**
 * A release already on this machine: the zip, or the directory it unpacks to.
 *
 * For a server without internet access, and for a release downloaded and
 * checked by hand. When lphp-vX.Y.Z.zip.sha256 sits next to the zip, the zip is
 * checked against it before it is unpacked.
 */
final class ArchiveReleaseSource implements ReleaseSource
{
    private ?Release $release = null;

    public function __construct(private readonly string $path) {}

    public function latest(): string
    {
        return $this->manifestHere()->version;
    }

    public function fetch(string $version, string $workDirectory): Release
    {
        $release = $this->release ??= \is_dir($this->path)
            ? Release::open($this->path)
            : Release::open(self::unpack($this->path, $workDirectory));

        if ($release->version() !== $version) {
            throw UpdateException::versionMismatch($version, $release->version());
        }

        return $release;
    }

    public function manifest(string $version): ?Manifest
    {
        $manifest = $this->manifestHere();

        return $manifest->version === $version ? $manifest : null;
    }

    public function describe(): string
    {
        return \basename($this->path);
    }

    /**
     * Unpack a release zip and return the directory holding its framework.json:
     * the top level, or the one directory a `git archive --prefix` puts it in.
     *
     * @throws UpdateException
     */
    public static function unpack(string $zip, string $workDirectory): string
    {
        if (!\class_exists(\ZipArchive::class)) {
            throw UpdateException::noZip();
        }

        if (\is_file($zip . '.sha256')) {
            self::verify($zip, (string) \file_get_contents($zip . '.sha256'));
        }

        $archive = new \ZipArchive();

        if ($archive->open($zip) !== true) {
            throw UpdateException::notARelease($zip);
        }

        // Every name is checked before anything is written: an entry like
        // ../../public/x.php must not land outside the work directory.
        for ($i = 0; $i < $archive->numFiles; ++$i) {
            $name = (string) $archive->getNameIndex($i);
            $relative = \rtrim($name, '/');

            if ($relative !== '' && (\str_starts_with($relative, '/') || \in_array('..', \explode('/', \str_replace('\\', '/', $relative)), true))) {
                $archive->close();

                throw UpdateException::unsafePath($name);
            }
        }

        if (!\is_dir($workDirectory) && !@\mkdir($workDirectory, 0o755, true) && !\is_dir($workDirectory)) {
            throw UpdateException::unwritable($workDirectory);
        }

        if (!$archive->extractTo($workDirectory)) {
            $archive->close();

            throw UpdateException::unwritable($workDirectory);
        }

        $archive->close();

        if (\is_file($workDirectory . '/' . Manifest::FILE)) {
            return $workDirectory;
        }

        $inside = \glob($workDirectory . '/*', \GLOB_ONLYDIR) ?: [];

        if (\count($inside) === 1 && \is_file($inside[0] . '/' . Manifest::FILE)) {
            return $inside[0];
        }

        throw UpdateException::notARelease($zip);
    }

    /**
     * Check a file against `sha256sum` output: "<hex>  <name>".
     *
     * @throws UpdateException
     */
    public static function verify(string $file, string $checksum): void
    {
        $expected = \strtolower(\substr(\trim($checksum), 0, 64));

        if (\preg_match('/^[0-9a-f]{64}$/D', $expected) !== 1 || !\hash_equals($expected, (string) \hash_file('sha256', $file))) {
            throw UpdateException::checksum(\basename($file));
        }
    }

    private function manifestHere(): Manifest
    {
        if (\is_dir($this->path)) {
            return Release::open($this->path)->manifest;
        }

        if (!\class_exists(\ZipArchive::class)) {
            throw UpdateException::noZip();
        }

        $archive = new \ZipArchive();

        if ($archive->open($this->path) !== true) {
            throw UpdateException::notARelease($this->path);
        }

        for ($i = 0; $i < $archive->numFiles; ++$i) {
            $name = (string) $archive->getNameIndex($i);

            if ($name === Manifest::FILE || \preg_match('#^[^/]+/' . \preg_quote(Manifest::FILE, '#') . '$#', $name) === 1) {
                $json = (string) $archive->getFromIndex($i);
                $archive->close();

                return Manifest::fromJson($json, $this->path);
            }
        }

        $archive->close();

        throw UpdateException::notARelease($this->path);
    }
}
