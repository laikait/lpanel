<?php

declare(strict_types=1);

namespace App\Engine\OpenApi;

use App\Engine\Auth\AuthGuard;
use App\Engine\Error\ErrorDocument;
use App\Engine\Http\Response;
use App\Engine\Routing\Route;
use App\Engine\Routing\Router;
use App\Engine\Schema\Field;
use App\Engine\Schema\Schema;
use App\Engine\Security\Guard;

/**
 * An OpenAPI 3.1 description of the routes that asked to be described.
 *
 *     $routes->get('/customers/{id}', [CustomerApi::class, 'show'])->meta(['openapi' => [
 *         'summary'   => 'One customer',
 *         'path'      => ['id' => Field::int('id')],
 *         'responses' => [200 => ['data' => CustomerSchema::resource()], 404 => 'No such customer'],
 *     ]]);
 *
 * **Only routes that say so appear.** `openapi` true includes a route with
 * what it already declares; an array adds details; false hides it, which is
 * how one route opts out of a group that opted in. Every other route -- the
 * pages, the login form, a health check -- is invisible, so a description
 * can never publish an endpoint nobody meant to document.
 *
 * **Nothing is guessed.** No handler runs and no response is inferred. What
 * is derived is what the framework itself decides: the path parameters and
 * their where() patterns, `deprecated` from the route's own meta, bearer
 * security for a route that needs a login, and the answers the framework
 * gives on a route's behalf -- 401 and 403 for `auth` and `can`, 429 for
 * `rate_limit` -- with the one error document's shape. Not 400 or 415 for a
 * body: a `body` here describes, it does not validate, so whether the handler
 * answers those is the handler's to say in `responses`.
 *
 * **The same routes give the same bytes.** Paths are sorted, methods are in a
 * fixed order and so are the schemas, so the document can be committed, diffed
 * and cached by ETag.
 */
final class OpenApiGenerator
{
    public const OPENAPI = '3.1.0';

    public const META = 'openapi';

    /** The keys an `openapi` meta array may hold. */
    public const KEYS = ['summary', 'description', 'tags', 'operationId', 'path', 'query', 'headers', 'body', 'body_type', 'responses', 'security'];

    /** OpenAPI's own order, which is also the order operations are listed in. */
    private const METHODS = ['GET', 'PUT', 'POST', 'DELETE', 'OPTIONS', 'PATCH'];

    private const BEARER = 'bearerAuth';

    private const ERROR = '#/components/schemas/Error';

    /** @var ?array<string, mixed> json()'s copy of generate() */
    private ?array $document = null;

    public function __construct(
        private readonly Router $router,
        private readonly string $title,
        private readonly string $version = '1.0.0',
        private readonly string $description = '',
        /** Absolute URL of the application (APP_URL); null describes it relative to wherever it is served. */
        private readonly ?string $url = null,
    ) {}

    /**
     * The document, as data.
     *
     * @return array<string, mixed>
     *
     * @throws OpenApiException for meta that cannot be described
     */
    public function generate(): array
    {
        $schemas = new SchemaConverter();
        $paths = [];
        $operationIds = [];
        $usesBearer = false;
        $usesError = false;

        foreach ($this->router->routes() as $route) {
            $details = self::details($route);

            if ($details === null || $route->method() === 'HEAD') {
                continue;
            }

            foreach (self::variants($route) as $path => $omitted) {
                $method = \strtolower($route->method());

                if (isset($paths[$path][$method])) {
                    throw OpenApiException::duplicateOperation($route->method(), $path);
                }

                $operation = $this->operation($route, $details, $omitted, $schemas, $usesBearer, $usesError);
                $operationId = $operation['operationId'];

                if (isset($operationIds[$operationId])) {
                    throw OpenApiException::duplicateOperationId($operationId);
                }

                $operationIds[$operationId] = true;
                $paths[$path][$method] = $operation;
            }
        }

        \ksort($paths, \SORT_STRING);

        foreach ($paths as $path => $operations) {
            \uksort($operations, static fn(string $a, string $b): int => self::rank($a) <=> self::rank($b));
            $paths[$path] = $operations;
        }

        $info = ['title' => $this->title, 'version' => $this->version];

        if ($this->description !== '') {
            $info['description'] = $this->description;
        }

        $document = [
            'openapi' => self::OPENAPI,
            'info' => $info,
            'servers' => [['url' => $this->serverUrl()]],
            'paths' => $paths === [] ? new \stdClass() : $paths,
        ];

        $components = [];
        $described = $schemas->components();

        if ($usesError) {
            $described['Error'] = ErrorDocument::schema();
            \ksort($described);
        }

        if ($described !== []) {
            $components['schemas'] = $described;
        }

        if ($usesBearer) {
            $components['securitySchemes'] = [self::BEARER => ['type' => 'http', 'scheme' => 'bearer']];
        }

        if ($components !== []) {
            $document['components'] = $components;
        }

        return $document;
    }

