<?php

declare(strict_types=1);

namespace App\Engine\Asset;

use App\Engine\Filter\FilterEngine;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Http\StreamResponse;

/**
 * Delivery: an asset URL in, an HTTP response out.
 *
 * The other half of "separate asset resolution from asset delivery". Nothing in
 * AssetManager knows this class exists; this class knows nothing about how a
 * URL was generated beyond the prefix they share. Either half can be replaced
 * without the other noticing, and in production the sensible replacement for
 * this one is the web server.
 *
 * **Why serve assets from PHP at all.** Because the specified layout puts
 * modules inside the web root and .htaccess denies the whole of modules/ -- it
 * has to, since module.php and every repository sits there. A module's
 * assets/ directory is therefore unreachable by Apache by design, and this is
 * what makes those files reachable without unlocking the directory that holds
 * the application's source. For the application's own assets/ directory, which
 * the web server can serve directly, letting it do so is faster and entirely
 * supported: the URL scheme is the same either way.
 *
 * **What this deliberately does not do.** Byte ranges. Without them, seeking
 * within a long video does not work, and the honest answer is that a video is
 * not something PHP should be streaming -- put it behind the web server or a
 * CDN. Saying "Accept-Ranges: none" states that plainly rather than letting a
 * client discover it by getting a whole file back when it asked for a slice.
 */
final class AssetServer
{
    /** A year. The immutable case only applies to URLs that carry a version. */
    public const IMMUTABLE_MAX_AGE = 31536000;

    private readonly int $maxAge;

    public function __construct(
        private readonly AssetRegistry $registry,
        private readonly AssetResolver $resolver,
        private readonly FilterEngine $filters,
        private readonly bool $debug = false,
        ?int $maxAge = null,
    ) {
        $this->maxAge = $maxAge ?? self::IMMUTABLE_MAX_AGE;
    }

    /** Whether this path belongs to the asset URL space at all. */
    public function handles(string $path): bool
    {
        return $path === '/' . AssetManager::PREFIX
            || \str_starts_with($path, '/' . AssetManager::PREFIX . '/');
    }

    /**
     * Serve, or explain why not.
     *
     * Every refusal is a 404, including the ones that were really "you tried to
     * traverse" and "that extension is not served". Distinguishing them for the
     * client would confirm to whoever is probing which of their attempts got
     * closer, and there is nothing a legitimate client does differently on one
     * than the other. In debug the reason goes in the body, because the person
     * reading it then is the developer who made the typo.
     *
     * The response passes through the asset.response filter before it is
     * returned -- a filter over a value, not a middleware pipeline. Since
     * Phase 27 only the engine's own listeners can be on it: an asset request
     * is answered before any module loads, so a module is refused when it
     * declares one (see ModuleContext::filter()). That was always the honest
     * position, because in production the web server usually delivers assets
     * without PHP. A file that needs a permission check belongs behind a route.
     */
    public function serve(Request $request, ?string $path = null): Response
    {
        $path ??= $request->path();

        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            return $this->finish($request, $path, new Response('', 405, [
                'Allow' => 'GET, HEAD',
                'Cache-Control' => 'no-store',
            ]));
        }

        try {
            $reference = $this->locate($path);
        } catch (AssetException $e) {
            return $this->finish($request, $path, $this->refuse($e->getMessage()));
        }

