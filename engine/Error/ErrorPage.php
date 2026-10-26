<?php

declare(strict_types=1);

namespace App\Engine\Error;

use App\Engine\Http\Request;
use App\Engine\Template\TemplateManager;
use App\Engine\Template\ThemeRegistry;

/**
 * An error, as a page for a person.
 *
 * Two renderings, in order of preference. An application that has a template
 * called errors/404 or errors/error gets its own page -- branded, in its own
 * layout, saying whatever the business wants a lost customer to read. An
 * application that has neither gets the built-in page below.
 *
 * The built-in one is deliberately plain and deliberately self-contained: no
 * stylesheet, no font, no asset URL. It has to work when the thing that broke
 * is the asset manager, and an error page that needs the application to be
 * healthy is an error page that vanishes exactly when it is needed.
 *
 * In debug mode, when filp/whoops is installed, the built-in page is Whoops
 * instead: source around each frame, the request, the environment. It is a
 * development tool, so it is never used for a document without a trace -- that
 * is, never outside debug mode -- and anything it fails on falls back to the
 * built-in page. Values that name a secret are masked before it sees them.
 *
 * Rendering a template can itself throw -- a typo in errors/500 is discovered
 * the day the first 500 happens -- so a failure here falls back rather than
 * propagating. An error page that throws turns a handled 404 into an unhandled
 * 500, and the original error is lost along with it.
 */
final class ErrorPage
{
    public const DIRECTORY = 'errors';

    /** Names whose values Whoops shows as asterisks: in the environment, the server array and form input. */
    private const SECRET = '/KEY|SECRET|PASS|TOKEN|DSN|AUTH|COOKIE|CREDENTIAL|PRIVATE|SESSION|SIGNATURE/i';

    public function __construct(
        private readonly ?TemplateManager $templates = null,
        /** The project root: Whoops shows paths relative to it and marks frames under it as the application's. */
        private readonly ?string $basePath = null,
        /** A Whoops editor name (phpstorm, vscode, sublime, ...) for clickable file links; null for none. */
        private readonly ?string $editor = null,
        /**
         * The environment's variable names, whose values Whoops masks. Handed
         * in rather than read, so this page still depends on nothing.
         *
         * @var list<string>
         */
        private readonly array $environment = [],
        /**
         * The theme to render in when the error came before any route chose
         * one; see themeFor(). Null leaves the page to templates/.
         */
        private readonly ?ThemeRegistry $themes = null,
    ) {}

    /**
     * The application's page when it has one, the built-in page otherwise.
     *
     * With one exception, and it is the important one: a document carrying
     * diagnostics -- which only happens in debug mode -- always gets the
     * built-in page. An application's error template is written for a visitor,
     * and it says what a visitor should read; if it won, the day somebody added
     * a branded 500 page would be the day stack traces stopped appearing, and
     * nothing about that failure would point at the template.
     *
     * The consequence is that error templates are previewed with debug off.
     * That is the right way round: a template is a production artefact, and the
     * trace is what development is for.
     */
    public function render(ErrorDocument $document, ?Request $request = null, ?\Throwable $exception = null): string
    {
        if ($document->detail('trace') !== null) {
            if ($exception === null) {
                return self::builtIn($document);
            }

            [$html, $why] = $this->whoops($exception);

            return $html ?? self::builtIn($document, $why);
        }

        return $this->fromTemplate($document, $request) ?? self::builtIn($document);
    }

    /**
     * The template names this document would use, most specific first.
     *
     * @return list<string>
     */
    public function candidates(ErrorDocument $document): array
    {
        return [self::DIRECTORY . '/' . $document->status, self::DIRECTORY . '/error'];
    }

    /**
     * The data an error template is given: the document, and where home is.
     *
     * "Home" comes from the request because a lost visitor's way back is the
     * one link an error page must get right, and under Apache in a subdirectory
     * "/" is somebody else's site. With no request -- a fatal before one was
     * known -- the template falls back to "/" itself.
     *
     * @return array<string, mixed>
     */
    private function data(ErrorDocument $document, ?Request $request): array
    {
        return $request === null
            ? ['error' => $document]
            : ['error' => $document, 'home' => $request->basePath() . '/'];
    }

    private function fromTemplate(ErrorDocument $document, ?Request $request): ?string
    {
        $templates = $this->templates;

        if ($templates === null) {
            return null;
        }

        $theme = $this->themeFor($request);

        if ($theme === null) {
            return $this->firstCandidate($templates, $document, $request);
        }

        // Borrowed for this page only. The request is over either way, but a
        // worker serving the next one must not inherit an error's theme.
        $this->themes?->use($theme);

        try {
            return $this->firstCandidate($templates, $document, $request);
        } finally {
            $this->themes?->use(null);
        }
    }

