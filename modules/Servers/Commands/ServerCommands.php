<?php

declare(strict_types=1);

namespace App\Modules\Servers\Commands;

use App\Engine\Auth\Identity;
use App\Engine\Cli\Output;
use App\Modules\Audit\AuditLog;
use App\Modules\Servers\Agent\AgentSettings;
use App\Modules\Servers\Agent\OperationKey;
use App\Modules\Servers\Agent\PanelEndpoint;
use App\Modules\Servers\Store\Server;
use App\Modules\Servers\Store\ServerStore;

/**
 * servers:add, servers:list, servers:rotate, servers:revoke -- the server
 * screens' levers, for an administrator with a shell on the panel host (and
 * for the end-to-end test, which has no browser). Audited as the console.
 */
final class ServerCommands
{
    public function __construct(
        private readonly ServerStore $servers,
        private readonly AgentSettings $settings,
        private readonly PanelEndpoint $endpoint,
        private readonly OperationKey $operationKey,
        private readonly AuditLog $audit,
    ) {}

    /** A new server and its pairing command. */
    public function add(Output $output, string $name, bool $token = false): int
    {
        $problems = $this->endpoint->problems($this->operationKey);

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $output->line($problem);
            }

            return 1;
        }

        if (\preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._-]{0,99}$/u', $name) !== 1) {
            $output->line('A server name is up to 100 letters, digits, spaces, ".", "_" or "-".');

            return 1;
        }

        $server = $this->servers->create($name, null);
        $secret = $this->servers->createEnrollment($server, null, $this->settings->enrollmentMinutes());
        $this->audit->record('server.create', 'succeeded', self::console(), 'server', $server->uid, ['name' => $name]);
        $this->audit->record('agent.enrollment_create', 'succeeded', self::console(), 'server', $server->uid);

        if ($token) {
            $output->line($secret);

            return 0;
        }

        $output->line(\sprintf('Server %s (%s). Run this on it, as root, within %d minutes:', $name, $server->uid, $this->settings->enrollmentMinutes()));
        $output->line(\sprintf('  sudo lpa enroll --panel %s --pin %s --token %s', (string) $this->endpoint->url(), (string) $this->endpoint->pin(), $secret));

        return 0;
    }

    public function list(Output $output, bool $json = false): int
    {
        $offline = $this->settings->offlineAfter();
        $servers = $this->servers->all();

        if ($json) {
            $output->line(\json_encode(\array_map(fn(Server $s): array => $s->toArray($offline) + [
                'keys' => \array_map(static fn($c): array => ['kid' => $c->kid, 'state' => $c->state], $this->servers->credentials($s)),
                'rotate_requested' => $s->rotateRequested,
            ], $servers), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

            return 0;
        }

        foreach ($servers as $s) {
            $output->line(\sprintf('%-26s %-20s %-8s %-24s %s', $s->uid, $s->name, $s->status($offline), (string) $s->hostname, (string) $s->lastSeenAt));
        }

        return 0;
    }

    public function rotate(Output $output, string $server): int
    {
        $found = $this->find($output, $server);

        if ($found === null || $found->state !== Server::ACTIVE) {
            $output->line('Only a server with a paired agent can rotate its key.');

            return 1;
        }

        $this->servers->requestRotation($found);
        $this->audit->record('agent.rotation_requested', 'succeeded', self::console(), 'server', $found->uid);
        $output->line('The agent will replace its key at its next heartbeat.');

        return 0;
    }

    public function revoke(Output $output, string $server): int
    {
        $found = $this->find($output, $server);

        if ($found === null) {
            return 1;
        }

        $this->servers->revoke($found);
        $this->audit->record('agent.revoke', 'succeeded', self::console(), 'server', $found->uid, ['agent_id' => $found->agentId]);
        $output->line('Revoked. The agent is refused from its next request.');

        return 0;
    }

    /** By id, or by name when exactly one server has it. */
    private function find(Output $output, string $server): ?Server
    {
        $found = $this->servers->byUid($server);

        if ($found !== null) {
            return $found;
        }

        $named = \array_values(\array_filter($this->servers->all(), static fn(Server $s): bool => $s->name === $server));

        if (\count($named) !== 1) {
            $output->line(\count($named) === 0 ? 'No such server.' : 'More than one server has that name; use its id.');

            return null;
        }

        return $named[0];
    }

    private static function console(): Identity
    {
        return new Identity('console', 'Panel console', [], ['system' => true]);
    }
}
