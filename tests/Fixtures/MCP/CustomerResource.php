<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\MCP;

use App\Engine\MCP\McpContext;
use App\Engine\MCP\Resource\Resource;
use App\Engine\MCP\Resource\ResourceContents;
use App\Engine\MCP\Resource\ResourceException;

/** customer://{id}, customer://recent and a failing read, for the reader's tests. */
final class CustomerResource implements Resource
{
    /** @var list<array{string, array<string, string>}> */
    public static array $reads = [];

    public function read(string $uri, array $parameters, McpContext $context): ResourceContents
    {
        self::$reads[] = [$uri, $parameters];

        if (($parameters['id'] ?? '') === 'noisy') {
            echo 'debugging output left in by mistake';
        }

        return match (true) {
            $uri === 'customer://recent' => ResourceContents::json($uri, ['recent' => [1, 2]]),
            ($parameters['id'] ?? '') === 'explode' => throw new \RuntimeException('connection to 10.0.0.5 refused, password S3cret'),
            ($parameters['id'] ?? '') === 'missing' => throw ResourceException::notFound($uri),
            ($parameters['id'] ?? '') === 'logo' => ResourceContents::blob($uri, "\x89PNG", 'image/png'),
            \ctype_digit($parameters['id'] ?? '') => ResourceContents::json($uri, ['id' => (int) $parameters['id'], 'name' => 'Ada']),
            default => ResourceContents::text($uri, 'id: ' . ($parameters['id'] ?? '')),
        };
    }
}
