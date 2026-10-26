<?php

declare(strict_types=1);

namespace App\Engine\MCP;

use App\Engine\Hook\HookEngine;
use App\Engine\MCP\Prompt\PromptProvider;
use App\Engine\MCP\Protocol\MessageParser;
use App\Engine\MCP\Protocol\Notification;
use App\Engine\MCP\Protocol\ProtocolException;
use App\Engine\MCP\Protocol\ProtocolVersion;
use App\Engine\MCP\Protocol\Request;
use App\Engine\MCP\Protocol\Response;
use App\Engine\MCP\Resource\ResourceReader;
use App\Engine\MCP\Tool\ToolRunner;

/**
 * The MCP protocol: one message in, at most one message out.
 *
 * It knows nothing about bytes on a pipe or an HTTP body -- the transports do
 * -- and nothing about what any tool does. Its job is the method table below,
 * the order a session must follow, and the rule that every failure leaves as
 * an McpError:
 *
 *     initialize                   agree a protocol version; say what is offered
 *     ping                         answer {}
 *     tools/list, tools/call
 *     resources/list, resources/templates/list, resources/read
 *     prompts/list, prompts/get
 *
 * **Nothing but initialize and ping before initialize.** A client that skips it
 * has not agreed a version, and the answer to anything else is InvalidRequest.
 *
 * **Notifications are never answered**, as JSON-RPC requires, including when
 * they are wrong: there is nothing to answer them with.
 *
 * **An exception that is not an McpException is reported and answered as an
 * internal error.** The runners already do this for handlers; this is the net
 * under the protocol code itself.
 */
final class McpServer
{
    /** @param (\Closure(\Throwable): void)|null $report */
    public function __construct(
        private readonly MessageParser $parser,
        private readonly ToolRunner $tools,
        private readonly ResourceReader $resources,
        private readonly PromptProvider $prompts,
        private readonly McpRegistry $registry,
        private readonly string $name,
        private readonly string $version,
        private readonly ?\Closure $report = null,
        private readonly ?HookEngine $hooks = null,
    ) {}

    /** The JSON line to send back, or null when there is nothing to send. */
    public function handle(string $message, McpSession $session): ?string
    {
        try {
            $parsed = $this->parser->parse($message);
        } catch (ProtocolException $e) {
            return Response::error($e->requestId, $e->error())->toJson();
        }

        if ($parsed instanceof Notification) {
            return null;
        }

        return $this->answer($parsed, $session)->toJson();
    }

    /**
     * One request, with one context: the hooks and the capability see the
     * same request id, which is what ties a log line to an answer.
     *
     *     mcp.request.received   hook   the method and context, before anything
     *     mcp.request.failed     hook   the error the client is about to receive
     *     mcp.response.created   hook   the response, success or failure
     *
     * A listener that throws is a bug like any other: reported, and the client
     * gets an internal error. Nothing a listener throws escapes this method,
     * because over STDIO that would end the session.
     */
    private function answer(Request $request, McpSession $session): Response
    {
        $context = $session->context();

        try {
            $response = $this->respond($request, $session, $context);
            $this->hooks?->do('mcp.response.created', $response, $context);

            return $response;
        } catch (\Throwable $e) {
            $this->reportFailure($e);

            return Response::error($request->id, McpError::internal());
        }
    }

    private function respond(Request $request, McpSession $session, McpContext $context): Response
    {
        try {
            $this->hooks?->do('mcp.request.received', $request->method, $context);

            if (!$session->isInitialized() && !\in_array($request->method, ['initialize', 'ping'], true)) {
                throw ProtocolException::invalidRequest('the session has not been initialized', $request->id);
            }

            return Response::result($request->id, $this->dispatch($request, $session, $context));
        } catch (McpException $e) {
            $error = $e->error();
        } catch (\Throwable $e) {
            $this->reportFailure($e);
            $error = McpError::internal();
        }

        $this->hooks?->do('mcp.request.failed', $request->method, $error, $context);

        return Response::error($request->id, $error);
    }

    private function reportFailure(\Throwable $e): void
    {
        if ($this->report !== null) {
            ($this->report)($e);
        }
    }

    /** @return array<string, mixed> */
    private function dispatch(Request $request, McpSession $session, McpContext $context): array
    {
        $params = $request->params;

        return match ($request->method) {
            'initialize' => $this->initialize($params, $session),
            'ping' => [],
            'tools/list' => ['tools' => $this->tools->list($context)],
            'tools/call' => $this->tools->call(self::string($params, 'name', $request), $params['arguments'] ?? [], $context)->toArray(),
            'resources/list' => ['resources' => $this->resources->resources($context)],
            'resources/templates/list' => ['resourceTemplates' => $this->resources->templates($context)],
            'resources/read' => ['contents' => [$this->resources->read($params['uri'] ?? null, $context)->toArray()]],
            'prompts/list' => ['prompts' => $this->prompts->list($context)],
            'prompts/get' => $this->prompts->get(self::string($params, 'name', $request), $params['arguments'] ?? null, $context)->toArray(),
            default => throw ProtocolException::methodNotFound($request->method, $request->id),
        };
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function initialize(array $params, McpSession $session): array
    {
        $client = \is_array($params['clientInfo'] ?? null) ? $params['clientInfo'] : [];
        $version = ProtocolVersion::negotiate($params['protocolVersion'] ?? null);

        $session->initialize(
            $version,
            \is_string($client['name'] ?? null) ? $client['name'] : '',
            \is_string($client['version'] ?? null) ? $client['version'] : '',
        );

        // Only what exists is advertised; an empty object says "supported,
        // with no optional features".
        $capabilities = [];

        foreach (['tools' => CapabilityKind::Tool, 'resources' => CapabilityKind::Resource, 'prompts' => CapabilityKind::Prompt] as $key => $kind) {
            if ($this->registry->all($kind) !== []) {
                $capabilities[$key] = new \stdClass();
            }
        }

        return [
            'protocolVersion' => $version,
            'capabilities' => $capabilities === [] ? new \stdClass() : $capabilities,
            'serverInfo' => ['name' => $this->name, 'version' => $this->version],
        ];
    }

    /** @param array<string, mixed> $params */
    private static function string(array $params, string $key, Request $request): string
    {
        return \is_string($params[$key] ?? null) && $params[$key] !== ''
            ? $params[$key]
            : throw ProtocolException::invalidParams(\sprintf('"%s" is a non-empty string', $key), $request->id);
    }
}
