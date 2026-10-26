<?php

declare(strict_types=1);

namespace App\Engine\Update;

/**
 * An unpacked framework release: its directory and the manifest it shipped with.
 */
final class Release
{
    public function __construct(
        public readonly string $root,
        public readonly Manifest $manifest,
    ) {}

    /**
     * @throws UpdateException when $root holds no framework.json
     */
    public static function open(string $root): self
    {
        $root = \rtrim(\str_replace('\\', '/', $root), '/');

        if (!\is_file($root . '/' . Manifest::FILE)) {
            throw UpdateException::notARelease($root);
        }

        return new self($root, Manifest::read($root . '/' . Manifest::FILE));
    }

    public function version(): string
    {
        return $this->manifest->version;
    }

    public function path(string $relative): string
    {
        return $this->root . '/' . $relative;
    }
}
