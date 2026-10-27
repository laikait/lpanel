<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Modules\Audit\AuditLog;
use App\Modules\Operations\Catalogue\Catalogue;
use App\Modules\Operations\Catalogue\Definition;
use App\Modules\Operations\Store\Operation;
use App\Modules\Operations\Store\OperationStore;
use App\Modules\Servers\Agent\AgentException;
use App\Modules\Servers\Agent\OperationKey;
use App\Modules\Servers\Agent\Protocol;
use App\Modules\Servers\Agent\RequestVerifier;
use App\Modules\Servers\Agent\StrictJson;
use App\Modules\Servers\Store\Server;
use App\Modules\Servers\Store\ServerStore;

/**
 * POST /agent/v1/operations/claim, /{id}/lease and /{id}/result
 * (AGENT_PROTOCOL.md §4, §6-7).
 *
 * Every request passes the §3 checks first (RequestVerifier). An agent only
 * ever sees its own server's operations: another server's id is answered as
 * if it did not exist. Answers are bare JSON, as the agent decodes them.
 */
final class AgentOperationsApi
{
    private const RESULT_FIELDS = ['operation_id', 'attempt', 'status', 'error_code', 'message', 'result', 'started_at', 'finished_at', 'agent_version', 'truncated'];

    private const STATUSES = [Operation::SUCCEEDED, Operation::FAILED, Operation::NEEDS_ATTENTION];

    public function __construct(
        private readonly RequestVerifier $verifier,
        private readonly OperationStore $operations,
        private readonly ServerStore $servers,
        private readonly Catalogue $catalogue,
        private readonly OperationKey $key,
        private readonly AuditLog $audit,
    ) {}

    /** Up to `max` queued operations, each signed for the attempt it is now. */
    public function claim(Request $request): Response
    {
        return $this->guard($request, function () use ($request): Response {
            [$server] = $this->verifier->verifyAgent($request);
            $body = StrictJson::object($request->body(), ['max'], 'claim');
            $max = StrictJson::int($body['max'], 1, 10, 'max');

            // Before anything is claimed: a claim that cannot be signed would only lapse.
            if (!$this->key->exists()) {
                throw new AgentException('internal', 'the panel cannot sign operations', 503);
            }

            $signed = $this->operations->claim(
                $server,
                $max,
                fn(Operation $op, int $attempt, int $issuedAt): array => $this->key->sign($this->envelope($server, $op, $attempt, $issuedAt)),
            );

            return new JsonResponse(['operations' => $signed]);
        });
    }

    /** The agent is still running it; it learns here if it should stop. */
    public function lease(Request $request, string $id): Response
    {
        return $this->guard($request, function () use ($request, $id): Response {
            [$server] = $this->verifier->verifyAgent($request);
            $body = StrictJson::object($request->body(), ['attempt'], 'lease');
            $attempt = StrictJson::int($body['attempt'], 1, 1000, 'attempt');
            $op = $this->operations->forServer($server, $id) ?? throw self::notFound();

            return new JsonResponse($this->operations->lease($op, $attempt));
        });
    }

