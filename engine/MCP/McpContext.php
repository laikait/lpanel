<?php

declare(strict_types=1);

namespace App\Engine\MCP;

use App\Engine\Auth\Identity;

/**
 * Who is asking, over what, as part of which request -- and nothing else.
 *
 * Built once per MCP request by the server and handed to the tool, resource or
 * prompt that answers it:
 *
 *     public function call(array $arguments, McpContext $context): ToolResult
 *     {
 *         $invoice = $this->invoices->void($context->identity, (int) $arguments['id']);
 *     }
 *
 * **Not a service locator.** It holds values, not the container, and a handler's
 * collaborators come through its constructor. The identity is here because an
 * application service needs it to authorize; the request id because a failure
 * the client is told nothing about has to be findable in the log.
 *
 * **Per request, never shared.** Each request gets its own, so one client's
 * identity cannot answer another's call, even in a long-running server that
 * serves many requests in one process.
 */
final class McpContext
{
    public const TRANSPORTS = ['stdio', 'http'];

    public function __construct(
        public readonly string $requestId,
        public readonly Identity $identity,
        public readonly string $transport,
        public readonly string $protocolVersion,
        /** The client's own name for itself, from initialize; not a credential. */
        public readonly string $clientName = '',
        public readonly string $clientVersion = '',
    ) {
        if (!\in_array($transport, self::TRANSPORTS, true)) {
            throw McpContractException::unknownTransport($transport);
        }

        if ($requestId === '') {
            throw McpContractException::emptyRequestId();
        }
    }

    /** @return array{request_id: string, transport: string, client: string, principal: ?string} for a log record */
    public function forLog(): array
    {
        return [
            'request_id' => $this->requestId,
            'transport' => $this->transport,
            'client' => \trim($this->clientName . ' ' . $this->clientVersion),
            'principal' => $this->identity->isGuest() ? null : $this->identity->id,
        ];
    }
}
