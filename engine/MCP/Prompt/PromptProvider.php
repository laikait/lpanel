<?php

declare(strict_types=1);

namespace App\Engine\MCP\Prompt;

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
 * Lists registered prompts and resolves one.
 *
 * Arguments are checked against the prompt's declaration before it runs: an
 * object whose values are strings, every required one present, nothing it
 * does not declare. Failures are handled as tools and resources handle them:
 * reported whole, answered as an internal error.
 */
final class PromptProvider
{
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
     * prompts/list.
     *
     * @return list<array{name: string, description: string, arguments: list<array{name: string, description: string, required: bool}>}>
     */
    public function list(McpContext $context): array
    {
        $prompts = [];

        foreach ($this->authorizer->visible($this->registry->all(CapabilityKind::Prompt), $context) as $capability) {
            $prompts[] = [
                'name' => $capability->name,
                'description' => $capability->description,
                'arguments' => \array_map(
                    static fn(PromptArgument $argument): array => $argument->toArray(),
                    $this->prompt($capability->name, $capability->handler)->arguments(),
                ),
            ];
        }

        return $prompts;
    }

    /** @throws McpException */
    public function get(string $name, mixed $arguments, McpContext $context): PromptResult
    {
        $capability = $this->registry->find(CapabilityKind::Prompt, $name);

        if ($capability === null) {
            throw PromptException::unknown($name);
        }

        if (!$this->authorizer->allows($capability, $context)) {
            $this->hooks?->do('mcp.access.denied', $capability, $context);

            throw PromptException::unknown($name);
        }

        $prompt = $this->prompt($name, $capability->handler);
        $checked = self::check($name, $prompt->arguments(), $arguments);

        try {
            $result = $prompt->get($checked, $context);
            $result = $this->filters?->apply('mcp.prompt.output', $result, $capability, $context) ?? $result;

            if (!$result instanceof PromptResult) {
                throw McpContractException::filterChangedType('mcp.prompt.output', PromptResult::class, \get_debug_type($result));
            }

            $this->hooks?->do('mcp.prompt.loaded', $capability, $result, $context);

            return $result;
        } catch (McpContractException $e) {
            $this->reportFailure($e, $capability, $context);
        } catch (McpException $e) {
            $this->hooks?->do('mcp.prompt.failed', $capability, $e, $context);

            throw $e;
        } catch (\Throwable $e) {
            $this->reportFailure($e, $capability, $context);
        }

        throw PromptException::failed();
    }

    /**
     * @param list<PromptArgument> $declared
     *
     * @return array<string, string>
     */
    private static function check(string $name, array $declared, mixed $arguments): array
    {
        $arguments ??= [];

        if (!\is_array($arguments) || ($arguments !== [] && \array_is_list($arguments))) {
            throw PromptException::invalidArguments($name, 'they are an object of names to strings');
        }

        $known = [];

        foreach ($declared as $argument) {
            $known[$argument->name] = $argument;

            if ($argument->required && !\array_key_exists($argument->name, $arguments)) {
                throw PromptException::invalidArguments($name, 'a required argument is missing', $argument->name);
            }
        }

        $checked = [];

        foreach ($arguments as $key => $value) {
            $key = (string) $key;

            if (!isset($known[$key])) {
                throw PromptException::invalidArguments($name, 'the prompt has no such argument', \substr($key, 0, 64));
            }

            if (!\is_string($value)) {
                throw PromptException::invalidArguments($name, 'argument values are strings', $key);
            }

            $checked[$key] = $value;
        }

        return $checked;
    }

    private function prompt(string $name, string $handler): Prompt
    {
        $prompt = $this->container->get($handler);

        return $prompt instanceof Prompt ? $prompt : throw McpContractException::notAPrompt($name, $handler);
    }

    private function reportFailure(\Throwable $e, Capability $capability, McpContext $context): void
    {
        $this->hooks?->do('mcp.prompt.failed', $capability, $e, $context);

        if ($this->report !== null) {
            ($this->report)($e);
        }
    }
}
