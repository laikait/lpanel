<?php

declare(strict_types=1);

namespace App\Modules\Web;

use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Http\Request;
use App\Modules\Accounts\Accounts;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentReply;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Audit\AuditLog;

/**
 * Certificates for sites and for the server's hostname.
 *
 * AutoSSL (ssl:autossl, every five minutes) gives every site of every active
 * account a certificate from Let's Encrypt, or ZeroSSL when Let's Encrypt
 * does not issue, and renews it before it expires. A site is due when it
 * has no certificate, when its certificate is in its last third (at most 30
 * days), or when it has names the certificate does not cover that were not
 * found pointing elsewhere in the last day. A failure waits 1, 2, 4… up to
 * 24 hours before the next try, so the authorities' limits are never hit.
 *
 * Getting a certificate takes up to minutes, so the agent does it in the
 * background (ssl.issue). Each order is recorded with its operation id
 * before it is sent; every run asks again until the agent has the answer.
 * The certificate stays in the agent's store and the site serves it once
 * web.apply names it (WebSync).
 *
 * Owners may also bring their own certificate (with a key, or for a request
 * made here), and choose per site whether AutoSSL looks after it and whether
 * plain HTTP goes to HTTPS.
 */
final class AutoSsl
{
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';

    public const LETSENCRYPT = 'letsencrypt';
    public const ZEROSSL = 'zerossl';

    /** Orders at once: the agent runs eight operations of any kind. */
    public const MAX_RUNNING = 4;

    /** At most this long before expiry, renew (or the last third, if shorter). */
    public const RENEW_DAYS = 30;

    /** A certificate brought by the owner is replaced only this close to expiry. */
    public const CUSTOM_DAYS = 3;

    /** Names found pointing elsewhere are checked again after this long. */
    public const RECHECK_HOURS = 24;

    /** Agent answers that mean "not now". */
    private const TRANSIENT = ['busy', 'stale', 'bad_signature'];

    private ?\Closure $clock = null;

