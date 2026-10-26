<?php

declare(strict_types=1);

namespace App\Engine\Asset;

use App\Engine\Cache\Cache;
use App\Engine\Cache\Stores\NullStore;

/**
 * The asset API application code actually uses.
 *
 *     asset()->core('js/app.js');                 ->  /assets/core/js/app.js?v=9c81f4a2
 *     asset()->template('css/app.css');           ->  /assets/template/css/app.css?v=...
 *     asset()->template('admin', 'css/admin.css');->  /assets/template/admin/css/admin.css?v=...
 *     asset()->module('Billing', 'js/billing.js');->  /assets/module/Billing/js/billing.js?v=...
 *
 * **This class resolves; it never delivers.** It has no idea what a Request or
 * a Response is, and an architecture test keeps it that way. Delivery is
 * AssetServer, and the separation is what the specification asks for: a URL can
 * be generated in a CLI job with no HTTP anywhere, and delivery can be handed
 * to nginx tomorrow without a line of application code changing.
 *
 * What a caller writes never mentions a directory, a hash, a manifest or a
 * version. Those are deployment facts, and a deployment fact that appears in
 * application source is one that somebody has to remember to update.
 */
final class AssetManager
{
    /**
     * The one public URL prefix the asset layer owns.
     *
     * Delivery reads it from here rather than declaring its own, so the scheme
     * that generates a URL and the scheme that parses one cannot drift apart.
     */
    public const PREFIX = 'assets';

    /** @var array<string, Manifest> one per source, built on first use */
    private array $manifests = [];

    /**
     * @param string $baseUrl prefix for every generated URL: "" for a site at
     *                        the domain root, "/framework" under Apache in a
     *                        subdirectory, or a CDN origin
     * @param bool   $strict  whether a missing asset is an exception; on in
     *                        development, off in production
     */
    public function __construct(
        private readonly AssetRegistry $registry,
        private readonly AssetResolver $resolver,
        private readonly string $baseUrl = '',
        private readonly AssetVersioning $versioning = AssetVersioning::Content,
        private readonly bool $useManifests = true,
        private readonly bool $strict = false,
        /**
         * Where version tokens are kept between requests.
         *
         * A null object by default, so nothing that builds one of these without
         * a cache behaves differently from before there was one.
         */
        private readonly Cache $cache = new Cache(new NullStore()),
    ) {}

    // ---- the five published namespaces ------------------------------------

    public function core(string $path): string
    {
        return $this->url(AssetKind::Core, null, $path);
    }

    /**
     * The active template's assets, or a named template's.
     *
     * Two shapes in one method because the specification's API has both, and
     * because the one-argument form is overwhelmingly the common case: a view
     * belonging to the active template should not have to know its own name.
     *
     *     template('css/app.css')                  templates/assets/
     *     template('admin/dark', 'css/admin.css')  the "admin/dark" theme
     *
     * A theme name is trimmed of slashes at either end, as ThemeRegistry
     * trims it, so '/admin/dark/' is the same theme; '/' alone is
     * templates/assets/.
     */
    public function template(string $nameOrPath, ?string $path = null): string
    {
        if ($path === null) {
            return $this->url(AssetKind::Template, null, $nameOrPath);
        }

        $name = \trim($nameOrPath, '/');

        return $this->url(AssetKind::Template, $name === '' ? null : $name, $path);
    }

    /** A file in a module's assets/ directory: module('Billing', 'js/billing.js'). */
    public function module(string $module, string $path): string
    {
        return $this->url(AssetKind::Module, $module, $path);
    }

    // ---- resolution -------------------------------------------------------

