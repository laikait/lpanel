<?php

declare(strict_types=1);

namespace App\Engine\MCP\Resource;

use App\Engine\Container\Container;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\MCP\Capability;
use App\Engine\MCP\CapabilityKind;
use App\Engine\MCP\McpAuthorizer;
use App\Engine\MCP\McpContext;
use App\Engine\MCP\McpContractException;
use App\Engine\MCP\McpException;
use App\Engine\MCP\McpRegistry;

/**
 * Lists registered resources and reads one by URI.
 *
 * **Matching is strict and predictable.**
 *
 * - A URI is absolute, at most MAX_URI bytes, with no whitespace or control
 *   characters.
 * - A registered URI with no placeholders matches only itself, and wins over
 *   any template: `customer://recent` is never read as `customer://{id}`.
 * - Otherwise templates are tried in registration order. Each `{name}` matches
 *   one non-empty path segment -- no `/`, `?` or `#` -- and is percent-decoded
 *   for the handler. A decoded value containing `/` is refused, so an encoded
 *   slash cannot smuggle a second segment in.
 *
 * Nothing matched, or the handler's own "no such thing", is ResourceNotFound.
 * Any other failure is reported and answered as an internal error.
 */
final class ResourceReader
{
    public const MAX_URI = 2048;

    /** @param (\Closure(\Throwable): void)|null $report */
    public function __construct(
        private readonly McpRegistry $registry,
        private readonly Container $container,
        private readonly McpAuthorizer $authorizer,
        private readonly ?\Closure $report = null,
        private readonly ?HookEngine $hooks = null,
        private readonly ?FilterEngine $filters = null,
    ) {}

    /**
     * resources/list: the registered URIs with no placeholders.
     *
     * @return list<array{uri: string, name: string, description: string}>
     */
    public function resources(McpContext $context): array
    {
        $resources = [];

        foreach ($this->authorizer->visible($this->registry->all(CapabilityKind::Resource), $context) as $capability) {
            if (!\str_contains($capability->name, '{')) {
                $resources[] = ['uri' => $capability->name, 'name' => $capability->name, 'description' => $capability->description];
            }
        }

        return $resources;
    }

    /**
     * resources/templates/list: the registered URI templates.
     *
     * @return list<array{uriTemplate: string, name: string, description: string}>
     */
    public function templates(McpContext $context): array
    {
        $templates = [];

        foreach ($this->authorizer->visible($this->registry->all(CapabilityKind::Resource), $context) as $capability) {
            if (\str_contains($capability->name, '{')) {
                $templates[] = ['uriTemplate' => $capability->name, 'name' => $capability->name, 'description' => $capability->description];
            }
        }

        return $templates;
    }

    /** @throws McpException */
    public function read(mixed $uri, McpContext $context): ResourceContents
    {
        if (!\is_string($uri) || \strlen($uri) > self::MAX_URI || \preg_match('/^[a-z][a-z0-9+.-]*:\/\/[^\s\x00-\x1F\x7F]+$/D', $uri) !== 1) {
            throw ResourceException::invalidUri();
        }

        [$capability, $parameters] = $this->match($uri) ?? throw ResourceException::notFound($uri);

        // A resource this caller may not read is not found, as one that does not exist.
        if (!$this->authorizer->allows($capability, $context)) {
            $this->hooks?->do('mcp.access.denied', $capability, $context);

            throw ResourceException::notFound($uri);
        }

        $handler = $this->container->get($capability->handler);

        if (!$handler instanceof Resource) {
            throw McpContractException::notAResource($capability->name, $capability->handler);
        }

        try {
            $contents = $handler->read($uri, $parameters, $context);
            $contents = $this->filters?->apply('mcp.resource.output', $contents, $capability, $context) ?? $contents;

            if (!$contents instanceof ResourceContents) {
                throw McpContractException::filterChangedType('mcp.resource.output', ResourceContents::class, \get_debug_type($contents));
            }

            $this->hooks?->do('mcp.resource.read', $capability, $uri, $context);

            return $contents;
        } catch (McpContractException $e) {
            $this->reportFailure($e, $capability, $context);
        } catch (McpException $e) {
            $this->hooks?->do('mcp.resource.failed', $capability, $e, $context);

            throw $e;
        } catch (\Throwable $e) {
            $this->reportFailure($e, $capability, $context);
        }

        throw ResourceException::failed();
    }

    /** @return array{Capability, array<string, string>}|null */
    private function match(string $uri): ?array
    {
        $templates = [];

        foreach ($this->registry->all(CapabilityKind::Resource) as $capability) {
            if (!\str_contains($capability->name, '{')) {
                if ($capability->name === $uri) {
                    return [$capability, []];
                }

                continue;
            }

            $templates[] = $capability;
        }

        foreach ($templates as $capability) {
            $parameters = self::parameters($capability->name, $uri);

            if ($parameters !== null) {
                return [$capability, $parameters];
            }
        }

        return null;
    }

    /** @return array<string, string>|null */
    private static function parameters(string $template, string $uri): ?array
    {
        $pattern = '';
        $names = [];

        foreach (\preg_split('/(\{[a-z_][a-z0-9_]*\})/', $template, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
            if (\preg_match('/^\{([a-z_][a-z0-9_]*)\}$/D', $part, $name) === 1) {
                $names[] = $name[1];
                $pattern .= '([^\/?#]+)';
            } else {
                $pattern .= \preg_quote($part, '~');
            }
        }

        // "~", not "#": the placeholder class above contains a "#".
        if (\preg_match('~\A' . $pattern . '\z~', $uri, $match) !== 1) {
            return null;
        }

        $parameters = [];

        foreach ($names as $index => $name) {
            $value = \rawurldecode($match[$index + 1]);

            if (\str_contains($value, '/') || \preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                return null;
            }

            $parameters[$name] = $value;
        }

        return $parameters;
    }

    private function reportFailure(\Throwable $e, Capability $capability, McpContext $context): void
    {
        $this->hooks?->do('mcp.resource.failed', $capability, $e, $context);

        if ($this->report !== null) {
            ($this->report)($e);
        }
    }
}
