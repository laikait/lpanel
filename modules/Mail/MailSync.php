<?php

declare(strict_types=1);

namespace App\Modules\Mail;

use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Modules\Accounts\Accounts;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Dns\DnsOutcome;
use App\Modules\Dns\DnsSync;
use App\Modules\Packages\Packages;

/**
 * Keeps each account's mail on the server in step with the panel's tables,
 * the way DbSync does for databases: the agent takes the whole state at once
 * (mail.apply); a changed state gets a new revision and operation id,
 * recorded before it is sent; mail:settle sends it again until the agent
 * answers.
 *
 * Once the server has a state, the domains' DKIM keys it answers with are
 * kept, their DNS records are placed in the account's zones (DKIM, DMARC,
 * and SPF for subdomains), and mail waiting to be deleted is deleted.
 */
final class MailSync
{
    public const PENDING = 'pending';
    public const APPLIED = 'applied';
    public const FAILED = 'failed';

    /** Records placed in zones under this source (DnsZones::provide). */
    public const DNS_SOURCE = 'mail';

    public const DMARC = 'v=DMARC1; p=none';
    public const SPF = 'v=spf1 a mx ~all';

    /** Agent answers that mean "not now". */
    private const TRANSIENT = ['busy', 'stale', 'bad_signature'];

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Accounts $accounts,
        private readonly Packages $packages,
        private readonly Mailboxes $mail,
        private readonly AgentClient $agent,
        private readonly DnsSync $dns,
    ) {}

    /**
     * Bring an account's mail in step. Accounts being made or removed are
     * left alone: their operation in flight comes first.
     */
    public function touch(int $accountId, bool $force = false): MailOutcome
    {
        $account = $this->accounts->byId($accountId);

        if ($account === null || !\in_array($account['status'], [Accounts::ACTIVE, Accounts::SUSPENDED], true)) {
            return MailOutcome::skipped();
        }

        $row = $this->row($accountId);
        $args = \json_encode($this->mail->state($account, $this->packages->byId((int) $account['package_id'])), \JSON_THROW_ON_ERROR);

        if ($row['op_args'] === $args && $row['state'] === self::APPLIED && !$force) {
            return MailOutcome::done();
        }

        if ($row['op_args'] !== $args || $row['state'] === self::FAILED || $force) {
            $this->table()->where('id', (int) $row['id'])->update([
                'revision' => (int) $row['revision'] + 1,
                'op_id' => Protocol::newId(),
                'op_args' => $args,
                'state' => self::PENDING,
                'error' => null,
                'changed_at' => Accounts::now(),
            ]);
            $row = $this->row($accountId);
        }

        return $this->send($row);
    }

    /**
     * Send every state the agent has not answered yet, then delete the mail
     * that waits for it.
     *
     * @return array<string, int> outcome => count
     */
    public function settleAll(): array
    {
        $counts = [];

        foreach ($this->table()->where('state', self::PENDING)->get() as $row) {
            $outcome = $this->send($row);
            $counts[$outcome->status] = ($counts[$outcome->status] ?? 0) + 1;
        }

        $accounts = [];

        foreach ($this->mail->purges() as $purge) {
            $accounts[(int) $purge['account_id']] = true;
        }

        foreach (\array_keys($accounts) as $accountId) {
            $counts['purged'] = ($counts['purged'] ?? 0) + $this->purge($accountId);
        }

        return $counts;
    }

    /** True when the server has the account's latest state (or it has none to have). */
    public function settled(int $accountId): bool
    {
        $row = $this->stateOf($accountId);

        return $row === null || $row['state'] !== self::PENDING;
    }

    /** @return array<string, mixed>|null */
    public function stateOf(int $accountId): ?array
    {
        return $this->table()->where('account_id', $accountId)->first();
    }

    /** @return list<array<string, mixed>> state rows in one state */
    public function rowsIn(string $state): array
    {
        return $this->table()->where('state', $state)->get();
    }

    /** Forget a terminated account; the agent removed its mail itself. */
    public function forget(int $accountId): void
    {
        $this->mail->deleteAccount($accountId);
        $this->table()->where('account_id', $accountId)->delete();
    }

    /**
     * Place the DNS records the account's mail domains need, in the zones
     * on this server that hold them. A domain without email in its package
     * gets none (any placed before go).
     *
     * @return int zones changed
     */
    public function publish(int $accountId): int
    {
        $account = $this->accounts->byId($accountId);

        if ($account === null) {
            return 0;
        }

        $package = $this->packages->byId((int) $account['package_id']);
        $email = $package === null || Packages::has($package, 'email');
        $changed = 0;

        foreach ($this->mail->domains($accountId) as $d) {
            $domain = $this->connections->connection()->table('domains')->where('id', (int) $d['domain_id'])->first();

            if ($domain === null) {
                continue;
            }

            $records = $email ? self::records($d, (string) $domain['kind']) : [];

            if ($this->dns->provide($domain, self::DNS_SOURCE, $records)->status !== DnsOutcome::SKIPPED) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * The records a mail domain needs: its DKIM key, a DMARC policy (not for
     * subdomains, which take their domain's), and SPF for a subdomain (the
     * zone's template gives the domain its own).
     *
     * @param array<string, mixed> $domain a row of mail_domains
     *
     * @return list<array{name: string, type: string, ttl: int, content: string}>
     */
    public static function records(array $domain, string $kind): array
    {
        $name = (string) $domain['name'];
        $records = [];

        if (($domain['dkim'] ?? null) !== null && $domain['dkim'] !== '') {
            $records[] = ['name' => 'default._domainkey.' . $name, 'type' => 'TXT', 'ttl' => 3600, 'content' => (string) $domain['dkim']];
        }

        if ($kind === 'sub') {
            $records[] = ['name' => $name, 'type' => 'TXT', 'ttl' => 3600, 'content' => self::SPF];
        } else {
            $records[] = ['name' => '_dmarc.' . $name, 'type' => 'TXT', 'ttl' => 3600, 'content' => self::DMARC];
        }

        return $records;
    }

    /**
     * Delete the account's mail waiting to go, once the server has its
     * latest state (until then a mailbox may still be set up there).
     *
     * @return int purges done
     */
    public function purge(int $accountId): int
    {
        $row = $this->stateOf($accountId);
        $account = $this->accounts->byId($accountId);

        if ($row === null || $row['state'] !== self::APPLIED || $account === null) {
            return 0;
        }

        $done = 0;

        foreach ($this->mail->purges($accountId) as $purge) {
            try {
                $reply = $this->agent->call('mail.purge', ['username' => (string) $account['username'], 'domain' => (string) $purge['domain'], 'local' => (string) $purge['local']], id: Protocol::newId(), timeout: 300.0);
            } catch (AgentUnavailable) {
                break;
            }

            // Done, or set up again since (its mail is someone's again): either way, not to delete.
            if ($reply->done() || $reply->errorCode === 'mail_in_use' || $reply->errorCode === 'bad_args') {
                $this->mail->purged((int) $purge['id']);
                $done++;
            }
        }

        return $done;
    }

    /** @param array<string, mixed> $row */
    private function send(array $row): MailOutcome
    {
        $args = \json_decode((string) $row['op_args'], true);

        try {
            $reply = $this->agent->call('mail.apply', \is_array($args) ? $args : [], id: (string) $row['op_id'], timeout: 150.0);
        } catch (AgentUnavailable $e) {
            return MailOutcome::pending($e->getMessage());
        }

        if ($reply->status === 'refused' && \in_array($reply->errorCode, self::TRANSIENT, true)) {
            return MailOutcome::pending((string) $reply->errorMessage);
        }

        if ($reply->status === 'accepted' || $reply->status === 'running') {
            return MailOutcome::pending();
        }

        $current = $this->table()->where('id', (int) $row['id'])->where('op_id', (string) $row['op_id']);

        if (!$reply->done()) {
            $code = $reply->errorCode ?? 'failed';
            $message = $reply->errorMessage ?? 'The agent gave no reason.';
            $current->update(['state' => self::FAILED, 'error' => $code . ': ' . $message]);

            return MailOutcome::failed($code, $message);
        }

        $current->update([
            'state' => self::APPLIED,
            'applied_revision' => (int) $row['revision'],
            'error' => null,
            'applied_at' => Accounts::now(),
        ]);

        $accountId = (int) $row['account_id'];

        foreach (\is_array($reply->result['dkim'] ?? null) ? $reply->result['dkim'] : [] as $name => $key) {
            $domain = $this->mail->domain($accountId, (string) $name);

            if ($domain !== null && \is_string($key) && $domain['dkim'] !== $key) {
                $this->mail->updateDomain((int) $domain['id'], ['dkim' => $key]);
            }
        }

        $this->publish($accountId);
        $this->purge($accountId);

        return MailOutcome::done($reply->result);
    }

    /** @return array<string, mixed> */
    private function row(int $accountId): array
    {
        $row = $this->stateOf($accountId);

        if ($row === null) {
            $this->table()->insert(['account_id' => $accountId, 'revision' => 0, 'applied_revision' => 0, 'state' => self::APPLIED]);
            $row = $this->stateOf($accountId) ?? throw new \LogicException('mail state vanished');
        }

        return $row;
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('mail_accounts');
    }
}
