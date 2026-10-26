<?php

declare(strict_types=1);

namespace App\Engine\Dispatch;

use App\Engine\Error\FrameworkException;

/**
 * Handler resolution and result-conversion failures.
 *
 * These are developer mistakes rather than client mistakes, so they become
 * 500s. A client mistake -- a route parameter that is not the declared type --
 * is an HttpException instead, and comes out as a 400.
 */
final class DispatchException extends FrameworkException
{
    public static function unresolvableHandler(string $description, string $reason): self
    {
        return new self(\sprintf('Cannot dispatch to %s: %s', $description, $reason));
    }

    public static function unsupportedResult(string $type, string $route): self
    {
        return new self(\sprintf(
            'The handler for %s returned %s, which cannot become a response. '
            . 'Return a Response, a string, an array, a JsonSerializable, or null.',
            $route,
            $type,
        ));
    }
}
