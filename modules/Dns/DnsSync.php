<?php

declare(strict_types=1);

namespace App\Modules\Dns;

use App\Modules\Accounts\Accounts;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;

/**
 * Keeps each zone on the server in step with the panel's records, the way
 * WebSync does for sites: the agent takes a zone's whole state at once
 * (dns.apply); a changed state gets a new revision and operation id,
 * recorded before it is sent; dns:settle sends it again until the agent
 * answers, and the agent's journal runs it once.
 *
 * A zone being removed stays as a row ("removing") until the agent has
 * deleted it, so a removal the agent missed is not forgotten.
 */
final class DnsSync
{
    public const PENDING = 'pending';
    public const APPLIED = 'applied';
    public const FAILED = 'failed';
    public const REMOVING = 'removing';

    /** Agent answers that mean "not now". */
    private const TRANSIENT = ['busy', 'stale', 'bad_signature'];

    public function __construct(
        private readonly DnsZones $zones,
        private readonly Accounts $accounts,
        private readonly AgentClient $agent,
    ) {}

    /** Bring one zone in step. */
    public function touch(int $zoneId, bool $force = false): DnsOutcome
    {
        $zone = $this->zones->zone($zoneId);

        if ($zone === null || $zone['state'] === self::REMOVING) {
            return DnsOutcome::skipped();
        }

        $args = \json_encode($this->zones->state($zone), \JSON_THROW_ON_ERROR);

        if ($zone['op_args'] === $args && $zone['state'] === self::APPLIED && !$force) {
            return DnsOutcome::done();
        }

        if ($zone['op_args'] !== $args || $zone['state'] === self::FAILED || $zone['op_id'] === null || $force) {
            $this->zones->updateZone($zoneId, [
                'revision' => (int) $zone['revision'] + 1,
                'op_id' => Protocol::newId(),
                'op_args' => $args,
                'state' => self::PENDING,
                'error' => null,
                'changed_at' => Accounts::now(),
            ]);
            $zone = $this->zones->zone($zoneId) ?? throw new \LogicException('zone vanished');
        }

        return $this->send($zone);
    }

    /**
     * Make an account's zones and records match its domains, and send what
     * changed. Accounts being made or removed are left alone.
     *
     * @return array<string, int> outcome => count
     */
    public function reconcile(int $accountId): array
    {
        $account = $this->accounts->byId($accountId);
        $counts = [];

        if ($account === null || !\in_array($account['status'], [Accounts::ACTIVE, Accounts::SUSPENDED], true)) {
            return $counts;
        }

        $result = $this->zones->reconcile($account);

        foreach ($result['removed'] as $zone) {
            $status = $this->remove($zone)->status;
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        foreach ($result['touched'] as $zoneId) {
            $status = $this->touch($zoneId)->status;
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Keep another module's records for one of an account's domains in its
     * zone (DnsZones::provide), and send the zone when they changed.
     *
     * @param array<string, mixed>                                                $domain  a row of domains
     * @param list<array{name: string, type: string, ttl: int, content: string}> $records names in full
     */
    public function provide(array $domain, string $source, array $records): DnsOutcome
    {
        $zoneId = $this->zones->provide($domain, $source, $records);

        return $zoneId === null ? DnsOutcome::skipped() : $this->touch($zoneId);
    }

    /**
     * Send every zone again whose state changed: after nameservers, the
     * server's addresses or a template's glue changed.
     *
     * @return array<string, int> outcome => count
     */
    public function touchAll(bool $force = false): array
    {
        $counts = [];

        foreach ($this->zones->all() as $zone) {
            $status = $this->touch((int) $zone['id'], $force)->status;
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Remove a zone on the server, then here. If the agent cannot be
     * reached, the zone waits as "removing" for dns:settle.
     *
     * @param array<string, mixed> $zone
     */
    public function remove(array $zone): DnsOutcome
    {
        $id = (int) $zone['id'];

        if ($zone['state'] !== self::REMOVING) {
            $account = $zone['account_id'] === null ? null : $this->accounts->byId((int) $zone['account_id']);
            $this->zones->updateZone($id, [
                'state' => self::REMOVING,
                'op_id' => Protocol::newId(),
                'op_args' => \json_encode(['username' => $account === null ? '' : (string) $account['username'], 'zone' => (string) $zone['name']], \JSON_THROW_ON_ERROR),
                'error' => null,
                'changed_at' => Accounts::now(),
            ]);
            $zone = $this->zones->zone($id) ?? throw new \LogicException('zone vanished');
        }

        try {
            $reply = $this->agent->call('dns.remove', (array) \json_decode((string) $zone['op_args'], true), id: (string) $zone['op_id'], timeout: 60.0);
        } catch (AgentUnavailable $e) {
            return DnsOutcome::pending($e->getMessage());
        }

        if (!$reply->done()) {
            if ($reply->status === 'refused' && \in_array($reply->errorCode, self::TRANSIENT, true)) {
                return DnsOutcome::pending((string) $reply->errorMessage);
            }

            // A server without DNS has no zone to remove.
            if ($reply->errorCode !== 'dns_off') {
                $this->zones->updateZone($id, ['error' => ($reply->errorCode ?? 'failed') . ': ' . ($reply->errorMessage ?? '')]);

                return DnsOutcome::failed($reply->errorCode ?? 'failed', $reply->errorMessage ?? 'The agent gave no reason.');
            }
        }

        $this->zones->deleteZone($id);

        return DnsOutcome::done();
    }

    /**
     * Send every state and removal the agent has not answered yet.
     *
     * @return array<string, int> outcome => count
     */
    public function settleAll(): array
    {
        $counts = [];

        foreach ($this->zones->all() as $zone) {
            $outcome = match ($zone['state']) {
                self::PENDING => $zone['op_id'] === null ? $this->touch((int) $zone['id']) : $this->send($zone),
                self::REMOVING => $this->remove($zone),
                default => null,
            };

            if ($outcome !== null) {
                $counts[$outcome->status] = ($counts[$outcome->status] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /** @param array<string, mixed> $zone */
    private function send(array $zone): DnsOutcome
    {
        try {
            $reply = $this->agent->call('dns.apply', (array) \json_decode((string) $zone['op_args'], true), id: (string) $zone['op_id'], timeout: 60.0);
        } catch (AgentUnavailable $e) {
            return DnsOutcome::pending($e->getMessage());
        }

        if ($reply->status === 'refused' && \in_array($reply->errorCode, self::TRANSIENT, true)) {
            return DnsOutcome::pending((string) $reply->errorMessage);
        }

        if ($reply->status === 'accepted' || $reply->status === 'running') {
            return DnsOutcome::pending();
        }

        // A newer revision may have been recorded meanwhile; only the answer
        // for the current one changes the state.
        $current = $this->zones->zone((int) $zone['id']);

        if ($current === null || $current['op_id'] !== $zone['op_id']) {
            return $reply->done() ? DnsOutcome::done() : DnsOutcome::failed($reply->errorCode ?? 'failed', $reply->errorMessage ?? '');
        }

        if (!$reply->done()) {
            $code = $reply->errorCode ?? 'failed';
            $message = $reply->errorMessage ?? 'The agent gave no reason.';
            $this->zones->updateZone((int) $zone['id'], ['state' => self::FAILED, 'error' => $code . ': ' . $message]);

            return DnsOutcome::failed($code, $message);
        }

        $this->zones->updateZone((int) $zone['id'], [
            'state' => self::APPLIED,
            'applied_revision' => (int) $zone['revision'],
            'error' => null,
            'applied_at' => Accounts::now(),
        ]);

        return DnsOutcome::done();
    }
}
