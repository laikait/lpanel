<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * The Linux side of hosting accounts, as the agent keeps it, for feature
 * tests: users with a shell, a quota and a suspended flag, each account's
 * web state as web.apply last set it, and the agent's
 * journal, so the same operation id gets the first outcome however often it
 * is asked (what makes retries safe).
 */
final class FakeServer
{
    /** @var array<string, array{uid: int, shell: string, suspended: bool, quota: array<string, int>, used: int}> */
    public array $users = [];

    /** @var array<string, array<string, mixed>> username => the web state last applied */
    public array $sites = [];

    /** @var list<string> the PHP versions installed */
    public array $php = ['82', '83'];

    /** @var array<int|string, list<string>> version => its extensions, as php.info answers (PHP makes "82" an int key) */
    public array $extensions = ['82' => ['gd', 'mbstring', 'zip'], '83' => ['intl', 'mbstring', 'mysqlnd', 'opcache', 'pdo', 'process', 'sodium', 'xml']];

    /** @var array<string, array<string, int>> username => day => bytes, as web.traffic answers */
    public array $traffic = [];

    /** @var list<string> operations actually carried out, not answered from the journal */
    public array $executed = [];

    /** While true, every request fails as if the socket were gone. */
    public bool $down = false;

    /** Carry out the next change, then lose the reply (the panel crashed, the connection broke). */
    public bool $loseNextReply = false;

    /** @var list<string> names whose DNS points at this server; others are left out of certificates */
    public array $pointing = [];

    /** @var list<string> authorities that are down (they fail without checking anything) */
    public array $authorityDown = [];

    /** @var list<string> names the authority's check fails for, although they point here */
    public array $checkFails = [];

    /** @var array<string, array<string, array<string, mixed>>> username => certificate id => info, the agent's store */
    public array $certs = [];

    /** @var array<string, list<string>> username => names of requests (CSRs) whose keys are kept */
    public array $requests = [];

    /** @var array<string, mixed>|null the hostname certificate installed; null is the installer's self-signed one */
    public ?array $hostCert = null;

    /** How long issued certificates are valid, from now. */
    public int $validDays = 90;

    /** When issued certificates start (now when null). */
    public ?int $issuedAt = null;

    /** @var array<string, array<string, mixed>> zone => its last dns.apply state */
    public array $zones = [];

    /** @var array<string, list<string>> what the world's DNS says: zone => its nameservers at the registry */
    public array $delegated = [];

    /** @var array<string, list<string>> host => its addresses in the world's DNS */
    public array $resolves = [];

    /** @var list<string> this server's addresses */
    public array $localIps = ['192.0.2.10'];

    /** @var array<string, array<string, mixed>> account => its last db.apply state */
    public array $dbs = [];

    /** @var array<string, int> database => bytes, as db.sizes reports */
    public array $dbSizes = [];

    /** @var array<string, list<string>> database => tables (for rename) */
    public array $dbTables = [];

    /** @var list<array<string, mixed>> connections, as db.processes reports */
    public array $dbProcesses = [];

    /** @var array<string, string> account => its phpMyAdmin password now */
    public array $sso = [];

    /** @var array<string, array<string, mixed>> account => its last mail.apply state */
    public array $mail = [];

    /** @var array<string, int> address => bytes, as mail.usage reports */
    public array $mailUsage = [];

    /** @var list<string> mail deleted: "account domain local" */
    public array $mailPurged = [];

    /** The relay host mail.settings set ("" for none). */
    public string $smarthost = '';

    /** @var array{user: string, password: string}|null the login to it, as mail.settings left it */
    public ?array $relayLogin = null;

    /** @var array{spam: bool, virus: bool, reject: int} what mail.filtering set */
    public array $filtering = ['spam' => false, 'virus' => false, 'reject' => 150];

    /** @var list<array<string, mixed>> Exim's queue, as mail.queue lists it */
    public array $queue = [];

    /** @var list<string> mail.queue_act calls: "action id,id" */
    public array $queueActs = [];

    /** @var list<array<string, mixed>> deliveries mail.track reads (each may carry an "account") */
    public array $deliveries = [];

    /** @var array<string, array{password: string, expires: int}> webmail sign-on passwords by address */
    public array $mailSso = [];

    /** @var list<string> sign-on passwords forgotten (address, or "expired") */
    public array $mailSsoRevoked = [];

    /** @var array<string, mixed> what mail.check answers (merged over a clean result) */
    public array $mailCheck = [];

    /** @var list<array<string, mixed>> mail.check's arguments, each time */
    public array $mailChecks = [];