        return $this->finish($request, $path, $this->deliver($request, $reference));
    }

    // ---- the URL space ----------------------------------------------------

    /**
     * Parse /assets/<kind>[/<name>]/<path> and resolve it.
     *
     * The one ambiguity in the scheme is the template kind, because three
     * shapes share it, and nothing in the URL says which:
     *
     *     /assets/template/css/app.css             templates/assets/
     *     /assets/template/dark/css/app.css        templates/dark/assets/
     *     /assets/template/admin/dark/css/app.css  templates/admin/dark/assets/
     *
     * It is resolved by asking the registry, longest name first: two leading
     * segments that name a registered theme win over one, and one wins over
     * none. Registering both "admin" and "admin/dark" therefore makes
     * templates/admin/assets/dark/ unreachable, and registering a theme
     * called "css" would shadow templates/assets/css/. Both are strange
     * things to name a theme and loud things to debug.
     *
     * There is no fallback between them. A file missing from a theme is a
     * 404, not a quiet copy from templates/assets/.
     *
     * @throws AssetException
     */
    public function locate(string $path): AssetReference
    {
        $remainder = \substr($path, \strlen('/' . AssetManager::PREFIX . '/'));
        $segments = \array_values(\array_filter(\explode('/', \trim($remainder, '/')), static fn(string $s): bool => $s !== ''));

        $kind = AssetKind::tryFrom($segments[0] ?? '');

        if ($kind === null) {
            throw AssetException::unacceptablePath($path, 'it does not name one of the three asset namespaces');
        }

        \array_shift($segments);

        $name = null;

        if ($kind->requiresName()) {
            $name = \array_shift($segments);

            if ($name === null) {
                throw AssetException::unacceptablePath($path, 'it names no ' . $kind->value);
            }
        } elseif ($kind === AssetKind::Template) {
            $name = $this->themeIn($segments);
        }

        if ($segments === []) {
            throw AssetException::unacceptablePath($path, 'it names a source but no file');
        }

        return $this->resolver->resolve($this->registry->source($kind, $name), \implode('/', $segments));
    }

    /**
     * Take the theme off the front of a template path, if one is named there.
     *
     * At least one segment is always left for the file, so "dark/app.css" with
     * a theme called "dark" is that theme's app.css rather than a theme called
     * "dark/app.css".
     *
     * @param list<string> $segments shortened in place by the segments used
     */
    private function themeIn(array &$segments): ?string
    {
        if (\count($segments) > 2 && $this->registry->has(AssetKind::Template, $segments[0] . '/' . $segments[1])) {
            $name = $segments[0] . '/' . $segments[1];
            $segments = \array_slice($segments, 2);

            return $name;
        }

        if (\count($segments) > 1 && $this->registry->has(AssetKind::Template, $segments[0])) {
            return \array_shift($segments);
        }

        return null;
    }

    // ---- delivery ---------------------------------------------------------

    private function deliver(Request $request, AssetReference $reference): Response
    {
        $headers = $this->headersFor($request, $reference);
        $etag = $headers['ETag'];

        if ($this->isFresh($request, $etag, $reference->modifiedAt())) {
            // 304 carries the validators and the caching policy and nothing
            // else; a body would defeat the purpose of sending it.
            return new Response('', 304, \array_intersect_key($headers, \array_flip([
                'ETag', 'Cache-Control', 'Last-Modified',
            ])));
        }

        return new StreamResponse($this->reader($reference), 200, $headers);
    }

    /**
     * Read the file in chunks rather than returning its contents.
     *
     * A generator, so a 200 MB download costs one buffer rather than one copy
     * of the file in memory, and so the framework never has to guess whether an
     * asset is "small enough".
     *
     * @return \Closure(): void
     */
    private function reader(AssetReference $reference): \Closure
    {
        return static function () use ($reference): void {
            $handle = @\fopen($reference->absolutePath, 'rb');

            if ($handle === false) {
                throw AssetException::unreadable($reference->path, $reference->source);
            }

            try {
                while (!\feof($handle)) {
                    $chunk = \fread($handle, 65536);

                    if ($chunk === false) {
                        break;
                    }

                    echo $chunk;
                }
            } finally {
                \fclose($handle);
            }
        };
    }

    /** @return array<string, string> */
    private function headersFor(Request $request, AssetReference $reference): array
    {
        $headers = [
            'Content-Type' => $reference->contentType() ?? 'application/octet-stream',
            'Content-Length' => (string) $reference->size(),
            'Last-Modified' => \gmdate('D, d M Y H:i:s', $reference->modifiedAt()) . ' GMT',
            'ETag' => $this->etag($reference),
            'Cache-Control' => $this->cacheControl($request, $reference),
            'Accept-Ranges' => 'none',
            // Without this a browser may decide a .txt is really HTML and run
            // what is inside it, on this application's own origin.
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($reference->extension() === 'svg') {
            // An SVG is a document that can contain script. Served inside an
            // <img> that never matters; opened directly in a tab it does, and
            // it would be same-origin. This makes the file inert either way.
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
        }

        return $headers;
    }

    /**
     * Size and modification time, in the format a strong ETag needs.
     *
     * Not a content hash: the validator has to be computed on every request,
     * including the ones that will end in a 304, and hashing a file to decide
     * not to send it is the wrong way round. The content hash is what versions
     * a URL, where it is computed once and cached by the client for a year.
     */
    private function etag(AssetReference $reference): string
    {
        return '"' . \dechex($reference->size()) . '-' . \dechex($reference->modifiedAt()) . '"';
    }

    /**
     * A versioned URL is immutable; an unversioned one must be revalidated.
     *
     * "Immutable" is a promise about the URL, not the file: /app.js?v=9c81f4a2
     * will always be those bytes because different bytes get a different token.
     * /app.js makes no such promise, so it gets a year of nothing.
     */
    private function cacheControl(Request $request, AssetReference $reference): string
    {
        $versioned = \is_string($request->query('v')) && $request->query('v') !== '';

        if (!$versioned && Manifest::forSource($reference->source)->isBuilt($reference->path)) {
            $versioned = true;
        }

        return $versioned
            ? \sprintf('public, max-age=%d, immutable', $this->maxAge)
            : 'public, max-age=0, must-revalidate';
    }

    /** Whether the client already has this exact file. */
    private function isFresh(Request $request, string $etag, int $modifiedAt): bool
    {
        $matches = $request->matchesEtag($etag);

        // An If-None-Match that did not match is a definite answer, and
        // RFC 9110 says to ignore If-Modified-Since when it is present.
        if ($matches !== null) {
            return $matches;
        }

        $since = $request->header('If-Modified-Since');

        if ($since === null || $since === '') {
            return false;
        }

        $timestamp = \strtotime($since);

        return $timestamp !== false && $modifiedAt <= $timestamp;
    }

    private function refuse(string $reason): Response
    {
        return new Response(
            $this->debug ? $reason : 'Not Found',
            404,
            ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store'],
        );
    }

    private function finish(Request $request, string $path, Response $response): Response
    {
        /** @var Response $filtered */
        $filtered = $this->filters->apply('asset.response', $response, $path, $request);

        return $filtered;
    }
}