    /**
     * The document as JSON: pretty for a file someone reads, compact for the
     * wire. Built once per process; the routes do not change after boot.
     *
     * @throws OpenApiException for meta that cannot be described, or a value JSON cannot hold
     */
    public function json(bool $pretty = true): string
    {
        $this->document ??= $this->generate();

        try {
            return \json_encode(
                $this->document,
                \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR | ($pretty ? \JSON_PRETTY_PRINT : 0),
            ) . ($pretty ? "\n" : '');
        } catch (\JsonException $e) {
            throw OpenApiException::unencodable($e->getMessage());
        }
    }

    /**
     * What the route's meta asks for: null when it is not described.
     *
     * @return ?array<string, mixed>
     */
    private static function details(Route $route): ?array
    {
        $meta = $route->metaValue(self::META);

        if ($meta === null || $meta === false) {
            return null;
        }

        if ($meta === true) {
            return [];
        }

        if (!\is_array($meta)) {
            throw OpenApiException::notAnArray($route);
        }

        foreach (\array_keys($meta) as $key) {
            if (!\in_array($key, self::KEYS, true)) {
                throw OpenApiException::unknownKey($route, (string) $key, self::KEYS);
            }
        }

        /** @var array<string, mixed> $meta */
        return $meta;
    }

    /**
     * Each OpenAPI path this route answers, with the optional parameter it
     * leaves out, if any. OpenAPI has no optional path segment, so
     * /reports/{year?} is two paths: /reports/{year} and /reports.
     *
     * @return array<string, ?string>
     */
    private static function variants(Route $route): array
    {
        $path = '/' . \trim($route->path(), '/');
        $optional = null;

        foreach ($route->parameters() as $parameter) {
            if ($parameter['optional']) {
                $optional = $parameter['name'];
            }
        }

        if ($optional === null) {
            return [$path => null];
        }

        $full = \str_replace('{' . $optional . '?}', '{' . $optional . '}', $path);
        $short = \preg_replace('#/\{' . \preg_quote($optional, '#') . '\?\}$#', '', $path) ?? $path;

        return [$full => null, ($short === '' ? '/' : $short) => $optional];
    }

    /**
     * @param array<string, mixed> $details
     *
     * @return array<string, mixed>&array{operationId: string}
     */
    private function operation(Route $route, array $details, ?string $omitted, SchemaConverter $schemas, bool &$usesBearer, bool &$usesError): array
    {
        $operation = [];
        $string = static fn(string $key): ?string => self::stringOrNull($route, $details, $key);

        $tags = $details['tags'] ?? ($route->module() !== null ? [$route->module()] : []);

        if (!\is_array($tags) || \array_filter($tags, \is_string(...)) !== $tags) {
            throw OpenApiException::invalidValue($route, 'tags', 'a list of strings');
        }

        if ($tags !== []) {
            $operation['tags'] = \array_values($tags);
        }

        foreach (['summary', 'description'] as $key) {
            if (($value = $string($key)) !== null) {
                $operation[$key] = $value;
            }
        }

        $operationId = $string('operationId') ?? self::operationId($route);
        $operation['operationId'] = $omitted === null ? $operationId : $operationId . '_without_' . $omitted;

        $deprecated = $route->metaValue('deprecated');

        if ($deprecated !== null && $deprecated !== false && $deprecated !== '') {
            $operation['deprecated'] = true;
        }

        $parameters = [
            ...$this->pathParameters($route, $details, $omitted, $schemas),
            ...$this->schemaParameters($route, $details, 'query', $schemas),
            ...$this->schemaParameters($route, $details, 'headers', $schemas),
        ];

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        $body = $details['body'] ?? null;
        $bodyType = $string('body_type') ?? 'application/json';

        if ($body !== null) {
            if (!$body instanceof Schema) {
                throw OpenApiException::invalidValue($route, 'body', 'a Schema');
            }

            $operation['requestBody'] = [
                'required' => true,
                'content' => [$bodyType => ['schema' => $schemas->reference($body)]],
            ];
        }

        $responses = $this->responses($route, $details, $schemas);
        $derived = $this->derivedResponses($route);

        foreach ($derived as $status => $description) {
            if (!isset($responses[$status])) {
                $responses[$status] = [
                    'description' => $description,
                    'content' => ['application/json' => ['schema' => ['$ref' => self::ERROR]]],
                ];
                $usesError = true;
            }
        }

        if ($responses !== []) {
            // Statuses in order, "default" last. PHP has already read "200"
            // as the int 200, so the keys are compared as strings.
            \uksort($responses, static fn(int|string $a, int|string $b): int => [$a === 'default', (string) $a] <=> [$b === 'default', (string) $b]);
            $operation['responses'] = $responses;
        }

        $security = $details['security'] ?? AuthGuard::isProtected($route);

        if (!\is_bool($security)) {
            throw OpenApiException::invalidValue($route, 'security', 'true (a bearer token) or false (public)');
        }

        if ($security) {
            $operation['security'] = [[self::BEARER => []]];
            $usesBearer = true;
        }

        return $operation;
    }