    /**
     * The theme an error page should use when nothing has chosen one yet.
     *
     * A route with meta(['theme' => ...]) has already made the theme active
     * by the time its handler or a guard throws, and that choice stands. An
     * unknown URL, a 405 or maintenance fails before any route is asked, so
     * the path is matched against templates.error_themes instead -- which is
     * what gives /admin/nonexistent the admin theme's 404.
     *
     * Never throws: a provider that fails here would turn a 404 into a 500.
     */
    private function themeFor(?Request $request): ?string
    {
        if ($this->themes === null || $request === null) {
            return null;
        }

        try {
            return $this->themes->active() === null ? $this->themes->forPath($request->path()) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function firstCandidate(TemplateManager $templates, ErrorDocument $document, ?Request $request): ?string
    {
        foreach ($this->candidates($document) as $name) {
            if (!$templates->exists($name)) {
                continue;
            }

            try {
                return $templates->render($name, $this->data($document, $request));
            } catch (\Throwable) {
                // Deliberately swallowed. Whatever went wrong in the error
                // template, the caller still needs a page, and the built-in one
                // cannot fail. The original error is the one worth reporting,
                // and it already has been.
                return null;
            }
        }

        return null;
    }

    /**
     * The Whoops page for $exception, or why there is none.
     *
     * Whoops only renders here: it is not registered as a handler, it writes
     * nothing, sends no header and never exits. ErrorHandler stays the one
     * thing that catches, reports and responds.
     *
     * The reason matters because the fallback is the built-in page, and in
     * debug that page appearing instead of Whoops otherwise looks like the
     * debug page simply not working.
     *
     * @return array{0: ?string, 1: ?string} the page, or null and the reason
     */
    private function whoops(\Throwable $exception): array
    {
        if (!\class_exists(\Whoops\Run::class)) {
            return [null, 'filp/whoops is not installed, so this is the plain debug page. Run: composer require filp/whoops'];
        }

        try {
            $handler = new \Whoops\Handler\PrettyPageHandler();
            // Tests and the built-in server's worker both run as "cli", where
            // Whoops would otherwise decline to produce HTML.
            $handler->handleUnconditionally(true);
            $handler->setPageTitle(\sprintf('%s: %s', $exception::class, $exception->getMessage()));

            if ($this->editor !== null && $this->editor !== '') {
                $handler->setEditor($this->editor);
            }

            if ($this->basePath !== null) {
                $handler->setApplicationRootPath($this->basePath);
                $handler->setApplicationPaths([
                    $this->basePath . '/modules',
                    $this->basePath . '/templates',
                    $this->basePath . '/config',
                ]);
            }

            $this->maskSecrets($handler);

            $run = new \Whoops\Run();
            $run->allowQuit(false);
            $run->writeToOutput(false);
            $run->sendHttpCode(false);
            $run->pushHandler($handler);

            $html = $run->handleException($exception);

            return \is_string($html) && $html !== ''
                ? [$html, null]
                : [null, 'Whoops produced no output for this error.'];
        } catch (\Throwable $failure) {
            // As with a broken error template: the caller still needs a page.
            return [null, \sprintf(
                'Whoops could not render this error (%s: %s at %s:%d).',
                $failure::class,
                $failure->getMessage(),
                $failure->getFile(),
                $failure->getLine(),
            )];
        }
    }

    /**
     * Hide every value that could be a credential.
     *
     * A debug page is still a page, and a debug flag left on in production is
     * the classic way it is seen by the wrong person. Every cookie and every
     * environment value is hidden -- the session cookie is a live login, and
     * the environment is where secrets live -- and so is anything in the
     * server array or the form input whose name looks like a secret: APP_KEY,
     * DB_DSN, DB_PASSWORD, HTTP_AUTHORIZATION, password.
     */
    private function maskSecrets(\Whoops\Handler\PrettyPageHandler $handler): void
    {
        foreach (\array_keys($_COOKIE) as $key) {
            $handler->hideSuperglobalKey('_COOKIE', (string) $key);
        }

        foreach ($this->environment as $key) {
            $handler->hideSuperglobalKey('_ENV', $key);
        }

        foreach (['_SERVER' => $_SERVER, '_POST' => $_POST] as $global => $values) {
            foreach (\array_keys($values) as $key) {
                if (\preg_match(self::SECRET, (string) $key) === 1) {
                    $handler->hideSuperglobalKey($global, (string) $key);
                }
            }
        }
    }

    /**
     * The page of last resort.
     *
     * Everything is escaped, including in debug mode: a stack trace routinely
     * contains a string argument that came from the request, and an error page
     * that renders it raw is a cross-site scripting hole reachable by causing
     * an error on purpose.
     *
     * @param string|null $note why this page is showing instead of Whoops; only
     *                          ever passed for a debug document
     */
    private static function builtIn(ErrorDocument $document, ?string $note = null): string
    {
        $escape = static fn(string $value): string => \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE);

        $heading = \sprintf('%d %s', $document->status, $document->title);

        $page = \sprintf(
            '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>%s</title><style>%s</style></head><body><main><h1>%s</h1><p>%s</p>',
            $escape($heading),
            self::STYLE,
            $escape($heading),
            $escape($document->message),
        );

        if ($note !== null) {
            $page .= \sprintf('<p class="note">%s</p>', $escape($note));
        }

        $trace = $document->detail('trace');

        if (\is_string($document->detail('exception')) && \is_array($trace)) {
            $page .= \sprintf(
                '<h2>%s</h2><p class="where">%s:%s</p><pre>%s</pre>',
                $escape((string) $document->detail('exception')),
                $escape((string) $document->detail('file')),
                $escape((string) $document->detail('line')),
                $escape(\implode("\n", \array_map(\strval(...), $trace))),
            );
        }

        return $page . '</main></body></html>';
    }

    private const STYLE = 'body{font:16px/1.5 system-ui,sans-serif;margin:0;padding:3rem 1rem;color:#111;'
        . 'background:#fafafa}main{max-width:44rem;margin:0 auto}h1{font-size:1.5rem;margin:0 0 .5rem}'
        . 'h2{font-size:1rem;margin:2rem 0 .25rem}p{margin:0 0 1rem}.where{color:#666;font-size:.875rem}'
        . '.note{padding:.5rem .75rem;border-left:3px solid #c80;background:#fff8e6;font-size:.875rem}'
        . 'pre{overflow-x:auto;background:#fff;border:1px solid #e5e5e5;padding:1rem;font-size:.8125rem;white-space:normal;}'
        . '@media(prefers-color-scheme:dark){body{background:#111;color:#eee}'
        . 'pre{background:#1a1a1a;border-color:#333}.where{color:#999}.note{background:#2a2210}}';
}
