<?php

declare(strict_types=1);

namespace App\Engine\Asset;

/**
 * Extension to media type, and by construction the list of servable files.
 *
 * Two decisions are worth explaining, because both look like shortcuts and
 * neither is.
 *
 * **The type comes from the extension, never from the content.** Content
 * sniffing (finfo, mime_content_type) is the obvious approach and the wrong
 * one: it reports what a file looks like, and what an uploaded file looks like
 * is whatever the uploader made it look like. A .txt full of markup sniffs as
 * text/html and then executes on the application's own origin. The extension is
 * a decision somebody made when they put the file in a published directory.
 *
 * **This map IS the allow list.** There is no fallback to
 * application/octet-stream, because a fallback turns every unknown extension
 * into a delivered file and makes the question "can this serve .php?" depend on
 * a deny list being complete. It never is. Here the question is inverted: .php,
 * .phtml, .env, .ini, .sh, .sql and everything else nobody enumerated are not
 * assets, because they are not on the list.
 *
 * HTML is deliberately absent. Serving author-supplied HTML from the
 * application's own origin is stored XSS with extra steps; content that needs
 * to be a page belongs behind a route, where something decided to render it.
 */
final class MimeTypes
{
    /**
     * The complete served set.
     *
     * @var array<string, string>
     */
    private const TYPES = [
        // Stylesheets and scripts.
        'css' => 'text/css',
        'js' => 'text/javascript',
        'mjs' => 'text/javascript',
        'map' => 'application/json',

        // Data.
        'json' => 'application/json',
        'xml' => 'application/xml',
        'txt' => 'text/plain',
        'csv' => 'text/csv',

        // Images.
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'bmp' => 'image/bmp',
        'ico' => 'image/vnd.microsoft.icon',
        'svg' => 'image/svg+xml',

        // Fonts.
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'eot' => 'application/vnd.ms-fontobject',

        // Documents and media.
        'pdf' => 'application/pdf',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogv' => 'video/ogg',
        'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
        'weba' => 'audio/webm',
    ];

    /** Types that are text and therefore need a charset to be unambiguous. */
    private const TEXTUAL = [
        'text/css',
        'text/javascript',
        'text/plain',
        'text/csv',
        'application/json',
        'application/xml',
        'image/svg+xml',
    ];

    public static function isServable(string $extension): bool
    {
        return isset(self::TYPES[\strtolower($extension)]);
    }

    public static function for(string $extension): ?string
    {
        return self::TYPES[\strtolower($extension)] ?? null;
    }

    /** The Content-Type header value, with a charset when one is meaningful. */
    public static function contentType(string $extension, string $charset = 'UTF-8'): ?string
    {
        $type = self::for($extension);

        if ($type === null) {
            return null;
        }

        return \in_array($type, self::TEXTUAL, true) ? $type . '; charset=' . $charset : $type;
    }

    /** @return list<string> every served extension, sorted */
    public static function extensions(): array
    {
        $extensions = \array_keys(self::TYPES);
        \sort($extensions);

        return $extensions;
    }
}