    /**
     * @param array<string, mixed> $details
     *
     * @return list<array<string, mixed>>
     */
    private function pathParameters(Route $route, array $details, ?string $omitted, SchemaConverter $schemas): array
    {
        $declared = $details['path'] ?? [];

        if (!\is_array($declared)) {
            throw OpenApiException::invalidValue($route, 'path', 'an array of parameter name => Field');
        }

        $names = \array_column($route->parameters(), 'name');

        foreach ($declared as $name => $field) {
            if (!\in_array($name, $names, true)) {
                throw OpenApiException::unknownPathParameter($route, (string) $name);
            }

            if (!$field instanceof Field) {
                throw OpenApiException::invalidValue($route, 'path', 'an array of parameter name => Field');
            }
        }

        $parameters = [];

        foreach ($names as $name) {
            if ($name === $omitted) {
                continue;
            }

            $field = $declared[$name] ?? Field::string($name);
            $schema = $schemas->field($field);
            $constraint = $route->constraint($name);

            if ($constraint !== null && !isset($schema['pattern'])) {
                $schema['pattern'] = '^(?:' . $constraint . ')$';
            }

            $parameter = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => $schema];

            if (\is_string($schema['description'] ?? null)) {
                $parameter['description'] = $schema['description'];
            }

            $parameters[] = $parameter;
        }

