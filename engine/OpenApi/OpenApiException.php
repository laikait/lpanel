<?php

declare(strict_types=1);

namespace App\Engine\OpenApi;

use App\Engine\Error\FrameworkException;
use App\Engine\Routing\Route;

/**
 * A route's `openapi` meta cannot be turned into a description.
 *
 * Developer mistakes, every one, and all raised when the document is built:
 * `openapi:export` exits 1 with the message, and a test that builds the
 * document finds the mistake before a client does. A description that skipped
 * what it did not understand would look complete and be wrong.
 */
final class OpenApiException extends FrameworkException
{
    public static function notAnArray(Route $route): self
    {
        return new self(\sprintf(
            '%s: the "openapi" meta must be true, false or an array of details.',
            self::describe($route),
        ));
    }

    /** @param list<string> $known */
    public static function unknownKey(Route $route, string $key, array $known): self
    {
        return new self(\sprintf(
            '%s: "%s" is not an openapi meta key. Known: %s.',
            self::describe($route),
            $key,
            \implode(', ', $known),
        ));
    }

    public static function invalidValue(Route $route, string $key, string $expected): self
    {
        return new self(\sprintf('%s: openapi "%s" must be %s.', self::describe($route), $key, $expected));
    }

    public static function unknownPathParameter(Route $route, string $parameter): self
    {
        return new self(\sprintf(
            '%s: openapi "path" describes "%s", which is not a parameter of the path.',
            self::describe($route),
            $parameter,
        ));
    }

    public static function invalidStatus(Route $route, string $status): self
    {
        return new self(\sprintf(
            '%s: "%s" is not a response status. Use 100-599 or "default".',
            self::describe($route),
            $status,
        ));
    }

    public static function duplicateOperation(string $method, string $path): self
    {
        return new self(\sprintf(
            'Two documented routes answer %s %s. An API description has one operation per method and path.',
            $method,
            $path,
        ));
    }

    public static function duplicateOperationId(string $operationId): self
    {
        return new self(\sprintf(
            'Two documented routes have the operationId "%s". Give one of them an "operationId" or a different route name.',
            $operationId,
        ));
    }

    public static function schemaNameClash(string $name): self
    {
        return new self(\sprintf(
            'Two different schemas are both named "%s". A description refers to schemas by name, so each name '
            . 'must mean one shape; rename one with Schema::named().',
            $name,
        ));
    }

    public static function routeTaken(string $path): self
    {
        return new self(\sprintf(
            'openapi.route is "%s", but an application route already answers GET %s. Choose another path for the '
            . 'description, or remove the route.',
            $path,
            $path,
        ));
    }

    public static function docsWithoutRoute(string $docs): self
    {
        return new self(\sprintf(
            'openapi.docs is "%s", but openapi.route is not set, so the page would have no description to show. '
            . 'Set openapi.route too, e.g. "/openapi.json".',
            $docs,
        ));
    }

    public static function docsOverRoute(string $path): self
    {
        return new self(\sprintf(
            'openapi.route and openapi.docs both need %s: the page and the description it loads must be at '
            . 'different paths, e.g. "/openapi.json" and "/docs".',
            $path,
        ));
    }

    public static function invalidSchemaName(string $name): self
    {
        return new self(\sprintf(
            '"%s" cannot name a schema in an API description: OpenAPI allows letters, digits, ".", "-" and "_" '
            . 'only, and a "/" would break every reference to it. Rename it with Schema::named().',
            $name,
        ));
    }

    public static function unencodable(string $reason): self
    {
        return new self(\sprintf(
            'The description cannot be written as JSON (%s): look for NAN or INF in a default, or text that is not '
            . 'UTF-8 in a summary or description.',
            $reason,
        ));
    }

    private static function describe(Route $route): string
    {
        return $route->method() . ' ' . $route->path()
            . ($route->routeName() !== null ? ' (' . $route->routeName() . ')' : '');
    }
}