    /** @var array{op: string, code: string, message: string}|null the next time this op runs, it fails */
    public ?array $failNext = null;

    /** @var array<string, array<string, mixed>> request id => response */
    private array $journal = [];

    private int $nextUid = 1001;

    public function agent(): FakeAgent
    {
        $answers = [];

        foreach (['account.create', 'account.modify', 'account.suspend', 'account.unsuspend', 'account.terminate', 'web.apply', 'web.remove', 'php.install', 'php.remove', 'php.extensions', 'ssl.issue', 'ssl.host_issue', 'ssl.install', 'ssl.csr', 'ssl.host_install', 'db.apply', 'db.drop', 'db.rename', 'db.sso', 'db.sso_revoke', 'db.kill', 'dns.apply', 'dns.remove', 'mail.apply', 'mail.purge', 'mail.settings', 'mail.filtering', 'mail.queue_act', 'mail.sso', 'mail.sso_revoke', 'mail.sso_expire'] as $op) {
            $answers[$op] = fn(array $args, string $id): array => $this->change($op, $args, $id);
        }

        $answers['account.info'] = fn(array $args): array => $this->read($args);
        $answers['web.info'] = fn(): array => $this->down ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => [
            'server' => 'apache', 'installed' => true, 'running' => true, 'accounts' => \count($this->sites),
            'php' => \array_map(fn(string $v): array => ['version' => $v, 'pools' => 0, 'running' => true], $this->php),
        ]];
        $answers['php.info'] = fn(): array => $this->down ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => $this->phpInfo()];
        $answers['web.traffic'] = fn(): array => $this->down ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => ['accounts' => (object) $this->traffic, 'more' => false]];
        $answers['system.facts'] = FakeAgent::unavailable();
        $answers['dns.info'] = fn(): array => $this->down ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => ['enabled' => true, 'running' => true, 'version' => '4.9.4', 'zones' => \count($this->zones)]];
        $answers['dns.lookup'] = fn(array $args): array => $this->down ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => [
            'hosts' => (object) \array_combine((array) $args['hosts'], \array_map(fn(string $h): array => [
                'addresses' => $this->resolves[$h] ?? [],
                'here' => \array_intersect($this->resolves[$h] ?? [], $this->localIps) !== [],
            ], (array) $args['hosts'])),
            'zones' => (object) \array_combine((array) $args['zones'], \array_map(fn(string $z): array => $this->delegated[$z] ?? [], (array) $args['zones'])),
            'local' => $this->localIps,
        ]];
        $answers['db.sizes'] = fn(array $args): array => $this->down ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => ['databases' => (object) \array_filter(
            $this->dbSizes,
            static fn(string $db): bool => !\is_array($args['usernames'] ?? null) || \in_array(\explode('_', $db)[0], $args['usernames'], true),
            \ARRAY_FILTER_USE_KEY,
        )]];
        $answers['mail.usage'] = fn(array $args): array => $this->down ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => ['mailboxes' => (object) \array_filter(
            $this->mailUsage,
            fn(string $address): bool => \in_array(\substr($address, (int) \strrpos($address, '@') + 1), $this->mailDomainsOf((string) $args['username']), true),
            \ARRAY_FILTER_USE_KEY,
        )]];
        $answers['mail.info'] = fn(): array => $this->down ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => [
            'enabled' => true, 'exim' => ['running' => true, 'version' => '4.100.1'], 'dovecot' => ['running' => true, 'version' => '2.3.16'],
            'queue' => \count($this->queue), 'domains' => \count($this->mailOwners()), 'filtering' => $this->filtering,
            'rspamd' => ['running' => $this->filtering['spam'], 'version' => '4.3.0'],
            'clamav' => ['running' => $this->filtering['virus'], 'version' => '1.4.6', 'ready' => true, 'signatures' => '27000'],
        ]];
        $answers['mail.queue'] = fn(array $args): array => $this->down ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => [
            'total' => \count($this->queue),
            'frozen' => \count(\array_filter($this->queue, static fn(array $m): bool => (bool) $m['frozen'])),
            'messages' => \array_values(\array_filter($this->queue, static fn(array $m): bool => ($args['search'] ?? '') === '' || \str_contains(\strtolower($m['sender'] . ' ' . \implode(' ', $m['recipients'])), \strtolower((string) $args['search'])))),
        ]];
        $answers['mail.queue_view'] = function (array $args): array {
            foreach ($this->queue as $m) {
                if ($m['id'] === $args['id']) {
                    return ['status' => 'done', 'result' => ['id' => $m['id'], 'message' => "From: <{$m['sender']}>\nSubject: Queued\n\nHello", 'truncated' => false, 'log' => '== deferred']];
                }
            }

            return ['status' => 'failed', 'error' => ['code' => 'mail_no_message', 'message' => $args['id'] . ' is not in the queue']];
        };
        $answers['mail.track'] = fn(array $args): array => $this->down ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => [
            'deliveries' => \array_values(\array_map(
                static fn(array $d): array => \array_diff_key($d, ['account' => true]),
                \array_filter($this->deliveries, static fn(array $d): bool => (($args['username'] ?? '') === '' || ($d['account'] ?? '') === $args['username'])
                    && (($args['status'] ?? '') === '' || $d['status'] === $args['status'])),
            )),
            'more' => false,
        ]];
        $answers['mail.check'] = function (array $args): array {
            if ($this->down) {
                throw FakeAgent::unavailable();
            }

            $this->mailChecks[] = $args;
            $ip = (string) ($args['ip'] ?? '') !== '' ? (string) $args['ip'] : '192.0.2.10';
            $domains = [];

            foreach ((array) ($args['domains'] ?? []) as $d) {
                $domains[$d] = ($this->mailCheck['domains'][$d] ?? []) + [
                    'spf' => 'v=spf1 a mx ~all', 'spf_count' => 1, 'spf_result' => [$ip => 'pass'],
                    'dmarc' => 'v=DMARC1; p=none', 'dkim' => '', 'mx' => [['host' => 'mail.' . $d, 'pref' => 10, 'addresses' => [$ip], 'here' => true]],
                ];
            }

            return ['status' => 'done', 'result' => [
                'domains' => (object) $domains,
                'ips' => [$ip => ($this->mailCheck['ips'][$ip] ?? []) + ['private' => false, 'ptr' => ['e2e-panel.test'], 'forward' => true, 'lists' => ['zen.spamhaus.org' => 'clear', 'bl.spamcop.net' => 'clear']]],
            ]];
        };
        $answers['db.info'] = fn(): array => $this->down ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => ['installed' => true, 'running' => true, 'version' => '10.5.27-MariaDB', 'uptime' => 3600, 'threads' => \count($this->dbProcesses), 'queries' => 42]];
        $answers['db.processes'] = fn(): array => $this->down ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => ['processes' => $this->dbProcesses]];
        $answers['ssl.host_info'] = fn(): array => $this->down ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => [
            'installed' => true,
            'cert' => $this->hostCert ?? $this->certInfo(['localhost.localdomain'], 'localhost.localdomain', true, \time() - 86400, 825),
        ]];

        return new FakeAgent($answers);
    }

    /** How many times an operation was really carried out. */
    public function ran(string $op): int
    {
        return \count(\array_filter($this->executed, static fn(string $e): bool => $e === $op));
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function change(string $op, array $args, string $id): array
    {
        if ($this->down) {
            throw FakeAgent::unavailable();
        }

        if (isset($this->journal[$id])) {
            return $this->journal[$id];
        }

        $this->executed[] = $op;
        $response = $this->run($op, $args);
        $this->journal[$id] = $response;

        if ($this->loseNextReply) {
            $this->loseNextReply = false;

            throw FakeAgent::unavailable();
        }

        // These run in the background: the first answer is "accepted".
        $async = \str_starts_with($op, 'php.') || $op === 'ssl.issue' || $op === 'ssl.host_issue';

        return ($op === 'account.terminate' && $response['status'] === 'done') || $async ? ['status' => 'accepted'] : $response;
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function run(string $op, array $args): array
    {
        $name = (string) ($args['username'] ?? '');

        if ($this->failNext !== null && $this->failNext['op'] === $op) {
            $fail = $this->failNext;
            $this->failNext = null;

            return ['status' => 'failed', 'error' => ['code' => $fail['code'], 'message' => $fail['message']]];
        }

        if (\str_starts_with($op, 'php.')) {
            return $this->runPhp($op, $args);
        }

        if (\str_starts_with($op, 'ssl.')) {
            return $this->runSsl($op, $args);
        }

        if (\str_starts_with($op, 'db.')) {
            return $this->runDb($op, $args);
        }

        if (\str_starts_with($op, 'dns.')) {
            return $this->runDns($op, $args);
        }

        if (\str_starts_with($op, 'mail.')) {
            return $this->runMail($op, $args);
        }

        if ($op === 'account.create') {
            if (isset($this->users[$name])) {
                return ['status' => 'failed', 'error' => ['code' => 'exists', 'message' => 'a user named ' . $name . ' already exists']];
            }

            $this->users[$name] = ['uid' => $this->nextUid++, 'shell' => (string) $args['shell'], 'suspended' => false, 'quota' => $args['quota'], 'used' => 8192];

            return ['status' => 'done', 'result' => $this->describe($name)];
        }

        if ($op === 'account.terminate') {
            $existed = isset($this->users[$name]);
            // The agent removes the sites first (BeforeRemove).
            unset($this->users[$name], $this->sites[$name], $this->dbs[$name], $this->sso[$name], $this->mail[$name]);
            // And the account's zones.
            $this->zones = \array_filter($this->zones, static fn(array $z): bool => $z['username'] !== $name);

            return ['status' => 'done', 'result' => ['username' => $name, 'existed' => $existed]];
        }

        if ($op === 'web.remove') {
            $changed = isset($this->sites[$name]);
            unset($this->sites[$name]);

            return ['status' => 'done', 'result' => ['username' => $name, 'changed' => $changed, 'php' => []]];
        }

        if (!isset($this->users[$name])) {
            return ['status' => 'failed', 'error' => ['code' => 'no_such_account', 'message' => 'there is no user named ' . $name]];
        }

        if ($op === 'web.apply') {
            foreach ($args['sites'] as $site) {
                if (!\in_array($site['php'], $this->php, true)) {
                    return ['status' => 'failed', 'error' => ['code' => 'php_missing', 'message' => 'PHP ' . $site['php'] . ' is not installed']];
                }

                if (isset($site['tls']) && !isset($this->certs[$name][$site['tls']['cert']])) {
                    return ['status' => 'failed', 'error' => ['code' => 'cert_missing', 'message' => $site['name'] . ': certificate ' . $site['tls']['cert'] . ' is not on this server']];
                }
            }

            $changed = ($this->sites[$name] ?? null) !== $args;
            $this->sites[$name] = $args;

            return ['status' => 'done', 'result' => ['username' => $name, 'changed' => $changed, 'sites' => \count($args['sites'])]];
        }

        match ($op) {
            'account.modify' => $this->users[$name] = ['shell' => (string) ($args['shell'] ?? $this->users[$name]['shell']), 'quota' => $args['quota'] ?? $this->users[$name]['quota']] + $this->users[$name],
            'account.suspend' => $this->users[$name]['suspended'] = true,
            'account.unsuspend' => $this->users[$name]['suspended'] = false,
            default => null,
        };

        return ['status' => 'done', 'result' => $this->describe($name)];
    }

    /**
     * The DNS operations, checked the way the agent checks them.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function runDns(string $op, array $args): array
    {
        $fail = static fn(string $code, string $message): array => ['status' => 'failed', 'error' => ['code' => $code, 'message' => $message]];
        $zone = (string) $args['zone'];
        $name = (string) $args['username'];
        $have = $this->zones[$zone] ?? null;

        if ($name !== '' && !isset($this->users[$name])) {
            return $fail('no_such_account', 'there is no user named ' . $name);
        }

        if ($have !== null && $have['username'] !== $name) {
            return $fail('dns_not_owner', 'the zone ' . $zone . ' belongs to another account');
        }

        if ($op === 'dns.remove') {
            unset($this->zones[$zone]);

            return ['status' => 'done', 'result' => ['removed' => $have !== null]];
        }

        $ns = false;
        $cname = [];
        $others = [];

        foreach ($args['records'] as $r) {
            if ($r['name'] !== $zone && !\str_ends_with($r['name'], '.' . $zone)) {
                return $fail('bad_args', $r['name'] . ' is not in the zone ' . $zone);
            }

            if ($r['ttl'] < 60 || !\in_array($r['type'], ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS', 'PTR'], true)) {
                return $fail('bad_args', 'not a record a zone may hold');
            }

            if ($r['type'] === 'A' && \filter_var($r['content'], \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) === false) {
                return $fail('bad_args', $r['content'] . ' is not an IPv4 address');
            }

            $ns = $ns || ($r['type'] === 'NS' && $r['name'] === $zone);
            $r['type'] === 'CNAME' ? $cname[$r['name']] = true : $others[$r['name']] = true;
        }

        if (!$ns || \array_intersect_key($cname, $others) !== [] || ($args['soa']['primary'] ?? '') === '') {
            return $fail('bad_args', 'the zone needs a nameserver, and a CNAME stands alone');
        }

        $this->zones[$zone] = $args;

        return ['status' => 'done', 'result' => ['zone' => $zone, 'created' => $have === null, 'changed' => $have !== $args, 'serial' => 2026111501]];
    }

    /**
     * The mail operations, checked the way the agent checks them.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function runMail(string $op, array $args): array
    {
        $fail = static fn(string $code, string $message): array => ['status' => 'failed', 'error' => ['code' => $code, 'message' => $message]];

        if ($op === 'mail.settings') {
            $host = (string) ($args['smarthost'] ?? '');
            $user = (string) ($args['user'] ?? '');

            if ($host !== '' && \preg_match('/^[a-z0-9.-]+:\d{1,5}$/', $host) !== 1) {
                return $fail('bad_args', $host . ' is not a host');
            }

            if ($user !== '' && (bool) ($args['keep_password'] ?? false) && $this->relayLogin === null) {
                return $fail('bad_args', 'there is no relay host password to keep');
            }

            $this->smarthost = $host;
            $this->relayLogin = $host === '' || $user === '' ? null : ['user' => $user, 'password' => (bool) ($args['keep_password'] ?? false) ? (string) ($this->relayLogin['password'] ?? '') : (string) $args['password']];

            return ['status' => 'done', 'result' => ['smarthost' => $host, 'login' => $this->relayLogin !== null]];
        }

        if ($op === 'mail.filtering') {
            if ((bool) $args['virus'] && !(bool) $args['spam']) {
                return $fail('bad_args', 'virus scanning needs spam filtering on');
            }

            $this->filtering = ['spam' => (bool) $args['spam'], 'virus' => (bool) $args['virus'], 'reject' => (int) $args['reject']];

            return ['status' => 'done', 'result' => $this->filtering];
        }

        if ($op === 'mail.queue_act') {
            $this->queueActs[] = $args['action'] . ' ' . \implode(',', $args['ids']);

            if ($args['action'] === 'remove') {
                $this->queue = \array_values(\array_filter($this->queue, static fn(array $m): bool => !\in_array($m['id'], $args['ids'], true)));
            }

            return ['status' => 'done', 'result' => ['action' => $args['action'], 'messages' => \count($args['ids'])]];
        }

        if ($op === 'mail.sso_expire') {
            $before = \count($this->mailSso);
            $this->mailSso = \array_filter($this->mailSso, static fn(array $e): bool => $e['expires'] > \time());
            $this->mailSsoRevoked[] = 'expired';

            return ['status' => 'done', 'result' => ['removed' => $before - \count($this->mailSso)]];
        }

        $name = (string) $args['username'];

        if (!isset($this->users[$name])) {
            return $fail('no_such_account', 'there is no user named ' . $name);
        }

        if ($op === 'mail.sso') {
            $address = (string) $args['address'];
            [$local, $domain] = \explode('@', $address) + ['', ''];
            $found = false;

            foreach ($this->mail[$name]['domains'] ?? [] as $d) {
                $found = $found || ($d['name'] === $domain && \in_array($local, \array_column($d['mailboxes'], 'local'), true));
            }

            if (!$found) {
                return $fail('mail_no_mailbox', $address . ' is not a mailbox of this account');
            }

            if ((bool) ($this->mail[$name]['suspended'] ?? false)) {
                return $fail('mail_suspended', 'the account is suspended');
            }

            $old = $this->mailSso[$address] ?? null;
            $this->mailSso[$address] = ['password' => $old !== null && $old['expires'] > \time() + 3600 ? $old['password'] : \bin2hex(\random_bytes(16)), 'expires' => \time() + 3600 * (int) ($args['hours'] ?? 8)];

            return ['status' => 'done', 'result' => ['user' => $address, 'password' => $this->mailSso[$address]['password'], 'expires' => $this->mailSso[$address]['expires']]];
        }

        if ($op === 'mail.sso_revoke') {
            $address = (string) ($args['address'] ?? '');
            $this->mailSsoRevoked[] = $address === '' ? $name : $address;
            unset($this->mailSso[$address]);

            return ['status' => 'done', 'result' => ['removed' => 1]];
        }

        if ($op === 'mail.purge') {
            $domain = (string) $args['domain'];
            $local = (string) $args['local'];

            foreach ($this->mail[$name]['domains'] ?? [] as $d) {
                if ($d['name'] === $domain && ($local === '' || \in_array($local, \array_column($d['mailboxes'], 'local'), true))) {
                    return $fail('mail_in_use', $domain . ' is still set up');
                }
            }

            $this->mailPurged[] = $name . ' ' . $domain . ' ' . $local;

            return ['status' => 'done', 'result' => ['removed' => true]];
        }

        $owners = $this->mailOwners();
        $dkim = [];

        foreach ($args['domains'] as $d) {
            if (($owners[$d['name']] ?? $name) !== $name) {
                return $fail('mail_not_owner', 'the mail of ' . $d['name'] . ' belongs to another account');
            }

            if (!\is_bool($d['local']) || !\is_array($d['mailboxes']) || !\is_array($d['forwarders']) || !\in_array($d['catchall'], ['', ':fail:', ':blackhole:'], true) && !\str_contains($d['catchall'], '@')) {
                return $fail('bad_args', 'not a mail domain');
            }

            foreach ($d['mailboxes'] as $b) {
                if (\preg_match('/^[a-z0-9]([a-z0-9._-]*[a-z0-9])?$/', $b['local']) !== 1 || \preg_match('/^\$2[aby]\$\d\d\$[.\/A-Za-z0-9]{53}$/', $b['hash']) !== 1 || !\is_int($b['quota_mb'])) {
                    return $fail('bad_args', 'not a mailbox: ' . $b['local']);
                }

                if (isset($b['autoresponder']) && ((int) $b['autoresponder']['days'] < 1 || $b['autoresponder']['subject'] === '')) {
                    return $fail('bad_args', 'not an autoresponder');
                }
            }

            foreach ($d['forwarders'] as $f) {
                if (!\is_array($f['to']) || $f['to'] === []) {
                    return $fail('bad_args', 'a forwarder needs somewhere to go');
                }
            }

            $dkim[$d['name']] = 'v=DKIM1; k=rsa; p=' . \base64_encode(\md5($d['name'], true));
        }

        $changed = ($this->mail[$name] ?? null) !== $args;
        $this->mail[$name] = $args;

        return ['status' => 'done', 'result' => ['domains' => \count($args['domains']), 'changed' => $changed ? 1 : 0, 'dkim' => (object) $dkim]];
    }

    /** @return array<string, string> mail domain => account */
    private function mailOwners(): array
    {
        $owners = [];

        foreach ($this->mail as $name => $state) {
            foreach ($state['domains'] as $d) {
                $owners[$d['name']] = $name;
            }
        }

        return $owners;
    }

    /** @return list<string> */
    private function mailDomainsOf(string $name): array
    {
        return \array_column($this->mail[$name]['domains'] ?? [], 'name');
    }

    /**
     * The database operations, checked the way the agent checks them.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function runDb(string $op, array $args): array
    {
        $fail = static fn(string $code, string $message): array => ['status' => 'failed', 'error' => ['code' => $code, 'message' => $message]];

        if ($op === 'db.kill') {
            foreach ($this->dbProcesses as $p) {
                if ($p['id'] === $args['id']) {
                    if (!$p['killable']) {
                        return $fail('db_not_account', 'connection ' . $p['id'] . ' is not an account\'s');
                    }

                    $this->dbProcesses = \array_values(\array_filter($this->dbProcesses, static fn(array $q): bool => $q['id'] !== $args['id']));

                    return ['status' => 'done', 'result' => ['killed' => true]];
                }
            }

            return ['status' => 'done', 'result' => ['killed' => false]];
        }

        $name = (string) $args['username'];

        if (!isset($this->users[$name])) {
            return $fail('no_such_account', 'there is no user named ' . $name);
        }

        $prefix = $name . '_';
        $have = $this->dbs[$name]['databases'] ?? [];

        switch ($op) {
            case 'db.apply':
                foreach ($args['databases'] as $db) {
                    if (!\str_starts_with($db, $prefix)) {
                        return $fail('bad_args', $db . ' is not a name for this account');
                    }
                }

                foreach ($args['users'] as $u) {
                    if (\preg_match('/^\*[0-9A-F]{40}$/', (string) $u['hash']) !== 1) {
                        return $fail('bad_args', 'not a MariaDB hash');
                    }

                    foreach ((array) $u['grants'] as $db => $privileges) {
                        if (!\in_array($db, $args['databases'], true)) {
                            return $fail('bad_args', 'grants on ' . $db . ', which is not one of the account\'s databases');
                        }
                    }
                }

                // Databases are never dropped by apply.
                $args['databases'] = \array_values(\array_unique([...$have, ...$args['databases']]));
                $this->dbs[$name] = $args;

                return ['status' => 'done', 'result' => ['changed' => true, 'created' => [], 'stray' => []]];

            case 'db.drop':
                $this->dbs[$name]['databases'] = \array_values(\array_diff($have, [$args['name']]));
                unset($this->dbSizes[$args['name']]);

                return ['status' => 'done', 'result' => ['dropped' => \in_array($args['name'], $have, true)]];

            case 'db.rename':
                if (!\in_array($args['from'], $have, true)) {
                    return $fail('db_missing', 'there is no database ' . $args['from']);
                }

                if (\in_array($args['to'], $have, true)) {
                    return $fail('db_exists', 'there is already a database ' . $args['to']);
                }

                if (\in_array('a_view', $this->dbTables[$args['from']] ?? [], true)) {
                    return $fail('db_rename_objects', $args['from'] . ' has views');
                }

                $this->dbs[$name]['databases'] = [...\array_values(\array_diff($have, [$args['from']])), $args['to']];

                return ['status' => 'done', 'result' => ['renamed' => true]];

            case 'db.sso':
                $this->sso[$name] = \bin2hex(\random_bytes(24));

                return ['status' => 'done', 'result' => ['user' => 'lpsso-' . $name, 'password' => $this->sso[$name]]];

            case 'db.sso_revoke':
                unset($this->sso[$name]);

                return ['status' => 'done', 'result' => ['revoked' => true]];
        }

        return $fail('unknown_op', $op);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function read(array $args): array
    {
        if ($this->down) {
            throw FakeAgent::unavailable();
        }

        $names = \is_array($args['usernames'] ?? null) ? $args['usernames'] : \array_keys($this->users);
        $accounts = [];

        foreach ($names as $name) {
            if (isset($this->users[$name])) {
                $accounts[] = $this->describe($name);
            }
        }

        return ['status' => 'done', 'result' => ['accounts' => $accounts, 'missing' => []]];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function runPhp(string $op, array $args): array
    {
        $v = (string) $args['version'];
        $fail = static fn(string $code, string $message): array => ['status' => 'failed', 'error' => ['code' => $code, 'message' => $message]];

        if ($op === 'php.install') {
            $this->php = \array_values(\array_unique([...$this->php, $v]));
            \sort($this->php);
            $this->extensions[$v] = ['bcmath', 'gd', 'intl', 'mbstring', 'mysqlnd', 'opcache', 'process', 'xml', 'zip'];
        } elseif ($op === 'php.remove') {
            if ($v === '83') {
                return $fail('panel_php', 'the panel itself runs on PHP 8.3');
            }

            foreach ($this->sites as $state) {
                foreach ($state['sites'] as $site) {
                    if ($site['php'] === $v) {
                        return $fail('in_use', 'sites still use PHP ' . $v);
                    }
                }
            }

            $this->php = \array_values(\array_diff($this->php, [$v]));
            unset($this->extensions[$v]);
        } else {
            $have = \array_values(\array_diff([...$this->extensions[$v] ?? [], ...$args['install']], $args['remove']));
            \sort($have);
            $this->extensions[$v] = \array_values(\array_unique($have));
        }

        return ['status' => 'done', 'result' => ['version' => $v]];
    }

    /**
     * The certificate operations. A fake certificate is PEM-looking text
     * with a "names:" line; a key is any text with "PRIVATE KEY" in it.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function runSsl(string $op, array $args): array
    {
        $name = (string) ($args['username'] ?? '');
        $fail = static fn(string $code, string $message): array => ['status' => 'failed', 'error' => ['code' => $code, 'message' => $message]];

        if ($op === 'ssl.issue' || $op === 'ssl.host_issue') {
            $names = \array_map('strval', (array) $args['names']);
            $ok = \array_values(\array_filter($names, fn(string $n): bool => \in_array($n, $this->pointing, true)));
            $excluded = \array_values(\array_map(static fn(string $n): array => ['name' => $n, 'reason' => 'it has no DNS address (A or AAAA record)'], \array_diff($names, $ok)));
            $result = ['issued' => false, 'cert' => null, 'authority' => '', 'excluded' => $excluded, 'attempts' => []];

            if ($ok === [] || $ok[0] !== $names[0]) {
                return ['status' => 'done', 'result' => ['error' => $names[0] . ': it has no DNS address (A or AAAA record)'] + $result];
            }

            foreach ((array) $args['authorities'] as $ca) {
                if (\in_array($ca, $this->authorityDown, true)) {
                    $result['attempts'][] = ['authority' => $ca, 'error' => 'it did not answer in time'];

                    continue;
                }

                if (\array_intersect($ok, $this->checkFails) !== []) {
                    $result['attempts'][] = ['authority' => $ca, 'error' => \implode(',', \array_intersect($ok, $this->checkFails)) . ' did not pass its check'];

                    break;
                }

                $info = $this->certInfo($ok, $ca === 'zerossl' ? 'ZeroSSL ECC Domain Secure Site CA' : "Let's Encrypt E7", false, $this->issuedAt ?? \time(), $this->validDays);

                if ($op === 'ssl.host_issue') {
                    $this->hostCert = $info;
                } else {
                    $this->certs[$name][$info['id']] = $info;
                }

                return ['status' => 'done', 'result' => ['issued' => true, 'cert' => $info, 'authority' => $ca] + $result];
            }

            $result['error'] = \implode('; ', \array_map(static fn(array $a): string => $a['authority'] . ': ' . $a['error'], $result['attempts']));

            return ['status' => 'done', 'result' => $result];
        }

        if ($op === 'ssl.csr') {
            $this->requests[$name] = \array_values(\array_map('strval', (array) $args['names']));

            return ['status' => 'done', 'result' => ['csr' => "-----BEGIN CERTIFICATE REQUEST-----\nnames: " . \implode(',', (array) $args['names']) . "\n-----END CERTIFICATE REQUEST-----\n", 'key_id' => \substr(\md5($name), 0, 16)]];
        }

        // ssl.install and ssl.host_install
        $chain = (string) $args['chain'];

        if (!\str_contains($chain, 'BEGIN CERTIFICATE') || \preg_match('/^names: (.+)$/m', $chain, $m) !== 1) {
            return $fail('cert_invalid', 'no certificate was found (it must be in PEM form: -----BEGIN CERTIFICATE-----)');
        }

        $names = \explode(',', \trim($m[1]));
        $hasKey = \str_contains((string) $args['key'], 'PRIVATE KEY');

        if (!$hasKey && ($op === 'ssl.host_install' || ($this->requests[$name] ?? null) !== $names)) {
            return $fail('cert_no_key', 'no key was given, and no certificate request made here has this certificate\'s key; paste the private key too');
        }

        $info = $this->certInfo($names, \str_contains($chain, 'self-signed') ? $names[0] : 'Commercial CA', \str_contains($chain, 'self-signed'), \time() - 3600, 365);

        if ($op === 'ssl.host_install') {
            $this->hostCert = $info;
        } else {
            $this->certs[$name][$info['id']] = $info;
        }

        return ['status' => 'done', 'result' => $info];
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, mixed>
     */
    private function certInfo(array $names, string $issuer, bool $self, int $from, int $days): array
    {
        static $serial = 0;
        $serial++;

        return [
            'id' => \substr(\hash('sha256', \implode(',', $names) . $serial . \microtime()), 0, 16),
            'names' => $names, 'issuer' => $issuer, 'serial' => \dechex(1000 + $serial),
            'not_before' => $from, 'not_after' => $from + $days * 86400, 'key_type' => 'ECDSA P-256', 'self_signed' => $self,
        ];
    }

    /** @return array<string, mixed> */
    private function phpInfo(): array
    {
        $versions = [];

        foreach (['74', '80', '81', '82', '83', '84', '85'] as $v) {
            $installed = \in_array($v, $this->php, true);
            $versions[] = [
                'version' => $v, 'installed' => $installed, 'extensions' => $installed ? $this->extensions[$v] ?? [] : [],
                'pools' => 0, 'running' => $installed, 'panel' => $v === '83',
                'locked' => $v === '83' ? ['intl', 'mbstring', 'mysqlnd', 'opcache', 'pdo', 'process', 'sodium', 'xml'] : [],
            ];
        }

        return [
            'versions' => $versions,
            'extensions' => ['apcu', 'bcmath', 'gd', 'gmp', 'imagick', 'intl', 'ioncube', 'ldap', 'mbstring', 'memcached', 'mysqlnd', 'opcache', 'pdo', 'pgsql', 'process', 'redis', 'soap', 'sodium', 'tidy', 'xml', 'zip'],
            'defaults' => ['bcmath', 'gd', 'intl', 'mbstring', 'mysqlnd', 'opcache', 'process', 'xml', 'zip'],
        ];
    }

    /** @return array<string, mixed> */
    private function describe(string $name): array
    {
        $u = $this->users[$name];

        return [
            'username' => $name, 'uid' => $u['uid'], 'gid' => $u['uid'], 'home' => '/home/' . $name,
            'shell' => $u['shell'], 'suspended' => $u['suspended'],
            'usage' => ['used_bytes' => $u['used'], 'used_inodes' => 12, 'limit_kib' => $u['quota']['blocks_kib'] ?? 0, 'inode_limit' => $u['quota']['inodes'] ?? 0],
        ];
    }
}