        return $parameters;
    }

    /**
     * A query or headers Schema as parameters, one per field.
     *
     * @param array<string, mixed> $details
     *
     * @return list<array<string, mixed>>
     */
    private function schemaParameters(Route $route, array $details, string $key, SchemaConverter $schemas): array
    {
        $schema = $details[$key] ?? null;

        if ($schema === null) {
            return [];
        }

        if (!$schema instanceof Schema) {
            throw OpenApiException::invalidValue($route, $key, 'a Schema, one field per parameter');
        }

        $parameters = [];

        foreach ($schema->fields() as $name => $field) {
            $converted = $schemas->field($field);
            $parameter = [
                'name' => $name,
                'in' => $key === 'query' ? 'query' : 'header',
                'required' => $field->isRequired(),
                'schema' => $converted,
            ];

            if (\is_string($converted['description'] ?? null)) {
                $parameter['description'] = $converted['description'];
            }

            $parameters[] = $parameter;
        }

        return $parameters;
    }

    /**
     * The declared responses.
     *
     *     200 => Schema                         the body is this schema
     *     200 => [Schema]                       a JSON list of it
     *     200 => ['data' => Schema]             ApiResponse::item(): {"data": …}
     *     200 => ['data' => [Schema]]           ApiResponse::collection(): {"data": [ … ], "meta": {…}}
     *     404 => 'No such customer'             a description, no body
     *     201 => ['description' => '…', 'schema' => Schema | [Schema], 'data' => …]
     *
     * @param array<string, mixed> $details
     *
     * @return array<int|string, array<string, mixed>> status => response; PHP keeps "200" as the int 200
     */
    private function responses(Route $route, array $details, SchemaConverter $schemas): array
    {
        $declared = $details['responses'] ?? [];

        if (!\is_array($declared)) {
            throw OpenApiException::invalidValue($route, 'responses', 'an array of status => response');
        }

        $responses = [];

        foreach ($declared as $status => $response) {
            $status = (string) $status;

            if ($status !== 'default' && (!\ctype_digit($status) || (int) $status < 100 || (int) $status > 599)) {
                throw OpenApiException::invalidStatus($route, $status);
            }

            $text = $status === 'default' ? 'Any other response' : Response::statusText((int) $status);

            if (\is_string($response)) {
                $responses[$status] = ['description' => $response];

                continue;
            }

            $description = $text;
            $body = null;

            if ($response instanceof Schema || self::isListOfOne($response)) {
                $body = $this->body($route, $response, $schemas);
            } elseif (\is_array($response)) {
                foreach (\array_keys($response) as $key) {
                    if (!\in_array($key, ['description', 'schema', 'data'], true)) {
                        throw OpenApiException::invalidValue($route, 'responses', 'Schema, [Schema], a string, or an array of description, schema, data');
                    }
                }

                if (\is_string($response['description'] ?? null)) {
                    $description = $response['description'];
                }

                if (isset($response['schema'])) {
                    $body = $this->body($route, $response['schema'], $schemas);
                } elseif (isset($response['data'])) {
                    $data = $this->body($route, $response['data'], $schemas);
                    $properties = ['data' => $data];

                    if (self::isListOfOne($response['data'])) {
                        $properties['meta'] = ['type' => 'object'];
                    }

                    $body = ['type' => 'object', 'required' => ['data'], 'properties' => $properties];
                }
            } else {
                throw OpenApiException::invalidValue($route, 'responses', 'Schema, [Schema], a string, or an array of description, schema, data');
            }

            $responses[$status] = $body === null
                ? ['description' => $description]
                : ['description' => $description, 'content' => ['application/json' => ['schema' => $body]]];
        }

        return $responses;
    }

    /** @return array<string, mixed> */
    private function body(Route $route, mixed $value, SchemaConverter $schemas): array
    {
        if ($value instanceof Schema) {
            return $schemas->reference($value);
        }

        if (self::isListOfOne($value)) {
            /** @var array{0: Schema} $value */
            return ['type' => 'array', 'items' => $schemas->reference($value[0])];
        }

        throw OpenApiException::invalidValue($route, 'responses', 'a Schema, or [Schema] for a list of them');
    }

    /**
     * What the framework's guards answer on this route's behalf, before the
     * handler runs and whatever it does.
     *
     * @return array<int, string>
     */
    private function derivedResponses(Route $route): array
    {
        $derived = [];

        if (AuthGuard::isProtected($route)) {
            $derived[401] = 'Not signed in';
        }

        if (AuthGuard::capabilitiesFor($route) !== []) {
            $derived[403] = 'Signed in without the capability this needs';
        }

        if ($route->metaValue(Guard::RATE_LIMIT_META) !== null) {
            $derived[429] = 'Too many requests; see Retry-After';
        }

        return $derived;
    }

    /** The route name with its dots as underscores, else the method and path. */
    private static function operationId(Route $route): string
    {
        $source = $route->routeName() ?? (\strtolower($route->method()) . ' ' . $route->path());
        $id = \trim((string) \preg_replace('/[^A-Za-z0-9]+/', '_', $source), '_');

        return $id === '' ? \strtolower($route->method()) . '_root' : $id;
    }

    /** @param array<string, mixed> $details */
    private static function stringOrNull(Route $route, array $details, string $key): ?string
    {
        $value = $details[$key] ?? null;

        if ($value !== null && !\is_string($value)) {
            throw OpenApiException::invalidValue($route, $key, 'a string');
        }

        return $value;
    }

    private static function isListOfOne(mixed $value): bool
    {
        return \is_array($value) && \count($value) === 1 && \array_key_exists(0, $value) && $value[0] instanceof Schema;
    }

    private static function rank(string $method): int
    {
        $index = \array_search(\strtoupper($method), self::METHODS, true);

        return $index === false ? \count(self::METHODS) : $index;
    }

    /**
     * APP_URL, which already carries the subdirectory (see AppUrl); without
     * it, the base path, so a viewer resolves the paths against wherever it
     * loaded the document from. Never the Host header.
     */
    private function serverUrl(): string
    {
        $url = \rtrim(\trim($this->url ?? ''), '/');

        return $url !== '' ? $url : '/' . \trim($this->router->basePath(), '/');
    }
}
