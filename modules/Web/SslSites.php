<?php

declare(strict_types=1);

namespace App\Modules\Web;

use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Modules\Accounts\Accounts;

/**
 * Each site's certificate and AutoSSL state (ssl_sites): the certificate
 * served now, known by its id in the agent's store, and the owner's two
 * choices, AutoSSL and sending plain HTTP to HTTPS.
 */
final class SslSites
{
    public const AUTO = 'auto';
    public const CUSTOM = 'custom';

    public function __construct(private readonly ConnectionManager $connections) {}

    /** @return array<int, array<string, mixed>> domain id => row */
    public function forAccount(int $accountId): array
    {
        $out = [];

        foreach ($this->table()->where('account_id', $accountId)->get() as $row) {
            $out[(int) $row['domain_id']] = self::decode($row);
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function forDomain(int $domainId): ?array
    {
        $row = $this->table()->where('domain_id', $domainId)->first();

        return $row === null ? null : self::decode($row);
    }

    /**
     * The row for a site, made with the defaults when there is none.
     *
     * @return array<string, mixed>
     */
    public function ensure(int $accountId, int $domainId): array
    {
        $row = $this->forDomain($domainId);

        if ($row === null) {
            $this->table()->insert(['account_id' => $accountId, 'domain_id' => $domainId, 'updated_at' => Accounts::now()]);
            $row = $this->forDomain($domainId) ?? throw new \LogicException('ssl row vanished');
        }

        return $row;
    }

    /** @param array<string, mixed> $changes */
    public function update(int $domainId, array $changes): void
    {
        foreach (['names', 'excluded'] as $json) {
            if (\array_key_exists($json, $changes) && \is_array($changes[$json])) {
                $changes[$json] = \json_encode(\array_values($changes[$json]), \JSON_THROW_ON_ERROR);
            }
        }

        $this->table()->where('domain_id', $domainId)->update($changes + ['updated_at' => Accounts::now()]);
    }

    /**
     * Record the certificate a site serves from now on.
     *
     * @param array<string, mixed> $info the agent's description (certs.Info)
     */
    public function install(int $accountId, int $domainId, array $info, string $source, ?string $authority): void
    {
        $this->ensure($accountId, $domainId);
        $this->update($domainId, [
            'cert_id' => (string) $info['id'],
            'source' => $source,
            'names' => \array_values(\array_map('strval', (array) ($info['names'] ?? []))),
            'issuer' => \mb_substr((string) ($info['issuer'] ?? ''), 0, 200),
            'serial' => \substr((string) ($info['serial'] ?? ''), 0, 64),
            'key_type' => \substr((string) ($info['key_type'] ?? ''), 0, 16),
            'self_signed' => (bool) ($info['self_signed'] ?? false),
            'authority' => $authority,
            'not_before' => \gmdate('Y-m-d H:i:s', (int) ($info['not_before'] ?? 0)),
            'not_after' => \gmdate('Y-m-d H:i:s', (int) ($info['not_after'] ?? 0)),
        ]);
    }

    /** The site serves no certificate any more. */
    public function clear(int $domainId): void
    {
        $this->update($domainId, [
            'cert_id' => null, 'source' => null, 'names' => null, 'issuer' => null, 'serial' => null,
            'key_type' => null, 'self_signed' => false, 'authority' => null, 'not_before' => null, 'not_after' => null,
        ]);
    }

    /**
     * What web.apply sends for each HTTPS site: the certificate and whether
     * HTTP goes to HTTPS. An expired certificate is not served: the site
     * stays reachable over HTTP until a new one comes.
     *
     * @return array<int, array{cert: string, force: bool}> domain id => tls
     */
    public function tlsFor(int $accountId, ?int $now = null): array
    {
        $now ??= \time();
        $out = [];

        foreach ($this->forAccount($accountId) as $domainId => $row) {
            if ($row['cert_id'] !== null && $row['not_after'] !== null && \strtotime($row['not_after'] . ' UTC') > $now) {
                $out[$domainId] = ['cert' => (string) $row['cert_id'], 'force' => (bool) $row['force_https']];
            }
        }

        return $out;
    }

    /** Forget a domain or a whole account. */
    public function forgetDomain(int $domainId): void
    {
        $this->table()->where('domain_id', $domainId)->delete();
    }

    public function forgetAccount(int $accountId): void
    {
        $this->table()->where('account_id', $accountId)->delete();
        $this->connections->connection()->table('ssl_orders')->where('account_id', $accountId)->delete();
    }

    /**
     * Every site with a certificate, for the Admin panel, soonest to expire
     * first.
     *
     * @return list<array<string, mixed>>
     */
    public function all(int $limit = 500): array
    {
        $rows = $this->connections->connection()->table('ssl_sites')
            ->select('ssl_sites.*', 'domains.name as domain', 'accounts.username as username')
            ->join('domains', 'domains.id', '=', 'ssl_sites.domain_id')
            ->join('accounts', 'accounts.id', '=', 'ssl_sites.account_id')
            ->orderBy('ssl_sites.not_after')
            ->limit($limit)
            ->get();

        return \array_values(\array_map(self::decode(...), $rows));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        foreach (['names', 'excluded'] as $json) {
            $row[$json] = \is_string($row[$json] ?? null) ? (\json_decode($row[$json], true) ?: []) : [];
        }

        return $row;
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('ssl_sites');
    }
}
