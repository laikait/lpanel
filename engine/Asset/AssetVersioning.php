<?php

declare(strict_types=1);

namespace App\Engine\Asset;

/**
 * How an unmanifested asset gets a cache-busting token.
 *
 * A manifest always wins where one exists, because a build tool that renamed
 * app.js to app.9c81f4a2.js has already solved this. These are what happens
 * for the assets nobody built.
 */
enum AssetVersioning: string
{
    /**
     * Hash the bytes. Correct, and the default.
     *
     * The obvious objection is cost, and the obvious alternative is mtime --
     * but mtime is wrong in exactly the case that matters. Deploy tools
     * preserve timestamps (rsync -a, tar -p, a git checkout of unchanged
     * files), so a changed file can arrive with an unchanged mtime and every
     * browser keeps the old copy. A content hash cannot be wrong about whether
     * the content changed. The cost is one xxh128 per asset per request,
     * memoised on the reference, which for the handful of files a page
     * references is not measurable next to the request it is part of.
     */
    case Content = 'content';

    /** Modification time. Cheap, and right whenever the deploy rewrites files. */
    case Modified = 'modified';

    /** No token. For a CDN that versions by path, or a manifest-only build. */
    case None = 'none';

    public static function parse(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return \is_string($value) ? (self::tryFrom($value) ?? self::Content) : self::Content;
    }

    public function tokenFor(AssetReference $reference): ?string
    {
        return match ($this) {
            self::Content => $reference->hash(),
            self::Modified => $reference->modifiedToken(),
            self::None => null,
        };
    }
}