    /**
     * The public URL for a logical asset.
     *
     * When the asset cannot be resolved, what happens depends on where you are.
     * In development it throws, naming the rule that refused it, because a
     * typo in an asset path should be loud at the moment it is written. In
     * production it returns the unversioned URL: a stale cached stylesheet is a
     * smaller problem than a page that will not render, and the request for a
     * file that is not there produces a 404 that is already visible in the
     * access log.
     */
    public function url(AssetKind $kind, ?string $name, string $path): string
    {
        $source = $this->registry->find($kind, $name);

        if ($source === null) {
            if ($this->strict) {
                throw AssetException::unknownSource($kind, $name);
            }

            return $this->compose($kind->value . ($name === null ? '' : '/' . $name), $path, null);
        }

        $built = $this->useManifests ? $this->manifest($source)->lookup($path) : null;

        try {
            $reference = $this->resolver->resolve($source, $built ?? $path);
        } catch (AssetException $e) {
            if ($this->strict) {
                throw $e;
            }

            return $this->compose($source->key(), $built ?? $path, null);
        }

        // A manifested file already carries its hash in its name, so adding
        // ?v= would version the same bytes twice and break nothing except the
        // reader's confidence.
        $token = $built !== null ? null : $this->versionToken($source, $reference);

        return $this->compose($source->key(), $reference->path, $token);
    }

    /**
     * The cache-busting token for an asset, computed at most once per deploy.
     *
     * Content hashing is the default and it is a read of the whole file. One
     * page referencing a stylesheet, a script and three images hashes five
     * files, and the next request hashes the same five again -- which is the
     * kind of cost that is invisible in development and measurable under load.
     *
     * Not cached in strict mode, which is development: a hash that outlives the
     * file it describes would mean editing a stylesheet and having the browser
     * keep the old one, and the entire point of hashing content is that this
     * cannot happen. The same flag that makes a missing asset loud turns this
     * off, because both are the same question -- is somebody editing this?
     *
     * The key carries the file's size and modification time, so a deployment
     * that forgets cache:clear still gets a fresh token for every file it
     * changed. Both are one stat, memoised on the reference, against a hash
     * that reads the whole file; and the token itself is still the hash, so a
     * copy that keeps its timestamp is only ever missed here when its size is
     * unchanged too. Without them a stale token would outlive the file, and
     * delivery marks every ?v= URL immutable for a year.
     */
    private function versionToken(AssetSource $source, AssetReference $reference): ?string
    {
        if ($this->strict || $this->versioning === AssetVersioning::None) {
            return $this->versioning->tokenFor($reference);
        }

        $key = \hash('xxh128', \implode('|', [
            $this->versioning->value,
            $source->key(),
            $reference->path,
            $reference->size(),
            $reference->modifiedAt(),
        ]));

        /** @var mixed $token */
        $token = $this->cache->remember($key, fn(): ?string => $this->versioning->tokenFor($reference));

        return \is_string($token) ? $token : null;
    }

    /**
     * The resolved file behind a logical asset.
     *
     * For the rare caller that needs the bytes rather than a URL -- inlining
     * critical CSS, checksumming a bundle in a deploy check. It is the only
     * method here that exposes a filesystem path, and it exposes one only after
     * the resolver has proved the file is inside a published directory.
     *
     * @throws AssetException always, when the asset cannot be resolved
     */
    public function locate(AssetKind $kind, ?string $name, string $path): AssetReference
    {
        $source = $this->registry->source($kind, $name);
        $built = $this->useManifests ? $this->manifest($source)->lookup($path) : null;

        return $this->resolver->resolve($source, $built ?? $path);
    }

    public function exists(AssetKind $kind, ?string $name, string $path): bool
    {
        try {
            $this->locate($kind, $name, $path);

            return true;
        } catch (AssetException) {
            return false;
        }
    }

    public function registry(): AssetRegistry
    {
        return $this->registry;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function versioning(): AssetVersioning
    {
        return $this->versioning;
    }

    public function isStrict(): bool
    {
        return $this->strict;
    }

    // ---- internals --------------------------------------------------------

    private function manifest(AssetSource $source): Manifest
    {
        return $this->manifests[$source->key()] ??= Manifest::forSource($source);
    }

    /**
     * Assemble the URL.
     *
     * Each segment is encoded individually so that a space or a plus in a
     * filename survives, while the slashes that structure the path do not get
     * encoded into uselessness.
     */
    private function compose(string $sourceKey, string $path, ?string $token): string
    {
        $segments = \array_map(
            static fn(string $segment): string => \rawurlencode($segment),
            \explode('/', \trim($path, '/')),
        );

        $url = \rtrim($this->baseUrl, '/') . '/' . self::PREFIX . '/'
            . $sourceKey . '/' . \implode('/', $segments);

        return $token === null || $token === '' ? $url : $url . '?v=' . \rawurlencode($token);
    }
}
