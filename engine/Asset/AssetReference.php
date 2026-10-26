<?php

declare(strict_types=1);

namespace App\Engine\Asset;

/**
 * A logical asset that has been proved to exist inside a published directory.
 *
 * Nothing constructs one of these except AssetResolver, and AssetResolver only
 * returns one after every check has passed. That is the point of the type:
 * holding an AssetReference means the traversal, containment, symlink and
 * extension questions have already been answered, so no code downstream has to
 * remember to ask them again.
 *
 * The file facts are read lazily and memoised, because a URL usually needs only
 * the hash and a delivery usually needs only the size and mtime; computing all
 * three for every asset on a page would be work nobody asked for.
 */
final class AssetReference
{
    private ?int $size = null;

    private ?int $modifiedAt = null;

    private ?string $hash = null;

    /**
     * @param string $path         the logical path, e.g. "js/app.js"
     * @param string $absolutePath the resolved location on disk, normalised
     */
    public function __construct(
        public readonly AssetSource $source,
        public readonly string $path,
        public readonly string $absolutePath,
    ) {}

    public function extension(): string
    {
        return \strtolower(\pathinfo($this->path, \PATHINFO_EXTENSION));
    }

    public function contentType(): ?string
    {
        return MimeTypes::contentType($this->extension());
    }

    public function size(): int
    {
        if ($this->size === null) {
            $size = \filesize($this->absolutePath);
            $this->size = $size === false ? 0 : $size;
        }

        return $this->size;
    }

    public function modifiedAt(): int
    {
        if ($this->modifiedAt === null) {
            $time = \filemtime($this->absolutePath);
            $this->modifiedAt = $time === false ? 0 : $time;
        }

        return $this->modifiedAt;
    }

    /**
     * A short content hash.
     *
     * xxh128 rather than a cryptographic digest on purpose: this answers "is
     * this the same bytes as last time", not "did someone tamper with it", and
     * it is roughly an order of magnitude faster over file-sized inputs. Eight
     * characters is what ends up in a URL; the collision risk across the
     * handful of assets one application publishes is not a real risk.
     */
    public function hash(): string
    {
        if ($this->hash === null) {
            $hash = \hash_file('xxh128', $this->absolutePath);
            $this->hash = $hash === false ? '' : \substr($hash, 0, 8);
        }

        return $this->hash;
    }

    /** Cheap version token for when hashing every asset is not worth it. */
    public function modifiedToken(): string
    {
        return \dechex($this->modifiedAt());
    }

    public function contents(): string
    {
        $contents = \file_get_contents($this->absolutePath);

        if ($contents === false) {
            throw AssetException::unreadable($this->path, $this->source);
        }

        return $contents;
    }
}
