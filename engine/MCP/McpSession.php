<?php

declare(strict_types=1);

namespace App\Engine\MCP;

use App\Engine\Auth\Identity;
use App\Engine\MCP\Protocol\ProtocolVersion;

/**
 * One client's connection: who it authenticated as, over what, and what was
 * agreed in `initialize`.
 *
 * A STDIO session is the life of the process; an HTTP session is one request.
 * Either way the identity is fixed by the transport before any message is
 * handled -- nothing a client sends can change it.
 */
final class McpSession
{
    private bool $initialized = false;

    private string $protocolVersion = ProtocolVersion::LATEST;

    private string $clientName = '';

    private string $clientVersion = '';

    public function __construct(
        public readonly Identity $identity,
        public readonly string $transport,
    ) {
        if (!\in_array($transport, McpContext::TRANSPORTS, true)) {
            throw McpContractException::unknownTransport($transport);
        }
    }

    public function initialize(string $protocolVersion, string $clientName, string $clientVersion): void
    {
        $this->initialized = true;
        $this->protocolVersion = $protocolVersion;
        $this->clientName = \mb_substr($clientName, 0, 100);
        $this->clientVersion = \mb_substr($clientVersion, 0, 50);
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function protocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /** A context for one request, with an id of its own. */
    public function context(): McpContext
    {
        return new McpContext(
            \bin2hex(\random_bytes(8)),
            $this->identity,
            $this->transport,
            $this->protocolVersion,
            $this->clientName,
            $this->clientVersion,
        );
    }
}
