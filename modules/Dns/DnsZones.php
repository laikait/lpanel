<?php

declare(strict_types=1);

namespace App\Modules\Dns;

use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Modules\Accounts\Accounts;

/**
 * Zones and their records as the panel keeps them, the nameservers and
 * templates they are made from, and each zone's state as the agent takes it.
 *
 * Every main, addon and alias domain of an account has a zone; a subdomain
 * gets its records in the zone above it. A zone's own NS records and its SOA
 * are never stored: they come from the nameservers of whoever the zone
 * belongs to (the account's reseller if they set their own, else the
 * server's), so changing nameservers changes every zone at once.
 */
final class DnsZones
{
    /** The server's own profile (nameservers and template). */
    public const SERVER = 0;

    /** TTL of a zone's own NS records. */
    private const NS_TTL = 86400;

    /** @var array<int, array<string, mixed>|null> */
    private array $profiles = [];

    /** @var array<string, string>|null */
    private ?array $settings = null;

    public function __construct(private readonly ConnectionManager $connections) {}

    // -------------------------------------------------------------------------
    // Settings and profiles

    /** @return array{ip4: string, ip6: string} the addresses new records point at */
    public function addresses(): array
    {
        return ['ip4' => $this->setting('ip4'), 'ip6' => $this->setting('ip6')];
    }

    public function setting(string $name): string
    {
        if ($this->settings === null) {
            $this->settings = [];

            foreach ($this->table('dns_settings')->get() as $row) {
                $this->settings[(string) $row['name']] = (string) $row['value'];
            }
        }

        return $this->settings[$name] ?? '';
    }

    public function set(string $name, string $value): void
    {
        $this->table('dns_settings')->upsert([['name' => $name, 'value' => $value]], ['name'], ['value']);
        $this->settings = null;
    }

    /**
     * A profile as stored: the server's (SERVER) or a reseller's.
     *
     * @return array{nameservers: list<string>, template: ?string}
     */
    public function profile(int $ownerId): array
    {
        if (!\array_key_exists($ownerId, $this->profiles)) {
            $this->profiles[$ownerId] = $this->table('dns_profiles')->where('owner_id', $ownerId)->first();
        }

        $row = $this->profiles[$ownerId];
        $list = $row === null ? null : \json_decode((string) ($row['nameservers'] ?? '[]'), true);

        return [
            'nameservers' => \is_array($list) ? \array_values(\array_map('strval', $list)) : [],
            'template' => $row === null || $row['template'] === null ? null : (string) $row['template'],
        ];
    }

    /** @param list<string> $nameservers */
    public function saveProfile(int $ownerId, array $nameservers, ?string $template): void
    {
        $this->table('dns_profiles')->upsert([[
            'owner_id' => $ownerId,
            'nameservers' => \json_encode(\array_values($nameservers), \JSON_THROW_ON_ERROR),
            'template' => $template,
            'updated_at' => Accounts::now(),
        ]], ['owner_id'], ['nameservers', 'template', 'updated_at']);
        unset($this->profiles[$ownerId]);
    }

    public function deleteProfile(int $ownerId): void
    {
        $this->table('dns_profiles')->where('owner_id', $ownerId)->delete();
        unset($this->profiles[$ownerId]);
    }

    /**
     * The nameservers a zone of this owner uses: the reseller's own when set,
     * else the server's, else ns1. and ns2. of the zone itself.
     *
     * @return list<string>
     */
    public function nameserversFor(?int $ownerId, string $zone): array
    {
        $own = $ownerId === null ? [] : $this->profile($ownerId)['nameservers'];
        $list = $own !== [] ? $own : $this->profile(self::SERVER)['nameservers'];

        return $list !== [] ? $list : ['ns1.' . $zone, 'ns2.' . $zone];
    }

    /** The template a new zone of this owner is made from. */
    public function templateFor(?int $ownerId): string
    {
        $own = $ownerId === null ? null : $this->profile($ownerId)['template'];

        return $own ?? $this->profile(self::SERVER)['template'] ?? DnsRecords::DEFAULT_TEMPLATE;
    }

