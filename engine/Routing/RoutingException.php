<?php

declare(strict_types=1);

namespace App\Engine\Routing;

use App\Engine\Error\FrameworkException;

/**
 * Registration-time and URL-generation errors.
 *
 * These are developer mistakes, not request failures, and they are raised as
 * early as possible: a duplicate route name is discovered when the module
 * registers it, not when someone finally requests it.
 */
final class RoutingException extends FrameworkException
{
    public static function duplicateName(string $name, ?string $owner, ?string $existingOwner): self
    {
        return new self(\sprintf(
            'Route name "%s" is already registered by %s and cannot be reused by %s. Route names must be unique.',
            $name,
            $existingOwner ?? 'an unknown module',
            $owner ?? 'an unknown module',
        ));
    }

    public static function unknownName(string $name): self
    {
        return new self(\sprintf('No route is named "%s".', $name));
    }

    public static function missingParameter(string $name, string $parameter): self
    {
        return new self(\sprintf(
            'Cannot build the URL for route "%s": the required parameter "%s" was not supplied.',
            $name,
            $parameter,
        ));
    }

    public static function parameterRejected(string $name, string $parameter, string $value, string $pattern): self
    {
        return new self(\sprintf(
            'Cannot build the URL for route "%s": "%s" is not a valid value for "%s", which must match %s.',
            $name,
            $value,
            $parameter,
            $pattern,
        ));
    }

    public static function invalidPattern(string $path, string $reason): self
    {
        return new self(\sprintf('Route path "%s" is invalid: %s', $path, $reason));
    }

    public static function unsupportedMethod(string $method): self
    {
        return new self(\sprintf('"%s" is not a supported HTTP method.', $method));
    }

    public static function noAppUrl(): self
    {
        return new self(
            'An absolute link needs APP_URL, the application\'s public address (https://example.com, '
            . 'with a subdirectory if it has one). It is never taken from the request\'s Host header, '
            . 'which the client chooses.',
        );
    }

    public static function invalidAppUrl(string $url): self
    {
        return new self(\sprintf('APP_URL "%s" is not an http or https URL without a query.', $url));
    }
}
