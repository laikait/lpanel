<?php

declare(strict_types=1);

namespace App\Engine\MCP\Tool;

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
use App\Engine\MCP\Validation\SchemaValidator;

/**
 * Lists registered tools and calls one.
 *
 * **Only a registered name is ever resolved.** The client names a tool; the
 * registry says which class that is; the container builds that class. No
 * client input becomes a class name, a method name or a callable.
 *
 * **A tool that fails does not leak.** An McpException is the tool saying no in
 * protocol terms, and passes through. Anything else is reported -- with the
 * throwable, to the error log -- and the client receives a tool error saying
 * only that the tool failed. The exception's message never reaches the client:
 * it is exactly where a SQL fragment or a path would be.
 *
 * The order of a call, which is the order that keeps extensions from getting
 * around security:
 *
 *     find the tool, authorize          a refused tool is an unknown one
 *     mcp.access.denied     hook        when authorization said no
 *     mcp.tool.input        filter      the arguments, before they are validated
 *     validate against the schema       so a filter cannot smuggle input past it
 *     mcp.tool.before       hook        may refuse by throwing an McpException
 *     the tool
 *     mcp.tool.output       filter      the ToolResult
 *     mcp.tool.after        hook
 *     mcp.tool.failed       hook        instead of after, whatever went wrong
 *
 * Nothing an extension does can make an unauthorized call run or an invalid
 * argument reach the tool: authorization comes before every listener, and
 * validation after every one that could change the arguments. A listener can
 * only add a refusal.
 */
final class ToolRunner
{
    /** @param (\Closure(\Throwable): void)|null $report where an unexpected failure is reported */
    public function __construct(
        private readonly McpRegistry $registry,
        private readonly Container $container,
        private readonly McpAuthorizer $authorizer,
        private readonly ?\Closure $report = null,
        private readonly ?HookEngine $hooks = null,
        private readonly ?FilterEngine $filters = null,
    ) {}

    /**
     * What tools/list answers: every tool this caller may use, in registration order.
     *
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function list(McpContext $context): array
    {
        $tools = [];

        foreach ($this->authorizer->visible($this->registry->all(CapabilityKind::Tool), $context) as $capability) {
            $description = $this->filters?->apply('mcp.tool.description', $capability->description, $capability, $context) ?? $capability->description;

            $tools[] = [
                'name' => $capability->name,
                'description' => \is_string($description) ? $description : throw McpContractException::filterChangedType('mcp.tool.description', 'a string', \get_debug_type($description)),
                'inputSchema' => self::schema($capability->name, $this->tool($capability->name, $capability->handler)),
            ];
        }

        return $tools;
    }

    /**
     * @throws ToolException        for a name that is not a tool, or arguments that are not an object
     * @throws McpException         when the tool refuses in protocol terms
     * @throws McpContractException when the registered handler is not a Tool
     */
    public function call(string $name, mixed $arguments, McpContext $context): ToolResult
    {
        $capability = $this->registry->find(CapabilityKind::Tool, $name);

        if ($capability === null) {
            throw ToolException::unknown($name);
        }

        // A tool this caller may not use answers exactly as one that does not
        // exist. The client is told nothing; the log is told everything.
        if (!$this->authorizer->allows($capability, $context)) {
            $this->hooks?->do('mcp.access.denied', $capability, $context);

            throw ToolException::unknown($name);
        }

        if (!\is_array($arguments) || ($arguments !== [] && \array_is_list($arguments))) {
            throw ToolException::argumentsNotAnObject($name);
        }

        $tool = $this->tool($name, $capability->handler);

        if ($this->filters !== null) {
            $arguments = $this->filters->apply('mcp.tool.input', $arguments, $capability, $context);

            if (!\is_array($arguments)) {
                throw McpContractException::filterChangedType('mcp.tool.input', 'an array', \get_debug_type($arguments));
            }
        }

        // Checked against the schema the tool declared, and normalized, before
        // the tool sees them -- after any filter, so none can get around it.
        /** @var array<string, mixed> $arguments */
        $arguments = (new SchemaValidator())->validate($name, self::schema($name, $tool), $arguments);

        try {
            $this->hooks?->do('mcp.tool.before', $capability, $arguments, $context);

            $result = $tool->call($arguments, $context);
            $result = $this->filters?->apply('mcp.tool.output', $result, $capability, $context) ?? $result;

            if (!$result instanceof ToolResult) {
                throw McpContractException::filterChangedType('mcp.tool.output', ToolResult::class, \get_debug_type($result));
            }

            $this->hooks?->do('mcp.tool.after', $capability, $result, $context);

            return $result;
        } catch (McpContractException $e) {
            // The tool broke the contract -- an object in its result, say. That
            // is a bug to log like any other, not a refusal to pass on.
            return $this->failed($e, $capability, $context);
        } catch (McpException $e) {
            $this->hooks?->do('mcp.tool.failed', $capability, $e, $context);

            throw $e;
        } catch (\Throwable $e) {
            return $this->failed($e, $capability, $context);
        }
    }

    private function failed(\Throwable $e, Capability $capability, McpContext $context): ToolResult
    {
        $this->hooks?->do('mcp.tool.failed', $capability, $e, $context);

        if ($this->report !== null) {
            ($this->report)($e);
        }

        return ToolResult::error('The tool failed. The error has been logged.');
    }

    private function tool(string $name, string $handler): Tool
    {
        $tool = $this->container->get($handler);

        return $tool instanceof Tool ? $tool : throw McpContractException::notATool($name, $handler);
    }

    /** @return array<string, mixed> */
    private static function schema(string $name, Tool $tool): array
    {
        $schema = $tool->inputSchema();

        if (($schema['type'] ?? null) !== 'object') {
            throw McpContractException::invalidInputSchema($name, 'a tool\'s input schema is {"type": "object", ...}');
        }

        return $schema;
    }
}
