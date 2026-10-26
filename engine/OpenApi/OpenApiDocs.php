<?php

declare(strict_types=1);

namespace App\Engine\OpenApi;

use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Template\Escaper;

/**
 * GET openapi.docs: Swagger UI over the description at openapi.route.
 *
 * Swagger UI is vendored in ui/ at a pinned version, byte for byte as npm
 * published it (OpenApiDocsTest checks the hashes), with its LICENSE and
 * NOTICE beside it. It is served by these routes rather than the asset layer,
 * because the asset layer serves the application's files and the modules',
 * never the framework's own; and served from this origin rather than a CDN,
 * so the page works offline and tells no third party who reads the API.
 *
 * The page carries no inline script. docs.js starts the UI from a data-
 * attribute, so the default Content-Security-Policy (script-src 'self')
 * holds; the UI's call to validator.swagger.io is switched off there.
 *
 * Every file URL carries a version (see version()), so the files are cached
 * for a year and a framework update that changes one is fetched at once. Private
 * when openapi.meta asks for a login, so a shared cache never keeps them.
 */
final class OpenApiDocs
{
    /** The vendored swagger-ui-dist release. Change it with the files and their hashes. */
    public const SWAGGER_UI = '5.33.1';

    /** URL file name => [path under engine/OpenApi, content type]. */
    public const FILES = [
        'swagger-ui.css' => ['ui/swagger-ui.css', 'text/css'],
        'swagger-ui-bundle.js' => ['ui/swagger-ui-bundle.js', 'text/javascript'],
        'docs.js' => ['docs.js', 'text/javascript'],
    ];

    public function __construct(
        private readonly Router $router,
        /** openapi.docs, normalised: "/docs". */
        private readonly string $path,
        /** openapi.route, normalised: "/openapi.json". */
        private readonly string $spec,
        private readonly string $title,
        /** Whether openapi.meta asks for a login, which makes every response private. */
        private readonly bool $private,
    ) {}

    public function page(): Response
    {
        $escape = new Escaper();
        $base = \rtrim($this->router->basePath(), '/');
        $file = fn(string $name): string => $escape->attr($base . $this->path . '/' . $name . '?v=' . self::version($name));

        $html = '<!doctype html>' . "\n"
            . '<html lang="en">' . "\n"
            . '<head>' . "\n"
            . '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<meta name="robots" content="noindex">' . "\n"
            . '<title>' . $escape->html($this->title) . '</title>' . "\n"
            . '<link rel="stylesheet" href="' . $file('swagger-ui.css') . '">' . "\n"
            . '</head>' . "\n"
            . '<body>' . "\n"
            . '<div id="swagger-ui" data-spec="' . $escape->attr($base . $this->spec) . '"></div>' . "\n"
            . '<script src="' . $file('swagger-ui-bundle.js') . '"></script>' . "\n"
            . '<script src="' . $file('docs.js') . '"></script>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";

        return (new Response($html, 200, ['Cache-Control' => 'private, no-cache']))->withContentType('text/html');
    }

    public function css(): Response
    {
        return $this->file('swagger-ui.css');
    }

    public function bundle(): Response
    {
        return $this->file('swagger-ui-bundle.js');
    }

    public function script(): Response
    {
        return $this->file('docs.js');
    }

    /**
     * What a file's URL is versioned by, so a year-long cache is safe: the
     * release for Swagger UI's own files, and a hash of the contents for
     * docs.js, which is the framework's and changes without the release.
     */
    public static function version(string $name): string
    {
        return $name === 'docs.js'
            ? \substr((string) \hash_file('sha256', __DIR__ . '/docs.js'), 0, 12)
            : self::SWAGGER_UI;
    }

    private function file(string $name): Response
    {
        [$path, $type] = self::FILES[$name];
        $contents = (string) \file_get_contents(__DIR__ . '/' . $path);

        return (new Response($contents, 200, [
            'Cache-Control' => ($this->private ? 'private' : 'public') . ', max-age=31536000, immutable',
        ]))->withContentType($type);
    }
}
