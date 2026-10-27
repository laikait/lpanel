<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Engine\Auth\Identity;
use App\Engine\Http\Response;
use App\Modules\Operations\OperationRequests;
use App\Modules\Operations\Store\Operation;
use App\Modules\Operations\Store\OperationStore;
use App\Modules\Servers\Agent\OperationKey;
use App\Modules\Servers\Agent\Protocol;
use App\Modules\Servers\Store\Server;

/**
 * A paired, online agent, and the means to give it work and check what it
 * is handed: claimed operations are verified with the panel's public key,
 * over their exact bytes, as the Go agent verifies them.
 */
abstract class OperationsTestCase extends ServersTestCase
{
    /**
     * An agent that has paired and sent a heartbeat listing $capabilities.
     *
     * @param array<string, list<int>> $capabilities
     * @param list<int>                $protocols
     */
    protected function onlineAgent(string $cert = 'agent-a', array $capabilities = FakeAgent::ALL_OPS, array $protocols = [1]): FakeAgent
    {
        $agent = $this->enrolledAgent($cert);
        self::assertSame(200, $agent->heartbeat([], ['capabilities' => $capabilities, 'protocols' => $protocols])->status());

        return $agent;
    }

    protected function store(): OperationStore
    {
        return $this->service(OperationStore::class);
    }

    /** Queue $op for $server (the first server by default), as the console. */
    protected function queue(string $op, ?Server $server = null): Operation
    {
        [$queued] = $this->service(OperationRequests::class)->request(
            new Identity('console', 'Panel console', [], ['system' => true]),
            $server ?? $this->server(),
            $op,
            null,
            new \stdClass(),
        );

        return $queued;
    }

    protected function reload(Operation $op): Operation
    {
        return $this->store()->byUid($op->uid) ?? self::fail('The operation is gone.');
    }

    /**
     * What a claim handed out: each signed operation verified, then decoded.
     *
     * @return list<array{raw: string, op: array<string, mixed>}>
     */
    protected function claimed(Response $response): array
    {
        self::assertSame(200, $response->status(), $response->body());
        $body = \json_decode($response->body(), true);
        self::assertIsArray($body);
        self::assertSame(['operations'], \array_keys($body));
        self::assertIsArray($body['operations']);

        $public = Protocol::unb64($this->service(OperationKey::class)->publicKey()) ?? '';
        $out = [];

        foreach ($body['operations'] as $signed) {
            self::assertIsArray($signed);
            self::assertSame(['operation', 'kid', 'signature'], \array_keys($signed));
            self::assertSame(Protocol::keyId($public), $signed['kid']);
            $raw = Protocol::unb64((string) $signed['operation']) ?? '';
            self::assertTrue(Protocol::verify($public, "LPA-OP-v1\n" . $raw, (string) $signed['signature']), 'The operation signature does not verify.');
            $op = \json_decode($raw, true, 32, \JSON_THROW_ON_ERROR);
            self::assertIsArray($op);
            /** @var array<string, mixed> $op */
            $out[] = ['raw' => $raw, 'op' => $op];
        }

        return $out;
    }

    /** A real result of $op, from the agent's catalogue file (made by the Go tests). */
    protected static function example(string $op): \stdClass
    {
        $file = \json_decode((string) \file_get_contents(\dirname(__DIR__) . '/Fixtures/lpa/catalogue.json'), false, 32, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $file);

        foreach ($file->operations as $entry) {
            if ($entry->op === $op) {
                self::assertInstanceOf(\stdClass::class, $entry->example_result);

                return $entry->example_result;
            }
        }

        self::fail('The catalogue has no ' . $op);
    }

    /** @param list<string> $expected */
    protected function assertHistory(Operation $op, array $expected): void
    {
        self::assertSame($expected, \array_map(
            static fn(array $e): string => ($e['from_status'] ?? '-') . '>' . $e['to_status'],
            $this->store()->events($op),
        ));
    }
}