    /** @var array<string, mixed>|null|false ssl.host_info, once per request */
    private array|null|false $hostInfo = false;

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly AgentClient $agent,
        private readonly Domains $domains,
        private readonly SslSites $sites,
        private readonly WebSync $sync,
        private readonly WebSettings $settings,
        private readonly AuditLog $audit,
    ) {}

    /** Tests set the time. */
    public function setClock(\Closure $clock): void
    {
        $this->clock = $clock;
    }

    public function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : \time();
    }

    private function stamp(?int $at = null): string
    {
        return \gmdate('Y-m-d H:i:s', $at ?? $this->now());
    }

    private static function time(?string $stamp): ?int
    {
        return $stamp === null ? null : (int) \strtotime($stamp . ' UTC');
    }

    // -------------------------------------------------------------------------
    // Settings

    /**
     * @return array{enabled: bool, primary: string, fallback: bool, eab_kid: string, eab_set: bool, hostname: string, host_autossl: bool}
     */
    public function settings(): array
    {
        return [
            'enabled' => $this->settings->get('autossl', '1') === '1',
            'primary' => $this->settings->get('ssl_primary', self::LETSENCRYPT) === self::ZEROSSL ? self::ZEROSSL : self::LETSENCRYPT,
            'fallback' => $this->settings->get('ssl_fallback', '1') === '1',
            'eab_kid' => (string) $this->settings->get('zerossl_eab_kid', ''),
            'eab_set' => (string) $this->settings->get('zerossl_eab_hmac', '') !== '',
            'hostname' => $this->hostname(),
            'host_autossl' => $this->settings->get('host_autossl', '1') === '1',
        ];
    }

    /**
     * Change the settings; unknown keys are ignored. An empty EAB HMAC keeps
     * the one stored, so the form need not show it.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, string> field => lang key, when refused
     */
    public function saveSettings(Identity $actor, ?Request $request, array $input): array
    {
        $errors = [];
        $kid = \array_key_exists('eab_kid', $input) ? \trim((string) $input['eab_kid']) : null;
        $hmac = \array_key_exists('eab_hmac', $input) ? \trim((string) $input['eab_hmac']) : '';
        $host = \array_key_exists('hostname', $input) ? \strtolower(\trim((string) $input['hostname'])) : null;
        $primary = $input['primary'] ?? null;

        if ($kid !== null && $kid !== '' && \preg_match('/^[A-Za-z0-9_-]{1,200}$/', $kid) !== 1) {
            $errors['eab_kid'] = 'Web.ssl_eab_invalid';
        }

        if ($hmac !== '' && \preg_match('/^[A-Za-z0-9_-]{16,200}={0,2}$/', $hmac) !== 1) {
            $errors['eab_hmac'] = 'Web.ssl_eab_invalid';
        }

        if ($host !== null && $host !== '' && !self::validHost($host)) {
            $errors['hostname'] = 'Web.ssl_hostname_invalid';
        }

        if ($primary !== null && !\in_array($primary, [self::LETSENCRYPT, self::ZEROSSL], true)) {
            $errors['primary'] = 'Web.ssl_primary_invalid';
        }

        $hasEab = ($kid ?? (string) $this->settings->get('zerossl_eab_kid', '')) !== ''
            && ($hmac !== '' || (string) $this->settings->get('zerossl_eab_hmac', '') !== '');

        if ($primary === self::ZEROSSL && !$hasEab) {
            $errors['primary'] = 'Web.ssl_zerossl_needs_eab';
        }

        if ($errors !== []) {
            return $errors;
        }

        $changed = [];

        foreach (['enabled' => 'autossl', 'fallback' => 'ssl_fallback', 'host_autossl' => 'host_autossl'] as $field => $key) {
            if (\array_key_exists($field, $input)) {
                $this->settings->set($key, \filter_var($input[$field], \FILTER_VALIDATE_BOOL) ? '1' : '0');
                $changed[] = $field;
            }
        }

        if ($primary !== null) {
            $this->settings->set('ssl_primary', (string) $primary);
            $changed[] = 'primary';
        }

        if ($kid !== null) {
            $this->settings->set('zerossl_eab_kid', $kid);
            $changed[] = 'eab_kid';

            if ($kid === '') {
                $this->settings->set('zerossl_eab_hmac', '');
            }
        }

        if ($hmac !== '') {
            $this->settings->set('zerossl_eab_hmac', $hmac);
            $changed[] = 'eab_hmac';
        }

        if ($host !== null) {
            $this->settings->set('ssl_hostname', $host);
            $changed[] = 'hostname';
        }

        $this->audit->record('ssl.settings', $actor, $request, 'AutoSSL', AuditLog::OK, ['changed' => \implode(',', $changed)]);

        return [];
    }

    /**
     * The authorities to ask, in order. ZeroSSL needs its EAB credentials.
     *
     * @return list<string>
     */
    public function authorities(): array
    {
        $s = $this->settings();
        $order = $s['primary'] === self::ZEROSSL ? [self::ZEROSSL, self::LETSENCRYPT] : [self::LETSENCRYPT, self::ZEROSSL];

        if (!$s['fallback']) {
            $order = [$order[0]];
        }

        return \array_values(\array_filter($order, static fn(string $a): bool => $a !== self::ZEROSSL || $s['eab_set']));
    }

    /** @return array{kid: string, hmac: string}|null */
    private function eab(): ?array
    {
        $kid = (string) $this->settings->get('zerossl_eab_kid', '');
        $hmac = (string) $this->settings->get('zerossl_eab_hmac', '');

        return $kid !== '' && $hmac !== '' ? ['kid' => $kid, 'hmac' => $hmac] : null;
    }

    /** The server's hostname, as set, else the kernel's when it is a full name. */
    public function hostname(): string
    {
        $set = (string) $this->settings->get('ssl_hostname', '');

        if ($set !== '') {
            return $set;
        }

        try {
            $reply = $this->agent->call('system.facts', timeout: 5.0);
            $name = \strtolower((string) ($reply->result['hostname'] ?? ''));
        } catch (AgentUnavailable) {
            return '';
        }

        return self::validHost($name) ? $name : '';
    }

    public static function validHost(string $name): bool
    {
        return \strlen($name) <= 253 && \preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $name) === 1;
    }

    // -------------------------------------------------------------------------
    // What each site wants

    /**
     * The names a site's certificate should cover: its own, its www., and
     * its aliases', without wildcards (HTTP-01 cannot prove those).
     *
     * @param array<string, mixed> $account
     *
     * @return array<int, list<string>> domain id => names, for each site (main, addon, sub)
     */
    public function namesOf(array $account): array
    {
        $byName = [];

        foreach ($this->domains->forAccount((int) $account['id']) as $row) {
            $byName[(string) $row['name']] = (int) $row['id'];
        }

        $out = [];

        foreach ($this->domains->state($account)['sites'] as $site) {
            $id = $byName[(string) $site['name']] ?? null;

            if ($id === null) {
                continue;
            }

            $names = [(string) $site['name']];

            foreach ((array) $site['aliases'] as $alias) {
                if (!\str_starts_with((string) $alias, '*.')) {
                    $names[] = (string) $alias;
                }
            }

            $out[$id] = \array_slice(\array_values(\array_unique($names)), 0, 100);
        }

        return $out;
    }

    /**
     * Why AutoSSL should order a certificate for a site now, or null.
     *
     * @param array<string, mixed>|null $ssl
     * @param list<string> $names
     */
    public function dueReason(?array $ssl, array $names, ?int $now = null): ?string
    {
        $now ??= $this->now();

        if ($ssl !== null && !$ssl['autossl']) {
            return null;
        }

        if ($ssl !== null && $ssl['retry_at'] !== null && self::time($ssl['retry_at']) > $now) {
            return null;
        }

        if ($ssl === null || $ssl['cert_id'] === null) {
            return 'none';
        }

        $after = (int) self::time($ssl['not_after']);
        $before = (int) self::time($ssl['not_before']);

        if ($ssl['source'] === SslSites::CUSTOM && !$ssl['self_signed']) {
            return $after - $now < self::CUSTOM_DAYS * 86400 ? 'expiring' : null;
        }

        if ($ssl['source'] === SslSites::CUSTOM || $after - $now < \min(self::RENEW_DAYS * 86400, \intdiv(\max(0, $after - $before), 3))) {
            return $ssl['source'] === SslSites::CUSTOM ? 'self_signed' : 'expiring';
        }

        $uncovered = \array_values(\array_filter($names, static fn(string $n): bool => !self::covers((array) $ssl['names'], $n)));

        if ($uncovered === []) {
            return null;
        }

        $excluded = \array_map(static fn(array $e): string => (string) ($e['name'] ?? ''), \array_filter((array) $ssl['excluded'], 'is_array'));
        $recent = $ssl['checked_at'] !== null && $now - (int) self::time($ssl['checked_at']) < self::RECHECK_HOURS * 3600;

        return $recent && \array_diff($uncovered, $excluded) === [] ? null : 'names';
    }

    /** @param list<string>|array<int, mixed> $certNames */
    public static function covers(array $certNames, string $host): bool
    {
        foreach ($certNames as $n) {
            $n = (string) $n;

            if ($n === $host) {
                return true;
            }

            if (\str_starts_with($n, '*.')) {
                $dot = \strpos($host, '.');

                if ($dot !== false && $dot > 0 && \substr($host, $dot + 1) === \substr($n, 2)) {
                    return true;
                }
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // AutoSSL

    /**
     * Ask about every order in flight, then order for every site that is
     * due, as many at once as MAX_RUNNING allows.
     *
     * @return array{settled: array<string, int>, started: int, due: int}
     */
    public function run(): array
    {
        $settled = $this->settleAll();
        $started = 0;
        $due = 0;

        if (!$this->settings()['enabled'] || $this->authorities() === []) {
            return ['settled' => $settled, 'started' => 0, 'due' => 0];
        }

        $slots = self::MAX_RUNNING - $this->orders()->where('state', self::RUNNING)->count();

        if ($this->hostDue() !== null) {
            $due++;

            if ($slots > 0 && $this->startHost(null, null, 'AutoSSL')[0] !== null) {
                $slots--;
                $started++;
            }
        }

        $busy = [];

        foreach ($this->orders()->where('state', self::RUNNING)->where('kind', 'site')->get() as $o) {
            $busy[(int) $o['domain_id']] = true;
        }

        foreach ($this->connections->connection()->table('accounts')->where('status', Accounts::ACTIVE)->orderBy('id')->get() as $account) {
            $ssl = $this->sites->forAccount((int) $account['id']);

            foreach ($this->namesOf($account) as $domainId => $names) {
                if (isset($busy[$domainId]) || $this->dueReason($ssl[$domainId] ?? null, $names) === null) {
                    continue;
                }

                $due++;

                if ($slots <= 0) {
                    continue;
                }

                $this->start($account, $domainId, $names, 'AutoSSL');
                $slots--;
                $started++;
            }
        }

        return ['settled' => $settled, 'started' => $started, 'due' => $due];
    }

    /**
     * Order a site's certificate now, whatever its wait after failures.
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $domain
     *
     * @return array{0: ?array<string, mixed>, 1: ?string} the order, or a lang key saying why not
     */
    public function issueNow(Identity $actor, ?Request $request, array $account, array $domain): array
    {
        $this->settleAll();
        $names = $this->namesOf($account)[(int) $domain['id']] ?? null;
        $why = match (true) {
            $account['status'] !== Accounts::ACTIVE => 'Web.ssl_not_active',
            $names === null => 'Web.ssl_not_a_site',
            $this->authorities() === [] => 'Web.ssl_no_authority',
            $this->runningFor((int) $domain['id']) !== null => 'Web.ssl_busy',
            default => null,
        };

        if ($why !== null) {
            return [null, $why];
        }

        $this->sites->ensure((int) $account['id'], (int) $domain['id']);
        $this->sites->update((int) $domain['id'], ['retry_at' => null]);
        $this->audit->record('ssl.issue', $actor, $request, (string) $domain['name'], AuditLog::OK, ['state' => 'started', 'names' => \implode(',', $names)]);

        return [$this->start($account, (int) $domain['id'], $names, self::actorName($actor)), null];
    }

    /** @return array<string, mixed>|null the site's order in flight */
    public function runningFor(int $domainId): ?array
    {
        return $this->orders()->where('domain_id', $domainId)->where('state', self::RUNNING)->first();
    }

    /** @return array<string, mixed>|null the site's latest order */
    public function lastFor(int $domainId): ?array
    {
        return $this->orders()->where('domain_id', $domainId)->orderBy('id', 'desc')->first();
    }

    /** @return array<string, mixed>|null */
    public function order(int $id): ?array
    {
        return $this->orders()->where('id', $id)->first();
    }

    /** @return list<array<string, mixed>> the latest orders, newest first */
    public function recentOrders(int $limit = 20): array
    {
        return \array_values($this->orders()->orderBy('id', 'desc')->limit($limit)->get());
    }

    /**
     * Ask again about every order still running.
     *
     * @return array<string, int> state => count
     */
    public function settleAll(): array
    {
        $counts = [];

        foreach ($this->orders()->where('state', self::RUNNING)->orderBy('id')->get() as $row) {
            $row = $this->send($row);
            $counts[(string) $row['state']] = ($counts[(string) $row['state']] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $account
     * @param list<string> $names
     *
     * @return array<string, mixed> the order as it is after sending
     */
    private function start(array $account, int $domainId, array $names, string $actorName): array
    {
        $this->sites->ensure((int) $account['id'], $domainId);
        $args = ['username' => (string) $account['username'], 'names' => $names, 'authorities' => $this->authorities()];

        if (($eab = $this->eab()) !== null) {
            $args['zerossl_eab'] = $eab;
        }

        $id = $this->orders()->insert([
            'kind' => 'site',
            'account_id' => (int) $account['id'],
            'domain_id' => $domainId,
            'op_id' => Protocol::newId(),
            'op_args' => \json_encode($args, \JSON_THROW_ON_ERROR),
            'state' => self::RUNNING,
            'actor_name' => \mb_substr($actorName, 0, 120),
            'created_at' => $this->stamp(),
        ], 'id');

        return $this->send($this->order((int) $id) ?? []);
    }

    /**
     * Send an order (again) and record what the agent says.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function send(array $row): array
    {
        $args = \json_decode((string) $row['op_args'], true);
        $op = $row['kind'] === 'host' ? 'ssl.host_issue' : 'ssl.issue';

        try {
            $reply = $this->agent->call($op, \is_array($args) ? $args : [], id: (string) $row['op_id'], timeout: 30.0);
        } catch (AgentUnavailable) {
            return $row;
        }

        return $this->settle($row, $reply);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function settle(array $row, AgentReply $reply): array
    {
        if ($reply->status === 'accepted' || $reply->status === 'running'
            || ($reply->status === 'refused' && \in_array($reply->errorCode, self::TRANSIENT, true))) {
            return $row;
        }

        $result = $reply->done() ? $reply->result : [];
        $issued = $reply->done() && ($result['issued'] ?? false) === true && \is_array($result['cert'] ?? null);
        $error = match (true) {
            $issued => null,
            $reply->done() => (string) ($result['error'] ?? 'The authority did not issue.'),
            default => ($reply->errorCode ?? 'failed') . ': ' . ($reply->errorMessage ?? 'The agent gave no reason.'),
        };
        $changes = [
            'state' => $issued ? self::DONE : self::FAILED,
            'result' => \json_encode($result, \JSON_THROW_ON_ERROR),
            'error' => $error,
            'finished_at' => $this->stamp(),
        ];
        $this->orders()->where('id', (int) $row['id'])->where('state', self::RUNNING)->update($changes);
        $excluded = \array_values(\array_filter((array) ($result['excluded'] ?? []), 'is_array'));

        if ($row['kind'] === 'host') {
            $this->hostSettled($row, $issued, $error, $result);

            return $changes + $row;
        }

        $domainId = (int) $row['domain_id'];
        $site = $this->sites->forDomain($domainId);
        $domain = $this->connections->connection()->table('domains')->where('id', $domainId)->first();

        // The site or its account went meanwhile: nothing to serve.
        if ($site === null || $domain === null) {
            return $changes + $row;
        }

        if ($issued) {
            $this->sites->install((int) $row['account_id'], $domainId, (array) $result['cert'], SslSites::AUTO, (string) ($result['authority'] ?? '') ?: null);
            $this->sites->update($domainId, ['excluded' => $excluded, 'last_error' => null, 'failures' => 0, 'checked_at' => $this->stamp(), 'retry_at' => null]);
            $this->sync->touch((int) $row['account_id']);
        } else {
            $failures = (int) $site['failures'] + 1;
            $this->sites->update($domainId, [
                'excluded' => $excluded,
                'last_error' => \mb_substr((string) $error, 0, 2000),
                'failures' => $failures,
                'checked_at' => $this->stamp(),
                'retry_at' => $this->stamp($this->now() + self::backoff($failures)),
            ]);
        }

        $this->audit->record('ssl.issue', null, null, (string) $domain['name'], $issued ? AuditLog::OK : AuditLog::FAILED, [
            'order' => (int) $row['id'], 'state' => $changes['state'], 'by' => (string) $row['actor_name'],
        ] + ($issued ? ['authority' => (string) ($result['authority'] ?? ''), 'cert' => (string) ($result['cert']['id'] ?? '')] : ['error' => \mb_substr((string) $error, 0, 300)]));

        return $changes + $row;
    }

    /** Seconds to wait after the nth failure in a row: 1, 2, 4… hours, at most 24. */
    public static function backoff(int $failures): int
    {
        return \min(24, 2 ** \max(0, \min(5, $failures - 1))) * 3600;
    }

    // -------------------------------------------------------------------------
    // Certificates the owner brings, and the owner's choices

    /**
     * Install a certificate the owner brings. The key may be left out when
     * the certificate answers a request (CSR) made here.
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $domain
     *
     * @return array{0: bool, 1: ?string, 2: ?string} done, a lang key saying why not, the agent's detail
     */
    public function install(Identity $actor, ?Request $request, array $account, array $domain, string $key, string $chain): array
    {
        if (!isset($this->namesOf($account)[(int) $domain['id']])) {
            return [false, 'Web.ssl_not_a_site', null];
        }

        if (\trim($chain) === '') {
            return [false, 'Web.ssl_chain_required', null];
        }

        try {
            $reply = $this->agent->call('ssl.install', ['username' => (string) $account['username'], 'key' => $key, 'chain' => $chain], timeout: 30.0);
        } catch (AgentUnavailable) {
            return [false, 'Web.ssl_agent_down', null];
        }

        if (!$reply->done()) {
            $this->audit->record('ssl.install', $actor, $request, (string) $domain['name'], AuditLog::FAILED, ['error' => (string) $reply->errorCode]);

            return [false, $reply->errorCode === 'cert_no_key' ? 'Web.ssl_no_key' : 'Web.ssl_invalid', (string) $reply->errorMessage];
        }

        $info = $reply->result;

        if (!self::covers((array) ($info['names'] ?? []), (string) $domain['name'])) {
            $this->audit->record('ssl.install', $actor, $request, (string) $domain['name'], AuditLog::FAILED, ['error' => 'does_not_cover']);

            return [false, 'Web.ssl_does_not_cover', \implode(', ', \array_map('strval', (array) ($info['names'] ?? [])))];
        }

        $this->sites->install((int) $account['id'], (int) $domain['id'], $info, SslSites::CUSTOM, null);
        $this->sites->update((int) $domain['id'], ['failures' => 0, 'retry_at' => null, 'last_error' => null]);
        $this->audit->record('ssl.install', $actor, $request, (string) $domain['name'], AuditLog::OK, [
            'cert' => (string) $info['id'], 'issuer' => (string) ($info['issuer'] ?? ''), 'expires' => \gmdate('Y-m-d', (int) ($info['not_after'] ?? 0)),
        ]);
        $this->sync->touch((int) $account['id']);

        return [true, null, null];
    }

    /**
     * Make a key (kept on the server) and a certificate request for a
     * commercial certificate covering the site's names.
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $domain
     * @param array<string, string> $subject country, state, locality, organization, key_type
     *
     * @return array{0: ?string, 1: ?string, 2: ?string} the CSR, a lang key saying why not, the agent's detail
     */
    public function csr(Identity $actor, ?Request $request, array $account, array $domain, array $subject): array
    {
        $names = $this->namesOf($account)[(int) $domain['id']] ?? null;

        if ($names === null) {
            return [null, 'Web.ssl_not_a_site', null];
        }

        $args = ['username' => (string) $account['username'], 'names' => $names];

        foreach (['key_type', 'country', 'state', 'locality', 'organization'] as $field) {
            $args[$field] = \trim((string) ($subject[$field] ?? ''));
        }

        $args['country'] = \strtoupper($args['country']);

        try {
            $reply = $this->agent->call('ssl.csr', $args, timeout: 30.0);
        } catch (AgentUnavailable) {
            return [null, 'Web.ssl_agent_down', null];
        }

        if (!$reply->done()) {
            return [null, 'Web.ssl_csr_refused', (string) $reply->errorMessage];
        }

        $this->audit->record('ssl.csr', $actor, $request, (string) $domain['name'], AuditLog::OK, ['names' => \implode(',', $names)]);

        return [(string) $reply->result['csr'], null, null];
    }

    /**
     * Stop serving a site's certificate. Removing one AutoSSL got turns
     * AutoSSL off for the site, or it would be back within minutes.
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $domain
     */
    public function remove(Identity $actor, ?Request $request, array $account, array $domain): WebOutcome
    {
        $ssl = $this->sites->forDomain((int) $domain['id']);

        if ($ssl === null || $ssl['cert_id'] === null) {
            return WebOutcome::done();
        }

        $this->sites->clear((int) $domain['id']);

        if ($ssl['source'] === SslSites::AUTO) {
            $this->sites->update((int) $domain['id'], ['autossl' => false]);
        }

        $this->audit->record('ssl.remove', $actor, $request, (string) $domain['name'], AuditLog::OK, ['cert' => (string) $ssl['cert_id'], 'source' => (string) $ssl['source']]);

        return $this->sync->touch((int) $account['id']);
    }

    /**
     * Turn AutoSSL or forcing HTTPS on or off for a site.
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $domain
     */
    public function setOptions(Identity $actor, ?Request $request, array $account, array $domain, ?bool $autossl, ?bool $force): WebOutcome
    {
        $ssl = $this->sites->ensure((int) $account['id'], (int) $domain['id']);
        $changes = [];

        if ($autossl !== null && $autossl !== (bool) $ssl['autossl']) {
            // Turned back on: try now, not after the last failure's wait.
            $changes += ['autossl' => $autossl, 'retry_at' => null, 'failures' => 0];
        }

        if ($force !== null && $force !== (bool) $ssl['force_https']) {
            $changes['force_https'] = $force;
        }

        if ($changes === []) {
            return WebOutcome::done();
        }

        $this->sites->update((int) $domain['id'], $changes);
        $this->audit->record('ssl.options', $actor, $request, (string) $domain['name'], AuditLog::OK, \array_map(static fn($v): string => \is_bool($v) ? ($v ? 'on' : 'off') : (string) $v, \array_intersect_key($changes, ['autossl' => 1, 'force_https' => 1])));

        return isset($changes['force_https']) && $ssl['cert_id'] !== null ? $this->sync->touch((int) $account['id']) : WebOutcome::done();
    }

    // -------------------------------------------------------------------------
    // The server's hostname

    /** @return array{installed: bool, cert: ?array<string, mixed>}|null null when the agent cannot be asked */
    public function hostInfo(): ?array
    {
        if ($this->hostInfo === false) {
            try {
                $reply = $this->agent->call('ssl.host_info', [], timeout: 10.0);
                $this->hostInfo = $reply->done() ? $reply->result : null;
            } catch (AgentUnavailable) {
                $this->hostInfo = null;
            }
        }

        if (!\is_array($this->hostInfo)) {
            return null;
        }

        return ['installed' => (bool) ($this->hostInfo['installed'] ?? false), 'cert' => \is_array($this->hostInfo['cert'] ?? null) ? $this->hostInfo['cert'] : null];
    }

    /** Why the hostname needs a certificate now, or null. */
    public function hostDue(): ?string
    {
        $s = $this->settings();
        $retry = self::time($this->settings->get('host_retry_at') ?: null);

        if (!$s['host_autossl'] || $s['hostname'] === '' || ($retry !== null && $retry > $this->now())
            || $this->orders()->where('kind', 'host')->where('state', self::RUNNING)->first() !== null) {
            return null;
        }

        $info = $this->hostInfo();

        if ($info === null) {
            return null;
        }

        $cert = $info['cert'];

        return match (true) {
            $cert === null => 'none',
            (bool) ($cert['self_signed'] ?? false) => 'self_signed',
            !self::covers((array) ($cert['names'] ?? []), $s['hostname']) => 'names',
            (int) $cert['not_after'] - $this->now() < \min(self::RENEW_DAYS * 86400, \intdiv(\max(0, (int) $cert['not_after'] - (int) $cert['not_before']), 3)) => 'expiring',
            default => null,
        };
    }

    /**
     * Order the hostname's certificate now.
     *
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    public function hostIssueNow(Identity $actor, ?Request $request): array
    {
        $this->settleAll();
        $this->settings->set('host_retry_at', '');
        $this->audit->record('ssl.host_issue', $actor, $request, $this->hostname(), AuditLog::OK, ['state' => 'started']);

        return $this->startHost($actor, $request, self::actorName($actor));
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    private function startHost(?Identity $actor, ?Request $request, string $actorName): array
    {
        $host = $this->hostname();
        $why = match (true) {
            $host === '' => 'Web.ssl_no_hostname',
            $this->authorities() === [] => 'Web.ssl_no_authority',
            $this->orders()->where('kind', 'host')->where('state', self::RUNNING)->first() !== null => 'Web.ssl_busy',
            default => null,
        };

        if ($why !== null) {
            return [null, $why];
        }

        $args = ['names' => [$host], 'authorities' => $this->authorities()];

        if (($eab = $this->eab()) !== null) {
            $args['zerossl_eab'] = $eab;
        }

        $id = $this->orders()->insert([
            'kind' => 'host',
            'op_id' => Protocol::newId(),
            'op_args' => \json_encode($args, \JSON_THROW_ON_ERROR),
            'state' => self::RUNNING,
            'actor_name' => \mb_substr($actorName, 0, 120),
            'created_at' => $this->stamp(),
        ], 'id');

        return [$this->send($this->order((int) $id) ?? []), null];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $result
     */
    private function hostSettled(array $row, bool $issued, ?string $error, array $result): void
    {
        $this->hostInfo = false;

        if ($issued) {
            $this->settings->set('host_failures', '0');
            $this->settings->set('host_retry_at', '');
            $this->settings->set('host_error', '');
        } else {
            $failures = (int) $this->settings->get('host_failures', '0') + 1;
            $this->settings->set('host_failures', (string) $failures);
            $this->settings->set('host_retry_at', $this->stamp($this->now() + self::backoff($failures)));
            $this->settings->set('host_error', \mb_substr((string) $error, 0, 250));
        }

        $this->audit->record('ssl.host_issue', null, null, (string) (\json_decode((string) $row['op_args'], true)['names'][0] ?? ''), $issued ? AuditLog::OK : AuditLog::FAILED, [
            'order' => (int) $row['id'], 'by' => (string) $row['actor_name'],
        ] + ($issued ? ['authority' => (string) ($result['authority'] ?? '')] : ['error' => \mb_substr((string) $error, 0, 300)]));
    }

    /**
     * Install a hostname certificate the administrator brings.
     *
     * @return array{0: bool, 1: ?string, 2: ?string}
     */
    public function hostInstall(Identity $actor, ?Request $request, string $key, string $chain): array
    {
        if (\trim($chain) === '' || \trim($key) === '') {
            return [false, 'Web.ssl_host_key_required', null];
        }

        try {
            $reply = $this->agent->call('ssl.host_install', ['key' => $key, 'chain' => $chain], timeout: 90.0);
        } catch (AgentUnavailable) {
            return [false, 'Web.ssl_agent_down', null];
        }

        $this->hostInfo = false;

        if (!$reply->done()) {
            $this->audit->record('ssl.host_install', $actor, $request, $this->hostname(), AuditLog::FAILED, ['error' => (string) $reply->errorCode]);

            return [false, 'Web.ssl_invalid', (string) $reply->errorMessage];
        }

        // Installed by hand: AutoSSL leaves it be until it nears expiry.
        $this->settings->set('host_failures', '0');
        $this->settings->set('host_retry_at', '');
        $this->audit->record('ssl.host_install', $actor, $request, $this->hostname(), AuditLog::OK, ['issuer' => (string) ($reply->result['issuer'] ?? '')]);

        return [true, null, null];
    }

    // -------------------------------------------------------------------------

    private static function actorName(Identity $actor): string
    {
        return (string) ($actor->attribute('username') ?? $actor->name);
    }

    private function orders(): QueryBuilder
    {
        return $this->connections->connection()->table('ssl_orders');
    }
}
