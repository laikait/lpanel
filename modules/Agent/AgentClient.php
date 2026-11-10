<?php

declare(strict_types=1);

namespace App\Modules\Agent;

/**
 * Asks lpa to do something.
 *
 * Every request is signed with the panel's key and carries an id. Pass the
 * same id again to retry the same work: the agent returns the first outcome
 * instead of doing it twice. A new piece of work gets a new id.
 */
final class AgentClient
{
    /**
     * Both are looked up on each call, not at construction: pages that never
     * ask the agent never read the key, and a test can swap either after boot.
     *
     * @param \Closure(): Transport $transport
     * @param \Closure(): AgentKey  $key
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly \Closure $transport,
        private readonly \Closure $key,
        private readonly ?\Closure $clock = null,
    ) {}

    /**
     * @param array<string, mixed> $args
     *
     * @throws AgentUnavailable when the agent could not be asked
     */
    public function call(string $op, array $args = [], ?string $id = null, float $timeout = 15.0): AgentReply
    {
        $key = ($this->key)();
        $id ??= Protocol::newId();
        $ts = $this->clock !== null ? ($this->clock)() : \time();
        $nonce = Protocol::newNonce();
        $encoded = Protocol::encodeArgs($args);
        $signature = $key->sign(Protocol::signedBytes($id, $op, $ts, $nonce, $encoded));

        return AgentReply::fromLine(($this->transport)()->exchange(Protocol::line($id, $op, $ts, $nonce, $encoded, $signature), $timeout));
    }

    /**
     * The outcome of an earlier request that answered "accepted".
     *
     * @throws AgentUnavailable
     */
    public function status(string $id): AgentReply
    {
        return $this->call('op.status', ['id' => $id], timeout: 5.0);
    }
}
