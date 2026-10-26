<?php

declare(strict_types=1);

namespace App\Engine\Logging;

use App\Engine\Error\ErrorContext;
use App\Engine\Http\HttpException;
use App\Engine\Http\Request;

/**
 * The bridge from error.reported to the log.
 *
 * A listener, structurally identical to one a module would write. That is the
 * whole demonstration: error handling announces, logging listens, and the
 * dependency runs one way only -- engine/Error references nothing here, and an
 * architecture test keeps it that way. Delete this class and errors stop being
 * logged; nothing else changes.
 *
 * The level is chosen from the status rather than from the exception class. A
 * 404 is not an incident -- it is a visitor typing a URL, and at error level a
 * scanner sweeping for /wp-admin would page somebody at three in the morning.
 * A 500 is. The line is where HTTP already draws it:
 *
 *   5xx or not an HttpException   error      something is broken
 *   429                           warning    a client is being throttled
 *   other 4xx                     notice     a client asked for the wrong thing
 */
final class ErrorLog
{
    public const CHANNEL = 'error';

    public function __construct(private readonly LogManager $logs) {}

    /**
     * error.reported passes the request being served, when there is one.
     *
     * What it contributes is what the request was: its method and path. Which
     * request it was -- the id a user
     * quotes from a response header -- is added to every record by the log's
     * enricher, so it is not repeated here. The query string and the body are
     * deliberately left out: that is where a token or a password is, and an
     * error log is read by more people than the request was.
     */
    public function __invoke(\Throwable $e, ErrorContext $context, ?Request $request = null): void
    {
        $fields = [
            'exception' => $e,
            'audience' => $context->value,
        ];

        if ($request !== null) {
            $fields['method'] = $request->method();
            $fields['path'] = $request->path();
        }

        $this->logs->channel(self::CHANNEL)->log(self::levelFor($e), self::summarise($e), $fields);
    }

    public static function levelFor(\Throwable $e): Level
    {
        if (!$e instanceof HttpException) {
            return Level::Error;
        }

        return match (true) {
            $e->status() >= 500 => Level::Error,
            $e->status() === 429 => Level::Warning,
            $e->status() >= 400 => Level::Notice,
            default => Level::Info,
        };
    }

    /**
     * One line naming what happened.
     *
     * The message is not repeated here -- it is in the context, under
     * "exception", with the class and position beside it. Putting it in both
     * places makes every line twice as long and makes grepping for a message
     * return two hits per occurrence.
     */
    private static function summarise(\Throwable $e): string
    {
        return $e instanceof HttpException
            ? \sprintf('%d %s', $e->status(), $e->getMessage())
            : \sprintf('Unhandled %s', \basename(\str_replace('\\', '/', $e::class)));
    }
}