    /** @return list<string> every nameserver name any profile uses */
    public function allNameservers(): array
    {
        $names = [];

        foreach ($this->table('dns_profiles')->get() as $row) {
            $list = \json_decode((string) ($row['nameservers'] ?? '[]'), true);

            foreach (\is_array($list) ? $list : [] as $n) {
                $names[(string) $n] = true;
            }
        }

        return \array_keys($names);
    }

    // -------------------------------------------------------------------------
    // Zones

    /** @return array<string, mixed>|null */
    public function zone(int $id): ?array
    {
        return $this->table('dns_zones')->where('id', $id)->first();
    }

    /** @return array<string, mixed>|null */
    public function zoneByName(string $name): ?array
    {
        return $this->table('dns_zones')->where('name', $name)->first();
    }

    /** @return list<array<string, mixed>> the account's zones, by name (the server's own for null) */
    public function zonesOf(?int $accountId): array
    {
        $q = $this->table('dns_zones');
        $q = $accountId === null ? $q->whereNull('account_id') : $q->where('account_id', $accountId);

        return \array_values(\array_filter($q->orderBy('name')->get(), static fn(array $z): bool => $z['state'] !== DnsSync::REMOVING));
    }

    /** @return list<array<string, mixed>> every zone, with its account's name and record count */
    public function all(): array
    {
        $usernames = [];

        foreach ($this->table('accounts')->get() as $a) {
            $usernames[(int) $a['id']] = (string) $a['username'];
        }

        $counts = [];

        foreach ($this->table('dns_records')->get() as $r) {
            $counts[(int) $r['zone_id']] = ($counts[(int) $r['zone_id']] ?? 0) + 1;
        }

        return \array_map(static fn(array $z): array => $z + [
            'username' => $z['account_id'] === null ? null : ($usernames[(int) $z['account_id']] ?? '?'),
            'records' => $counts[(int) $z['id']] ?? 0,
        ], $this->table('dns_zones')->orderBy('name')->get());
    }

    /**
     * The zone of this account (or the server's, for null) that holds a
     * name: the longest.
     *
     * @return array<string, mixed>|null
     */
    public function zoneHolding(?int $accountId, string $name): ?array
    {
        $best = null;

        foreach ($this->zonesOf($accountId) as $zone) {
            if (($name === $zone['name'] || \str_ends_with($name, '.' . $zone['name'])) && ($best === null || \strlen((string) $zone['name']) > \strlen((string) $best['name']))) {
                $best = $zone;
            }
        }

        return $best;
    }

    public function addZone(?int $accountId, string $name): int
    {
        return (int) $this->table('dns_zones')->insert([
            'account_id' => $accountId, 'name' => $name, 'revision' => 0, 'applied_revision' => 0,
            'state' => DnsSync::PENDING, 'created_at' => Accounts::now(),
        ], 'id');
    }

    public function deleteZone(int $id): void
    {
        $this->table('dns_records')->where('zone_id', $id)->delete();
        $this->table('dns_domains')->where('zone_id', $id)->delete();
        $this->table('dns_provided')->where('zone_id', $id)->delete();
        $this->table('dns_zones')->where('id', $id)->delete();
    }

    /** @param array<string, mixed> $changes */
    public function updateZone(int $id, array $changes): void
    {
        $this->table('dns_zones')->where('id', $id)->update($changes);
    }

    /** Forget a terminated account: the agent removed its zones itself. */
    public function deleteAccount(int $accountId): void
    {
        foreach ($this->table('dns_zones')->where('account_id', $accountId)->get() as $zone) {
            $this->deleteZone((int) $zone['id']);
        }

        $this->table('dns_domains')->where('account_id', $accountId)->delete();
    }

    // -------------------------------------------------------------------------
    // Records