    /**
     * The final result. A succeeded result must match the operation's result
     * schema; one that does not is stored as failed / invalid_result, and
     * audited, since a correct agent never sends one (THREAT_MODEL T5).
     */
    public function result(Request $request, string $id): Response
    {
        return $this->guard($request, function () use ($request, $id): Response {
            [$server] = $this->verifier->verifyAgent($request);
            $body = StrictJson::object($request->body(), self::RESULT_FIELDS, 'result', 16);

            if (StrictJson::string($body['operation_id'], Protocol::UUID, 'operation_id') !== $id) {
                throw AgentException::badRequest('operation_id is not the one in the path');
            }

            $attempt = StrictJson::int($body['attempt'], 1, 1000, 'attempt');
            $status = $body['status'];

            if (!\is_string($status) || !\in_array($status, self::STATUSES, true)) {
                throw AgentException::badRequest('status must be succeeded, failed or needs_attention');
            }

            $code = $body['error_code'] === null ? null : StrictJson::string($body['error_code'], '/^[a-z_]{1,64}$/', 'error_code');

            if (($status === Operation::SUCCEEDED) !== ($code === null)) {
                throw AgentException::badRequest('error_code is null exactly when the operation succeeded');
            }

            $message = $body['message'];

            if (!\is_string($message) || \strlen($message) > 500 || \preg_match('/^[^\x00-\x08\x0b\x0c\x0e-\x1f\x7f]*$/u', $message) !== 1) {
                throw AgentException::badRequest('message must be text of at most 500 bytes');
            }

            $startedAt = StrictJson::int($body['started_at'], 0, \PHP_INT_MAX, 'started_at');
            $finishedAt = StrictJson::int($body['finished_at'], 0, \PHP_INT_MAX, 'finished_at');
            $agentVersion = StrictJson::string($body['agent_version'], '/^[0-9A-Za-z.+~_-]{1,32}$/', 'agent_version');

            if (!\is_bool($body['truncated'])) {
                throw AgentException::badRequest('truncated must be true or false');
            }

            $op = $this->operations->forServer($server, $id) ?? throw self::notFound();
            $definition = $this->catalogue->find($op->op, $op->opVersion);
            $resultJson = null;
            $invalid = null;

            if ($status === Operation::SUCCEEDED) {
                // Decoded again with objects kept as objects: {} and [] are not the same shape.
                $raw = \json_decode($request->body(), false, 16, \JSON_BIGINT_AS_STRING);
                $value = $raw instanceof \stdClass ? $raw->result : null;
                $resultJson = \json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION) ?: null;
                $invalid = match (true) {
                    $definition === null => 'this panel has no definition of the operation',
                    $body['truncated'] => 'the result is marked truncated',
                    $resultJson === null || \strlen($resultJson) > $definition->maxResultBytes => 'the result is larger than the operation allows',
                    default => $definition->result->check($value),
                };

                if ($invalid !== null) {
                    [$status, $code, $message, $resultJson] = [Operation::FAILED, 'invalid_result', \substr('The agent\'s result was refused: ' . $invalid . '.', 0, 500), null];
                }
            }

            $outcome = $this->operations->finish(
                $op,
                $attempt,
                $status,
                $code,
                $message === '' ? null : $message,
                $resultJson,
                $agentVersion,
                $startedAt,
                $finishedAt,
                function (Operation $done) use ($server, $definition, $invalid): void {
                    $this->afterResult($server, $done, $definition, $invalid);
                },
            );

            if ($outcome === 'conflict') {
                throw new AgentException('result_conflict', 'the operation is not awaiting this result', 409);
            }

            return new JsonResponse(['accepted' => true]);
        });
    }

    /** What a stored result changes, in the same transaction. */
    private function afterResult(Server $server, Operation $done, ?Definition $definition, ?string $invalid): void
    {
        $agent = new Identity((string) $server->agentId, 'Agent on ' . $server->name, [], ['agent' => true]);

        if ($done->status === Operation::SUCCEEDED && $done->op === 'system.info') {
            $info = $done->resultArray() ?? [];
            $os = \is_array($info['os'] ?? null) ? $info['os'] : [];
            $this->servers->recordFacts($server, [
                'hostname' => (string) ($info['hostname'] ?? ''),
                'os_id' => (string) ($os['id'] ?? ''),
                'os_version' => (string) ($os['version_id'] ?? ''),
                'kernel' => (string) ($info['kernel'] ?? ''),
            ]);
        }

        $notable = $invalid !== null
            || $done->status === Operation::NEEDS_ATTENTION
            || ($definition !== null && $definition->audit !== Definition::AUDIT_INFO);

        if ($notable) {
            $this->audit->record(
                $invalid !== null ? 'operation.invalid_result' : 'operation.finish',
                $done->status === Operation::SUCCEEDED ? 'succeeded' : 'failed',
                $agent,
                'operation',
                $done->uid,
                \array_filter(['op' => $done->op, 'server' => $server->uid, 'status' => $done->status, 'error_code' => $done->errorCode, 'reason' => $invalid]),
            );
        }
    }

    /** The bytes the panel signs and the agent verifies before it reads them (§6.1). */
    private function envelope(Server $server, Operation $op, int $attempt, int $issuedAt): string
    {
        return \json_encode([
            'v' => Protocol::VERSION,
            'operation_id' => $op->uid,
            'agent_id' => (string) $server->agentId,
            'op' => $op->op,
            'op_version' => $op->opVersion,
            'target' => ['server_id' => $op->targetUid],
            'idempotency_key' => $op->idempotencyKey,
            'attempt' => $attempt,
            'issued_at' => $issuedAt,
            'not_after' => (new \DateTimeImmutable($op->notAfter, new \DateTimeZone('UTC')))->getTimestamp(),
            'lease_seconds' => $op->leaseSeconds,
            'timeout_seconds' => $op->timeoutSeconds,
            // Decoded as objects, so an empty payload stays {}.
            'payload' => \json_decode($op->payload, false, 32, \JSON_THROW_ON_ERROR),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    private static function notFound(): AgentException
    {
        return new AgentException('operation_not_found', 'no such operation for this agent', 404);
    }

    private function guard(Request $request, \Closure $handler): Response
    {
        if (\strlen($request->body()) > Protocol::MAX_BODY) {
            return (new AgentException('bad_request', 'body too large', 413))->toResponse();
        }

        try {
            return $handler();
        } catch (AgentException $e) {
            return $e->toResponse();
        }
    }
}
