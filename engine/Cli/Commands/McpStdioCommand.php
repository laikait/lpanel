<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Auth\AuthManager;
use App\Engine\Auth\Identity;
use App\Engine\Cli\Output;
use App\Engine\MCP\McpConfig;
use App\Engine\MCP\McpSession;
use App\Engine\MCP\Transport\StdioTransport;

/**
 * Serve MCP over this process's stdin and stdout, for a local client.
 *
 *     php laika mcp:stdio --user=ada
 *
 * The MCP client starts this command and talks to it through the pipe. It acts
 * as the user named here -- looked up through the application's user provider,
 * by login or id, and refused if inactive -- for the whole session. With no
 * user it acts as a guest, and a guest is refused everything unless the
 * application allows guests. The person who configured the client chose the
 * user, which is the same trust as running any other console command as them.
 *
 * stdout is the protocol, so this command writes nothing of its own there;
 * a failure to start goes to stderr. It refuses to start while MCP or its
 * STDIO transport is switched off in config/mcp.php.
 */
final class McpStdioCommand
{
    public function __construct(
        private readonly StdioTransport $transport,
        private readonly AuthManager $auth,
        private readonly McpConfig $config,
    ) {}

    public function __invoke(Output $output, ?string $user = null): int
    {
        if (!$this->config->stdioEnabled()) {
            $output->error('MCP over STDIO is switched off: see mcp.enabled and mcp.transports.stdio.');

            return 1;
        }

        $identity = $this->identity($user);

        if ($identity === null) {
            // Not which of the two: that would say whether the account exists.
            $output->error(\sprintf('No active user "%s" to serve MCP as.', (string) $user));

            return 1;
        }

        $this->transport->run(\STDIN, \STDOUT, new McpSession($identity, 'stdio'));

        return 0;
    }

    private function identity(?string $user): ?Identity
    {
        if ($user === null || $user === '') {
            return Identity::guest();
        }

        $provider = $this->auth->provider();
        $account = $provider->byLogin($user) ?? $provider->byId($user);

        return $account !== null && $account->active ? $account->identity : null;
    }
}