    /** @return list<array<string, mixed>> a zone's records: the zone's own name first, then by name and type */
    public function records(int $zoneId): array
    {
        $rows = $this->table('dns_records')->where('zone_id', $zoneId)->get();
        \usort($rows, static fn(array $a, array $b): int => [$a['name'] !== '@', $a['name'], \array_search($a['type'], DnsRecords::TYPES, true), $a['content']]
            <=> [$b['name'] !== '@', $b['name'], \array_search($b['type'], DnsRecords::TYPES, true), $b['content']]);

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function record(int $zoneId, int $id): ?array
    {
        return $this->table('dns_records')->where('zone_id', $zoneId)->where('id', $id)->first();
    }

    /** @param array{name: string, type: string, ttl: int, content: string} $record */
    public function addRecord(int $zoneId, array $record, ?int $domainId = null, ?string $source = null): int
    {
        return (int) $this->table('dns_records')->insert([
            'zone_id' => $zoneId, 'name' => $record['name'], 'type' => $record['type'], 'ttl' => $record['ttl'],
            'content' => $record['content'], 'domain_id' => $domainId, 'source' => $source, 'created_at' => Accounts::now(),
        ], 'id');
    }

    /**
     * Records another module keeps for one of an account's domains, in the
     * zone that holds it (mail: the DKIM key, the DMARC policy). A set is
     * placed once and placed again only when it changes, so the owner may
     * edit or remove its records; a record is left out where the zone has
     * one of its kind already (a second SPF or DMARC record would break
     * both). The records go when the domain goes.
     *
     * @param array<string, mixed>                                                $domain  a row of domains
     * @param list<array{name: string, type: string, ttl: int, content: string}> $records names in full
     *
     * @return int|null the zone changed, or null for none (no zone here, or nothing new)
     */
    public function provide(array $domain, string $source, array $records): ?int
    {
        $zone = $this->zoneHolding((int) $domain['account_id'], (string) $domain['name']);

        if ($zone === null) {
            return null;
        }

        $zoneId = (int) $zone['id'];
        $domainId = (int) $domain['id'];
        $digest = \sha1(\json_encode($records, \JSON_THROW_ON_ERROR));
        $placed = $this->table('dns_provided')->where('zone_id', $zoneId)->where('domain_id', $domainId)->where('source', $source)->first();

        if ($placed !== null && $placed['digest'] === $digest) {
            return null;
        }

        $this->table('dns_records')->where('zone_id', $zoneId)->where('domain_id', $domainId)->where('source', $source)->delete();
        $existing = $this->records($zoneId);

        foreach ($records as $record) {
            $name = DnsRecords::relative($record['name'], (string) $zone['name']);

            if ($name === null || \array_filter($existing, static fn(array $r): bool => $r['name'] === $name && self::sameKind($r, $record)) !== []) {
                continue;
            }

            $this->addRecord($zoneId, ['name' => $name] + $record, $domainId, $source);
        }

        if ($placed === null) {
            $this->table('dns_provided')->insert(['zone_id' => $zoneId, 'domain_id' => $domainId, 'source' => $source, 'digest' => $digest]);
        } else {
            $this->table('dns_provided')->where('id', (int) $placed['id'])->update(['digest' => $digest]);
        }

        return $zoneId;
    }

    /**
     * Two records of the same kind at one name: the same type, and for TXT
     * the same leading tag ("v=spf1", "v=DMARC1", "v=DKIM1").
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function sameKind(array $a, array $b): bool
    {
        if ($a['type'] !== $b['type']) {
            return false;
        }

        if ($a['type'] !== 'TXT') {
            return true;
        }

        $tag = static fn(mixed $content): string => \strtolower((string) \preg_replace('/[\s;].*$/s', '', \trim((string) $content)));

        return $tag($a['content']) === $tag($b['content']);
    }

    /** @param array{name: string, type: string, ttl: int, content: string} $record */
    public function updateRecord(int $id, array $record): void
    {
        $this->table('dns_records')->where('id', $id)->update(['name' => $record['name'], 'type' => $record['type'], 'ttl' => $record['ttl'], 'content' => $record['content']]);
    }

    public function deleteRecord(int $id): void
    {
        $this->table('dns_records')->where('id', $id)->delete();
    }

    /**
     * Put a zone's records back as they were.
     *
     * @param list<array<string, mixed>> $rows as records() gave them
     */
    public function restoreRecords(int $zoneId, array $rows): void
    {
        $this->table('dns_records')->where('zone_id', $zoneId)->delete();

        foreach ($rows as $row) {
            $this->table('dns_records')->insert($row);
        }
    }

    /**
     * Replace a zone's records with its template's, and the records its
     * account's domains need (subdomains, wildcards).
     *
     * @param array<string, mixed> $zone
     */
    public function fill(array $zone, ?int $ownerId): void
    {
        $id = (int) $zone['id'];
        $this->table('dns_records')->where('zone_id', $id)->delete();
        // Other modules' records go too; they place them again (dns.zone_reset).
        $this->table('dns_provided')->where('zone_id', $id)->delete();
        $ips = $this->addresses();

        foreach (DnsRecords::expand($this->templateFor($ownerId), (string) $zone['name'], $ips['ip4'], $ips['ip6']) as $record) {
            $this->addRecord($id, $record);
        }

        foreach ($this->table('dns_domains')->where('zone_id', $id)->get() as $made) {
            $domain = $this->table('domains')->where('id', (int) $made['domain_id'])->first();

            if ($domain !== null) {
                $this->addDomainRecords($zone, $domain, (bool) $made['wildcard']);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Keeping zones in step with an account's domains

    /**
     * Make the zones and records an account's domains need, and remove
     * those of domains that are gone. Records the owner changed or removed
     * since are left as they are.
     *
     * @param array<string, mixed> $account
     *
     * @return array{touched: list<int>, removed: list<array<string, mixed>>} zones to send, and zones to remove on the server
     */
    public function reconcile(array $account): array
    {
        $accountId = (int) $account['id'];
        $ownerId = $account['owner_id'] === null ? null : (int) $account['owner_id'];
        $domains = [];

        foreach ($this->table('domains')->where('account_id', $accountId)->orderBy('id')->get() as $d) {
            $domains[(int) $d['id']] = $d;
        }

        $touched = [];
        $removed = [];
        $zones = [];

        foreach ($this->table('dns_zones')->where('account_id', $accountId)->get() as $z) {
            $zones[(string) $z['name']] = $z;
        }

        // Zones: one for each domain that is not a subdomain.
        $wanted = [];

        foreach ($domains as $d) {
            if ($d['kind'] !== 'sub') {
                $wanted[(string) $d['name']] = true;
            }
        }

        foreach ($zones as $name => $z) {
            if (!isset($wanted[$name]) && $z['state'] !== DnsSync::REMOVING) {
                $removed[] = $z;
                unset($zones[$name]);
            }
        }

        foreach (\array_keys($wanted) as $name) {
            if (!isset($zones[$name]) && $this->zoneByName($name) === null) {
                $id = $this->addZone($accountId, $name);
                $zones[$name] = $this->zone($id) ?? throw new \LogicException('zone vanished');
                $this->fill($zones[$name], $ownerId);
                $touched[$id] = true;
            }
        }

        // Records for subdomains and wildcards, once for each domain.
        $made = [];

        foreach ($this->table('dns_domains')->where('account_id', $accountId)->get() as $row) {
            $made[(int) $row['domain_id']] = $row;
        }

        foreach ($made as $domainId => $row) {
            if (!isset($domains[$domainId])) {
                $this->table('dns_records')->where('domain_id', $domainId)->delete();
                $this->table('dns_provided')->where('domain_id', $domainId)->delete();
                $this->table('dns_domains')->where('id', (int) $row['id'])->delete();
                $touched[(int) $row['zone_id']] = true;
            }
        }

        foreach ($domains as $domainId => $d) {
            $zone = null;

            foreach ($zones as $name => $z) {
                if (($d['name'] === $name || \str_ends_with((string) $d['name'], '.' . $name)) && ($zone === null || \strlen($name) > \strlen((string) $zone['name']))) {
                    $zone = $z;
                }
            }

            if ($zone === null || $zone['state'] === DnsSync::REMOVING) {
                continue;
            }

            $wildcard = (bool) $d['wildcard'];
            $row = $made[$domainId] ?? null;

            if ($row === null) {
                $this->table('dns_domains')->insert(['domain_id' => $domainId, 'account_id' => $accountId, 'zone_id' => (int) $zone['id'], 'wildcard' => $wildcard]);

                if ($this->addDomainRecords($zone, $d, $wildcard) > 0) {
                    $touched[(int) $zone['id']] = true;
                }
            } elseif ((bool) $row['wildcard'] !== $wildcard) {
                $this->table('dns_domains')->where('id', (int) $row['id'])->update(['wildcard' => $wildcard]);
                $star = DnsRecords::relative('*.' . $d['name'], (string) $zone['name']);

                if ($wildcard) {
                    $this->addDomainRecords($zone, $d, true, onlyWildcard: true);
                } else {
                    $this->table('dns_records')->where('domain_id', $domainId)->where('name', (string) $star)->delete();
                }

                $touched[(int) $zone['id']] = true;
            }
        }

        return ['touched' => \array_keys($touched), 'removed' => $removed];
    }

    /**
     * Address records for a domain inside a zone: a subdomain's own name,
     * and "*." under any domain that answers every subdomain. A name that
     * has records already is left alone.
     *
     * @param array<string, mixed> $zone
     * @param array<string, mixed> $domain
     */
    private function addDomainRecords(array $zone, array $domain, bool $wildcard, bool $onlyWildcard = false): int
    {
        $names = [];

        if ($domain['kind'] === 'sub' && !$onlyWildcard) {
            $names[] = (string) $domain['name'];
        }

        if ($wildcard) {
            $names[] = '*.' . $domain['name'];
        }

        $ips = $this->addresses();
        $existing = $this->records((int) $zone['id']);
        $added = 0;

        foreach ($names as $full) {
            $name = DnsRecords::relative($full, (string) $zone['name']);

            if ($name === null || \array_filter($existing, static fn(array $r): bool => $r['name'] === $name) !== []) {
                continue;
            }

            foreach (['A' => $ips['ip4'], 'AAAA' => $ips['ip6']] as $type => $ip) {
                if ($ip !== '') {
                    $this->addRecord((int) $zone['id'], ['name' => $name, 'type' => $type, 'ttl' => DnsRecords::DEFAULT_TTL, 'content' => $ip], (int) $domain['id']);
                    $added++;
                }
            }
        }

        return $added;
    }

    // -------------------------------------------------------------------------
    // What the agent is sent

    /**
     * A zone's whole state as dns.apply takes it.
     *
     * @param array<string, mixed> $zone
     *
     * @return array<string, mixed>
     */
    public function state(array $zone): array
    {
        $name = (string) $zone['name'];
        $account = $zone['account_id'] === null ? null : $this->table('accounts')->where('id', (int) $zone['account_id'])->first();
        $ownerId = $account === null || $account['owner_id'] === null ? null : (int) $account['owner_id'];
        $nameservers = $this->nameserversFor($ownerId, $name);
        $records = [];

        foreach ($nameservers as $ns) {
            $records[] = ['name' => $name, 'type' => 'NS', 'ttl' => self::NS_TTL, 'content' => $ns];
        }

        $held = [];

        foreach ($this->records((int) $zone['id']) as $row) {
            $full = DnsRecords::full((string) $row['name'], $name);
            $held[$full] = true;
            $records[] = ['name' => $full, 'type' => (string) $row['type'], 'ttl' => (int) $row['ttl'], 'content' => (string) $row['content']];
        }

        // Glue: a nameserver named inside this zone needs its address here.
        $ips = $this->addresses();

        foreach ($this->allNameservers() as $ns) {
            if (($ns === $name || \str_ends_with($ns, '.' . $name)) && !isset($held[$ns])) {
                foreach (['A' => $ips['ip4'], 'AAAA' => $ips['ip6']] as $type => $ip) {
                    if ($ip !== '') {
                        $records[] = ['name' => $ns, 'type' => $type, 'ttl' => self::NS_TTL, 'content' => $ip];
                    }
                }
            }
        }

        return [
            'username' => $account === null ? '' : (string) $account['username'],
            'zone' => $name,
            'soa' => ['primary' => $nameservers[0], 'mail' => 'hostmaster.' . $name, 'refresh' => 10800, 'retry' => 3600, 'expire' => 604800, 'minimum' => 3600],
            'records' => $records,
        ];
    }

    private function table(string $name): QueryBuilder
    {
        return $this->connections->connection()->table($name);
    }
}
