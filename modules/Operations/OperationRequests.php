<?php

declare(strict_types=1);

namespace App\Modules\Operations;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Config\Config;
use App\Modules\Audit\AuditLog;
use App\Modules\Operations\Catalogue\Catalogue;
use App\Modules\Operations\Catalogue\Definition;
use App\Modules\Operations\Store\Operation;
use App\Modules\Operations\Store\OperationStore;
use App\Modules\Servers\Agent\AgentSettings;
use App\Modules\Servers\Agent\Protocol;
use App\Modules\Servers\Store\Server;

/**
 * Asking an agent for something, and calling it off: the checks before an
 * operation is queued, and the audit record of who asked.
 *
 * Refused at creation, not queued to fail later (ARCHITECTURE.md §2,
 * AGENT_PROTOCOL.md §9): an operation this panel does not define, a person
 * without its capability, a payload that does not fit, a server that is not
 * online, and an agent that does not speak the protocol or lacks the operation.
 */
final class OperationRequests
{
    public function __construct(
        private readonly Catalogue $catalogue,
        private readonly OperationStore $operations,
        private readonly AgentSettings $agents,
        private readonly Authorizer $authorizer,
        private readonly AuditLog $audit,
        private readonly Config $config,
    ) {}

    /**
     * Queue $op for $server's agent. A read-only request already queued or
     * running for the same server is returned instead of a second one.
     *
     * @param mixed                                 $payload as json_decode(..., false) gives it: \stdClass for an object
     * @param array{type: string, uid: string}|null $target  what it acts on, for an operation whose scope is not the server
     *
     * @return array{Operation, bool} the operation, and whether it is new
     *
     * @throws OperationException
     */
    public function request(Identity $by, Server $server, string $op, ?int $version, mixed $payload, ?array $target = null): array
    {
        $definition = $version === null ? $this->catalogue->latest($op) : $this->catalogue->find($op, $version);

        if ($definition === null) {
            throw new OperationException('unknown_operation', \sprintf('The panel has no operation "%s"%s.', \substr($op, 0, 100), $version === null ? '' : ' v' . $version), 422);
        }

        if (!self::isConsole($by) && !$this->authorizer->allows($by, $definition->capability)) {
            $this->audit->record('operation.request', 'denied', $by, 'server', $server->uid, ['op' => $definition->op]);

            throw new OperationException('forbidden', 'You may not ask for this operation.', 403);
        }

        if (($target === null) !== ($definition->scope === 'server') || ($target !== null && $target['type'] !== $definition->scope)) {
            throw new OperationException('payload_invalid', \sprintf('%s acts on a %s, so it is asked for from that %s\'s screen.', $definition->op, $definition->scope, $definition->scope), 422);
        }

        $invalid = $definition->payload->check($payload, 'payload');

        if ($invalid !== null) {
            throw new OperationException('payload_invalid', \sprintf('The payload does not fit %s: %s.', $definition->op, $invalid), 422);
        }

        $this->ready($server, $definition);

        if (!$definition->mutating) {
            $live = $this->operations->liveFor($server, $definition);

            if ($live !== null) {
                return [$live, false];
            }
        }

        $payloadJson = \json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        $queued = $this->operations->transaction(function () use ($by, $server, $definition, $payloadJson, $target): Operation {
            $queued = $this->operations->queue(
                $server,
                $definition,
                $payloadJson,
                self::isConsole($by) ? null : $by->id,
                $by->name,
                self::isConsole($by) ? 'system' : 'user',
                $this->ttl(),
                $target,
            );
            $this->audit->record('operation.request', 'succeeded', $by, 'operation', $queued->uid, \array_filter([
                'op' => $definition->op . '@' . $definition->version,
                'server' => $server->uid,
                $target === null ? '' : $target['type'] => $target['uid'] ?? null,
            ]));

            return $queued;
        });

        return [$queued, true];
    }

    /**
     * Cancel $op: at once if no agent has it, else at its agent's next lease
     * extension. Who may ask for an operation may also call it off.
     *
     * @return string 'cancelled' or 'requested'
     *
     * @throws OperationException
     */
    public function cancel(Identity $by, Operation $op): string
    {
        $definition = $this->catalogue->find($op->op, $op->opVersion);

        if (!self::isConsole($by) && ($definition === null || !$this->authorizer->allows($by, $definition->capability))) {
            throw new OperationException('forbidden', 'You may not cancel this operation.', 403);
        }

        $done = $this->operations->cancel($op, self::isConsole($by) ? 'system' : 'user');

        if ($done === 'final') {
            throw new OperationException('operation_final', 'The operation has already finished.', 409);
        }

        $this->audit->record('operation.cancel', 'succeeded', $by, 'operation', $op->uid, ['op' => $op->op, 'how' => $done]);

        return $done;
    }

    /** Seconds a queued operation waits for its agent before it expires. */
    public function ttl(): int
    {
        return \max(60, \min(86400, $this->config->int('Operations.ttl', 600) ?? 600));
    }

    /** @throws OperationException */
    private function ready(Server $server, Definition $definition): void
    {
        $status = $server->status($this->agents->offlineAfter());
        $refusal = match ($status) {
            'pending' => ['server_not_paired', 'This server has no agent yet. Pair it first.'],
            'revoked' => ['agent_revoked', 'This server\'s agent is revoked.'],
            'offline' => ['server_offline', 'This server\'s agent has not checked in recently, so the operation would only wait. Check the agent first.'],
            default => null,
        };

        if ($refusal === null && !\in_array(Protocol::VERSION, $server->protocols, true)) {
            $refusal = ['unsupported_protocol', \sprintf('The agent does not speak protocol version %d.', Protocol::VERSION)];
        }

        if ($refusal === null && !isset($server->capabilities[$definition->op])) {
            $refusal = ['unknown_operation', \sprintf('The agent on this server has no operation %s. Upgrade the agent.', $definition->op)];
        }

        if ($refusal === null && !\in_array($definition->version, $server->capabilities[$definition->op], true)) {
            $refusal = ['unsupported_op_version', \sprintf('The agent on this server has no version %d of %s. Upgrade the agent.', $definition->version, $definition->op)];
        }

        if ($refusal !== null) {
            throw new OperationException($refusal[0], $refusal[1], 409);
        }
    }

    /** The panel's own console (`php laika`), which has no capabilities and needs none. */
    private static function isConsole(Identity $identity): bool
    {
        return $identity->attribute('system') === true;
    }
}
