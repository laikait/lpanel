<?php

declare(strict_types=1);

namespace App\Engine\Asset;

use App\Engine\Support\Path;

/**
 * A build tool's map from the name you write to the file it produced.
 *
 *     { "js/app.js": "js/app.9c81f4a2.js",
 *       "css/app.css": { "path": "css/app.3f1c.css", "integrity": "sha384-..." } }
 *
 * Both shapes are read because both are what bundlers emit; the object form is
 * the one that can carry more later without becoming a different file format.
 *
 * Application code keeps writing asset()->core('js/app.js') whether a manifest
 * exists or not. That is the entire reason this class exists: the hashed name
 * is a deployment detail, and a deployment detail that appears in source is one
 * every developer has to remember to update.
 *
 * Loading is lazy and happens once per source per request. A missing manifest
 * is the normal case, not an error -- most applications never build assets. A
 * *malformed* one is an error, because silently ignoring it would serve stale
 * files with no indication why.
 */
final class Manifest
{
    public const FILENAME = 'manifest.json';

    /** @var array<string, string>|null null until loaded */
    private ?array $entries = null;

    public function __construct(private readonly string $file) {}

    public static function forSource(AssetSource $source): self
    {
        return new self(Path::join($source->root, self::FILENAME));
    }

    public function exists(): bool
    {
        return \is_file($this->file);
    }

    /** The built path for a logical path, or null when the manifest has no opinion. */
    public function lookup(string $path): ?string
    {
        return $this->entries()[$path] ?? null;
    }

    /**
     * Whether this path is something the manifest produced.
     *
     * Delivery asks, so that a built filename -- which carries its own hash --
     * is treated as immutable even though its URL has no ?v= on it.
     */
    public function isBuilt(string $path): bool
    {
        return \in_array($path, $this->entries(), true);
    }

    /** @return array<string, string> */
    public function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        if (!\is_file($this->file)) {
            return $this->entries = [];
        }

        $contents = \file_get_contents($this->file);

        if ($contents === false) {
            throw AssetException::unreadableManifest($this->file, 'the file could not be read');
        }

        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($contents, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw AssetException::unreadableManifest($this->file, 'it is not valid JSON (' . $e->getMessage() . ')');
        }

        if (!\is_array($decoded)) {
            throw AssetException::unreadableManifest($this->file, 'the top level is not an object');
        }

        $entries = [];

        /** @var mixed $target */
        foreach ($decoded as $logical => $target) {
            if (!\is_string($logical) || $logical === '') {
                continue;
            }

            $built = $this->readTarget($target);

            if ($built !== null) {
                $entries[$logical] = $built;
            }
        }

        return $this->entries = $entries;
    }

    /** Accept both "path" and { "path": "..." }; ignore anything else. */
    private function readTarget(mixed $target): ?string
    {
        if (\is_string($target) && $target !== '') {
            return $target;
        }

        if (\is_array($target)) {
            /** @var mixed $inner */
            $inner = $target['path'] ?? null;

            if (\is_string($inner) && $inner !== '') {
                return $inner;
            }
        }

        return null;
    }
}
