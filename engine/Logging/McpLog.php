<?php

declare(strict_types=1);

namespace App\Engine\Logging;

use App\Engine\Hook\HookEngine;
use App\Engine\MCP\Capability;
use App\Engine\MCP\McpContext;
use App\Engine\MCP\McpError;
use App\Engine\MCP\McpException;
use App\Engine\MCP\Prompt\PromptResult;
use App\Engine\MCP\Tool\ToolResult;

/**
 * The bridge from the mcp.* hooks to the log.
 *
 * SystemAuditLog's arrangement again: engine/MCP announces what happened and
 * holds no logger; this listens and writes, to its own `mcp` channel.
 *
 * Every record carries who and what -- the MCP request id, transport, client,
 * principal (an id, or null for a guest), capability, kind and module -- and
 * never the payload. Arguments, results, resource contents and prompt text are
 * not logged: they are where a customer's data or a secret would be. The
 * framework's own redaction still applies to what is logged.
 *
 *   access denied            warning   authorization said no; the client heard "unknown"
 *   tool/resource/prompt ok  info      with the tool's duration
 *   refused in protocol      notice    the capability said no (an McpException)
 *   failed                   warning   the exception class only; the throwable
 *                                      itself went to the error log, reported
 *   request failed           info      the method and error code
 *   request received         debug
 */
final class McpLog
{
    public const CHANNEL = 'mcp';

    /** @var array<string, int|float> hrtime at mcp.tool.before, by request id */
    private array $started = [];

    public function __construct(private readonly LogManager $logs) {}

    public function attach(HookEngine $hooks): void
    {
        $hooks->add('mcp.request.received', $this->received(...), 10, 'engine');
        $hooks->add('mcp.request.failed', $this->requestFailed(...), 10, 'engine');
        $hooks->add('mcp.access.denied', $this->denied(...), 10, 'engine');
        $hooks->add('mcp.tool.before', $this->toolStarted(...), 10, 'engine');
        $hooks->add('mcp.tool.after', $this->toolFinished(...), 10, 'engine');
        $hooks->add('mcp.tool.failed', $this->failed(...), 10, 'engine');
        $hooks->add('mcp.resource.read', $this->resourceRead(...), 10, 'engine');
        $hooks->add('mcp.resource.failed', $this->failed(...), 10, 'engine');
        $hooks->add('mcp.prompt.loaded', $this->promptLoaded(...), 10, 'engine');
        $hooks->add('mcp.prompt.failed', $this->failed(...), 10, 'engine');
    }

    public function received(string $method, McpContext $context): void
    {
        $this->log(Level::Debug, 'MCP request', ['method' => $method] + $context->forLog());
    }

    public function requestFailed(string $method, McpError $error, McpContext $context): void
    {
        $this->log(Level::Info, 'MCP request failed', ['method' => $method, 'code' => $error->code->value] + $context->forLog());
    }

    public function denied(Capability $capability, McpContext $context): void
    {
        $this->log(Level::Warning, \sprintf('MCP %s denied', $capability->kind->value), self::record($capability, $context, 'denied'));
    }

    /** @param array<string, mixed> $arguments not logged; they are the payload */
    public function toolStarted(Capability $capability, array $arguments, McpContext $context): void
    {
        $this->started[$context->requestId] = \hrtime(true);
    }

    public function toolFinished(Capability $capability, ToolResult $result, McpContext $context): void
    {
        $this->log(
            Level::Info,
            'MCP tool called',
            self::record($capability, $context, $result->isError() ? 'tool_error' : 'ok') + ['ms' => $this->elapsed($context)],
        );
    }

    public function resourceRead(Capability $capability, string $uri, McpContext $context): void
    {
        // The URI, not the template: which customer was read is the point.
        $this->log(Level::Info, 'MCP resource read', self::record($capability, $context, 'ok') + ['uri' => \substr($uri, 0, 256)]);
    }

    public function promptLoaded(Capability $capability, PromptResult $result, McpContext $context): void
    {
        $this->log(Level::Info, 'MCP prompt loaded', self::record($capability, $context, 'ok'));
    }

    public function failed(Capability $capability, \Throwable $e, McpContext $context): void
    {
        $record = self::record($capability, $context, $e instanceof McpException ? 'refused' : 'failed');
        $record['error'] = $e instanceof McpException ? $e->error()->code->value : $e::class;

        if ($capability->kind->value === 'tool') {
            $record['ms'] = $this->elapsed($context);
        }

        $this->log(
            $e instanceof McpException ? Level::Notice : Level::Warning,
            \sprintf('MCP %s %s', $capability->kind->value, $record['outcome']),
            $record,
        );
    }

    /** @return array<string, mixed> */
    private static function record(Capability $capability, McpContext $context, string $outcome): array
    {
        return [
            'kind' => $capability->kind->value,
            'capability' => $capability->name,
            'module' => $capability->module,
            'outcome' => $outcome,
        ] + $context->forLog();
    }

    private function elapsed(McpContext $context): ?float
    {
        $started = $this->started[$context->requestId] ?? null;
        unset($this->started[$context->requestId]);

        return $started === null ? null : \round((\hrtime(true) - $started) / 1e6, 2);
    }

    /** @param array<string, mixed> $context */
    private function log(Level $level, string $message, array $context): void
    {
        $this->logs->channel(self::CHANNEL)->log($level, $message, $context);
    }
}
