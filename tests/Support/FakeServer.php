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

    /**
     * Each account's home, as files.* sees it: path => content, or null for
     * a folder. Created with the server's own folders.
     *
     * @var array<string, array<string, string|null>>
     */
    public array $homes = [];

    /** The panel's staging folder, which files.* reads uploads from and writes downloads into. */
    public string $staging = '';

    /** @var array<string, array<string, mixed>> account => its last ftp.apply state */
    public array $ftp = [];

    /** @var array<string, mixed>|null what ftp.settings last wrote */
    public ?array $ftpSettings = null;

    /** @var list<array<string, mixed>> FTP sessions, as ftp.info lists them (each with its account) */
    public array $ftpSessions = [];

    /** @var list<int> sessions ftp.kill ended */
    public array $ftpKilled = [];

    /**
     * What the firewall holds: ports (port, proto, sources), both lists, the
     * countries blocked, and each country's networks (what its list holds).
     *
     * @var array{enabled: bool, running: bool, ports: list<array{port: string, proto: string, sources: list<string>}>, allow: list<string>, deny: list<string>, countries: list<string>, nets: array<string, list<string>>, keep: list<int>}
     */
    public array $fw = [
        'enabled' => true, 'running' => true,
        'ports' => [['port' => '22', 'proto' => 'tcp', 'sources' => []], ['port' => '80', 'proto' => 'tcp', 'sources' => []], ['port' => '7443', 'proto' => 'tcp', 'sources' => []]],
        'allow' => [], 'deny' => [], 'countries' => [], 'nets' => ['CN' => ['203.0.113.0/24'], 'RU' => ['198.51.100.0/24']], 'keep' => [22, 7443, 7444],
    ];
    /**
     * The services: unit => label, group, what it allows, and its state.
     *
     * @var array<string, array{label: string, group: string, allowed: list<string>, active: string, enabled: bool}>
     */
    public array $svc = [
        'httpd.service' => ['label' => 'Apache', 'group' => 'web', 'allowed' => ['start', 'stop', 'restart', 'reload', 'enable', 'disable'], 'active' => 'active', 'enabled' => true],
        'exim.service' => ['label' => 'Exim', 'group' => 'mail', 'allowed' => ['start', 'stop', 'restart', 'reload', 'enable', 'disable'], 'active' => 'active', 'enabled' => true],
        'pure-ftpd.service' => ['label' => 'Pure-FTPd', 'group' => 'ftp', 'allowed' => ['start', 'stop', 'restart', 'reload', 'enable', 'disable'], 'active' => 'inactive', 'enabled' => false],
        'mariadb.service' => ['label' => 'MariaDB', 'group' => 'database', 'allowed' => ['start', 'restart'], 'active' => 'active', 'enabled' => true],
        'lpa.service' => ['label' => 'LPanel agent', 'group' => 'panel', 'allowed' => [], 'active' => 'active', 'enabled' => true],
    ];

    /** @var array<string, list<array{schedule: string, command: string}>> the jobs cron runs, by account */
    public array $cron = [];

    /** @var list<array<string, mixed>> what each cron.apply was asked */
    public array $cronApplied = [];

    /** @var string|null when set, cron.apply refuses with these words */
    public ?string $cronRefuse = null;

    /** @var list<array<string, mixed>> what tasks.list answers (newest first) */
    public array $tasks = [];

    /** @var array<string, mixed> what updates.check finds */
    public array $updatesFound = [
        'checked' => '2026-11-24T03:00:00Z', 'kernel' => '5.14.0-503.el9.x86_64',
        'packages' => [
            ['name' => 'openssl-libs', 'arch' => 'x86_64', 'from' => '1:3.0.7-24.el9', 'to' => '1:3.0.7-27.el9', 'repo' => 'baseos', 'advisory' => 'ALSA-2026:0202', 'kind' => 'security', 'severity' => 'Important'],
            ['name' => 'php83-php-common', 'arch' => 'x86_64', 'from' => '8.3.14-1.el9.remi', 'to' => '8.3.15-1.el9.remi', 'repo' => 'remi-safe', 'advisory' => '', 'kind' => '', 'severity' => ''],
        ],
        'security' => 1, 'php' => [['version' => '83', 'from' => '8.3.14', 'to' => '8.3.15']], 'restart' => [],
        'reboot' => ['known' => true, 'needed' => false, 'why' => [], 'scheduled' => false],
        'panel' => ['channel' => true, 'installed' => '0.1.0', 'latest' => '0.2.0', 'released' => '2026-11-24', 'notes' => 'Fixes.', 'newer' => true, 'error' => ''],
    ];

    /** @var list<array<string, mixed>> what each updates.apply was asked */
    public array $updatesApplied = [];

    /** @var bool while true, update runs (check, apply, panel) are still going on */
    public bool $updatesHold = false;

    /** @var list<array<string, mixed>> what each updates.panel was asked */
    public array $upgrades = [];

    /** @var array<string, mixed> what updates.panel_status answers */
    public array $upgradeStatus = ['state' => '', 'target' => '', 'from' => '', 'started' => '', 'finished' => '', 'error' => '', 'running' => false, 'installed' => '0.1.0', 'log' => []];

    /** @var list<bool> each updates.reboot, true for a cancel */
    public array $reboots = [];

    /** @var list<string> ids of update runs (op.status answers "running" for them while updatesHold) */
    private array $updateIds = [];

    /** @var list<string> what services.act was asked, as "unit action" */
    public array $serviceActs = [];

    /** @var list<string> units whose restart fails */
    public array $svcFailRestart = [];

    /** @var array{hidepid: bool, links: bool, refuse: bool} what the server has for isolation; refuse makes isolation.apply fail */
    public array $isolation = ['hidepid' => false, 'links' => false, 'refuse' => false];

    /** @var list<array<string, mixed>> what each isolation.apply was asked */
    public array $isolationApplied = [];

    /** @var array{available: bool, server: string, rules: string, paranoia: int} what waf.info answers */
    public array $waf = ['available' => true, 'server' => 'apache', 'rules' => '3.3.5', 'paranoia' => 1];

    /** @var list<array<string, mixed>> what the next waf.events reports (then it is empty) */
    public array $wafEvents = [];

    /** @var list<array<string, mixed>> what each waf.events was asked */
    public array $wafScans = [];

    /** @var list<array{ip: string, service: string, count: int}> what the next authlog.scan reports (then it is empty) */
    public array $authFailures = [];

    /** @var list<array<string, mixed>> what each authlog.scan was asked */
    public array $authScans = [];

    /** @var array<string, list<array<string, mixed>>> account => the keys ssh.apply last wrote */
    public array $sshKeys = [];

    /** @var bool when true, ssh.apply refuses every list (as the agent does a bad key) */
    public bool $sshRefuse = false;

    /** @var array<string, array<string, mixed>> agent id (d1…) => what backup.destination kept, secrets included */
    public array $backupDestinations = [];

    /** @var list<array<string, mixed>> what each backup.run was asked */
    public array $backupRuns = [];

    /** @var bool while true, a backup run is still going on (op.status says running) */
    public bool $backupHold = false;

    /** @var array<string, string> account => why its backup fails */
    public array $backupFail = [];

    /** @var array<string, mixed>|null what system.facts answers; null: the agent cannot be asked */
    public ?array $facts = null;

    /** @var bool when true, backup.destination cannot reach the destination */
    public bool $backupUnreachable = false;

    /** @var array<string, array<string, mixed>> request id => response */
    private array $journal = [];

    /** @var list<array<string, mixed>> what each backup.restore and backup.download was asked, with the op */
    public array $backupRestores = [];

    /** @var list<string> ids of backup runs (op.status answers "running" for them while backupHold) */
    private array $backupIds = [];

    private int $nextUid = 1001;

    public function agent(): FakeAgent
    {
        $answers = [];

        foreach (['account.create', 'account.modify', 'account.suspend', 'account.unsuspend', 'account.terminate', 'web.apply', 'web.remove', 'php.install', 'php.remove', 'php.extensions', 'ssl.issue', 'ssl.host_issue', 'ssl.install', 'ssl.csr', 'ssl.host_install', 'db.apply', 'db.drop', 'db.rename', 'db.sso', 'db.sso_revoke', 'db.kill', 'dns.apply', 'dns.remove', 'mail.apply', 'mail.purge', 'mail.settings', 'mail.filtering', 'mail.queue_act', 'mail.sso', 'mail.sso_revoke', 'mail.sso_expire'] as $op) {
            $answers[$op] = fn(array $args, string $id): array => $this->change($op, $args, $id);
        }

        foreach (['ftp.apply', 'ftp.settings', 'ftp.kill', 'ssh.apply', 'backup.destination', 'backup.destination_remove', 'backup.run', 'backup.restore', 'backup.download', 'firewall.port', 'firewall.lists', 'firewall.countries', 'waf.settings', 'isolation.apply', 'services.act', 'cron.apply', 'updates.check', 'updates.apply', 'updates.panel', 'updates.reboot'] as $op) {
            $answers[$op] = fn(array $args, string $id): array => $this->change($op, $args, $id);
        }

        $answers['op.status'] = function (array $args): array {
            if ($this->down) {
                throw FakeAgent::unavailable();
            }

            $entry = $this->journal[(string) $args['id']] ?? null;

            if ($entry === null) {
                return ['status' => 'refused', 'error' => ['code' => 'not_found', 'message' => 'no operation with that id']];
            }

            if ($this->updatesHold && \in_array((string) $args['id'], $this->updateIds, true)) {
                return ['status' => 'running'];
            }

            return $this->backupHold && \in_array((string) $args['id'], $this->backupIds, true) ? ['status' => 'running'] : $entry;
        };
        $answers['backup.status'] = fn(): array => $this->down ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => [
            'running' => $this->backupHold, 'destination' => 'd1', 'current' => 'shop', 'done' => 0, 'total' => 1, 'installed' => true,
        ]];
        $answers['backup.snapshots'] = function (array $args): array {
            if ($this->down) {
                throw FakeAgent::unavailable();
            }

            $out = [];

            foreach (\array_reverse($this->backupRuns, true) as $n => $run) {
                foreach ($run['accounts'] as $a) {
                    if (($args['username'] ?? '') === '' || $a['username'] === $args['username']) {
                        $id = self::snapshotId($n, (string) $a['username']);
                        $out[] = ['id' => $id, 'short_id' => \substr($id, 0, 8), 'time' => \sprintf('2026-11-18T02:%02d:00Z', $n % 60), 'username' => $a['username'], 'files' => 12, 'bytes' => 4096];
                    }
                }
            }

            return ['status' => 'done', 'result' => $out];
        };
        $answers['backup.files'] = function (array $args): array {
            if ($this->down) {
                throw FakeAgent::unavailable();
            }

            $snap = $this->snapshotOf((string) $args['snapshot']);

            if ($snap === null || $snap['username'] !== $args['username']) {
                return ['status' => 'failed', 'error' => ['code' => 'backup_not_in_snapshot', 'message' => 'snapshot ' . $args['snapshot'] . ' is not ' . $args['username'] . '\'s']];
            }

            $dbs = [];

            foreach ($snap['meta']['tables']['db_databases'] ?? [] as $db) {
                $dbs[] = (string) $db['name'];
            }

            $entries = $args['path'] === ''
                ? [['name' => 'public_html', 'type' => 'dir', 'size' => 0, 'mtime' => '2026-11-17T10:00:00Z'], ['name' => '.bashrc', 'type' => 'file', 'size' => 120, 'mtime' => '2026-11-17T10:00:00Z']]
                : [['name' => 'index.html', 'type' => 'file', 'size' => 2048, 'mtime' => '2026-11-17T10:00:00Z']];

            return ['status' => 'done', 'result' => ['snapshot' => ['id' => $args['snapshot']], 'path' => $args['path'], 'entries' => $entries, 'databases' => $args['path'] === '' ? $dbs : [], 'truncated' => false]];
        };
        $answers['backup.meta'] = function (array $args): array {
            if ($this->down) {
                throw FakeAgent::unavailable();
            }

            $snap = $this->snapshotOf((string) $args['snapshot']);

            return $snap === null
                ? ['status' => 'failed', 'error' => ['code' => 'backup_not_in_snapshot', 'message' => 'there is no snapshot ' . $args['snapshot']]]
                : ['status' => 'done', 'result' => ['snapshot' => ['id' => $args['snapshot'], 'short_id' => \substr((string) $args['snapshot'], 0, 8), 'time' => '2026-11-18T02:30:00Z', 'username' => $snap['username']], 'meta' => $snap['meta']]];
        };
        $answers['firewall.info'] = fn(): array => $this->down ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => [
            'enabled' => $this->fw['enabled'], 'running' => $this->fw['enabled'] && $this->fw['running'], 'version' => '1.3.4', 'zone' => 'public', 'zones' => ['public'],
            'ports' => $this->fw['running'] ? \array_map(static fn(array $p): array => $p + ['via' => ''], $this->fw['ports']) : [], 'services' => [],
            'allow' => $this->fw['allow'], 'deny' => $this->fw['deny'], 'countries' => $this->fw['countries'],
            'cached' => \array_map(fn(string $c): array => ['code' => $c, 'v4' => \count($this->fw['nets'][$c] ?? []), 'v6' => 0, 'fetched' => '2026-11-23T00:00:00Z'], $this->fw['countries']),
            'rules' => $this->fw['allow'] !== [] || $this->fw['deny'] !== [] || $this->fw['countries'] !== [], 'keep' => $this->fw['keep'],
        ]];
        $answers['tasks.list'] = function (array $args): array {
            if ($this->down) {
                throw FakeAgent::unavailable();
            }

            $list = \array_values(\array_filter($this->tasks, static fn(array $t): bool => ($args['state'] ?? '') === '' || $t['state'] === $args['state']));

            return ['status' => 'done', 'result' => ['tasks' => \array_slice($list, 0, (int) ($args['limit'] ?? 50))]];
        };
        $answers['updates.panel_status'] = fn(): array => $this->down ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => $this->upgradeStatus];
        $answers['services.status'] = fn(): array => $this->down ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => ['services' => $this->serviceList()]];
        $answers['isolation.info'] = fn(): array => $this->down ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => $this->isolationInfo()];
        $answers['waf.info'] = fn(): array => $this->down ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => [
            'available' => $this->waf['available'], 'server' => $this->waf['server'], 'rules' => $this->waf['rules'], 'paranoia' => $this->waf['paranoia'],
        ]];
        $answers['waf.events'] = function (array $args): array {
            if ($this->down) {
                throw FakeAgent::unavailable();
            }

            $this->wafScans[] = $args;
            $events = $this->wafEvents;
            $this->wafEvents = [];

            return ['status' => 'done', 'result' => ['events' => $events, 'cursors' => ['shop' => ['offset' => \count($this->wafScans) * 10, 'head' => 'w1']]]];
        };
        $answers['authlog.scan'] = function (array $args): array {
            if ($this->down) {
                throw FakeAgent::unavailable();
            }

            $this->authScans[] = $args;
            $failures = $this->authFailures;
            $this->authFailures = [];

            return ['status' => 'done', 'result' => ['failures' => $failures, 'cursors' => ['ssh' => ['offset' => \count($this->authScans) * 100, 'head' => 'h1']], 'missing' => ['ftp', 'mail', 'smtp'], 'lines' => 0]];
        };
        $answers['ftp.info'] = fn(): array => $this->down ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => [
            'enabled' => true, 'installed' => true, 'running' => true, 'version' => '1.0.52',
            'logins' => \array_sum(\array_map(static fn(array $s): int => $s['suspended'] ? 0 : \count($s['users']), $this->ftp)),
            'settings' => $this->ftpSettings ?? [], 'sessions' => $this->ftpSessions, 'sessions_known' => true,
        ]];

        foreach (['files.write', 'files.upload', 'files.mkdir', 'files.create', 'files.rename', 'files.move', 'files.copy', 'files.delete', 'files.chmod', 'files.compress', 'files.extract'] as $op) {
            $answers[$op] = fn(array $args, string $id): array => $this->change($op, $args, $id);
        }

        foreach (['files.list', 'files.read', 'files.usage', 'files.download'] as $op) {
            $answers[$op] = fn(array $args): array => $this->down ? throw FakeAgent::unavailable() : $this->files($op, $args);
        }

        $answers['account.info'] = fn(array $args): array => $this->read($args);
        $answers['web.info'] = fn(): array => $this->down ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => [
            'server' => 'apache', 'installed' => true, 'running' => true, 'accounts' => \count($this->sites),
            'php' => \array_map(fn(string $v): array => ['version' => $v, 'pools' => 0, 'running' => true], $this->php),
        ]];
        $answers['php.info'] = fn(): array => $this->down ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => $this->phpInfo()];
        $answers['web.traffic'] = fn(): array => $this->down ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => ['accounts' => (object) $this->traffic, 'more' => false]];
        $answers['system.facts'] = fn(): array => $this->facts === null ? throw FakeAgent::unavailable() : ['status' => 'done', 'result' => $this->facts];
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

    /** Put a service in a state (active, inactive, failed) as if something outside the panel did it. */
    public function service(string $unit, string $active): void
    {
        $this->svc[$unit]['active'] = $active;
    }

    /** @return list<array<string, mixed>> */
    private function serviceList(): array
    {
        $out = [];

        foreach ($this->svc as $unit => $s) {
            $out[] = ['unit' => $unit, 'label' => $s['label'], 'group' => $s['group'], 'allowed' => $s['allowed'], 'installed' => true, 'active' => $s['active'], 'sub' => $s['active'] === 'active' ? 'running' : 'dead', 'enabled' => $s['enabled'], 'since' => $s['active'] === 'active' ? '2026-11-24T08:00:00Z' : '', 'memory_bytes' => 1048576, 'restarts' => 0];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function isolationInfo(): array
    {
        $v = $this->isolation['links'] ? [1, 1, 2, 2] : [1, 1, 2, 1];
        $links = [];

        foreach (['fs.protected_symlinks' => 1, 'fs.protected_hardlinks' => 1, 'fs.protected_regular' => 2, 'fs.protected_fifos' => 2] as $name => $want) {
            $links[] = ['name' => $name, 'value' => $v[\count($links)], 'want' => $want];
        }

        return [
            'hidepid' => $this->isolation['hidepid'], 'mode' => $this->isolation['hidepid'] ? 'invisible' : '0', 'hidepid_kept' => $this->isolation['hidepid'],
            'links' => $links, 'links_ok' => $this->isolation['links'], 'links_kept' => $this->isolation['links'],
        ];
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
        $async = \str_starts_with($op, 'php.') || \in_array($op, ['ssl.issue', 'ssl.host_issue', 'backup.run', 'backup.restore', 'backup.download', 'updates.check', 'updates.apply', 'updates.panel'], true);

        if (\in_array($op, ['updates.check', 'updates.apply', 'updates.panel'], true)) {
            $this->updateIds[] = $id;
        }

        if (\in_array($op, ['backup.run', 'backup.restore', 'backup.download'], true)) {
            $this->backupIds[] = $id;
        }

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

        if (\str_starts_with($op, 'files.')) {
            return $this->files($op, $args);
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

        if (\str_starts_with($op, 'ftp.')) {
            return $this->runFtp($op, $args);
        }

        if (\str_starts_with($op, 'firewall.')) {
            return $this->runFirewall($op, $args);
        }

        if ($op === 'updates.check') {
            return ['status' => 'done', 'result' => $this->updatesFound];
        }

        if ($op === 'updates.apply') {
            $this->updatesApplied[] = $args;
            $names = match ((string) $args['scope']) {
                'security' => \array_column(\array_filter($this->updatesFound['packages'], static fn(array $p): bool => $p['kind'] === 'security'), 'name'),
                'php' => \array_values(\array_filter(\array_column($this->updatesFound['packages'], 'name'), static fn(string $n): bool => \str_starts_with($n, 'php'))),
                'packages' => (array) $args['packages'],
                default => \array_column($this->updatesFound['packages'], 'name'),
            };
            $upgraded = [];

            foreach ($this->updatesFound['packages'] as $p) {
                if (\in_array($p['name'], $names, true)) {
                    $upgraded[] = ['name' => $p['name'], 'arch' => $p['arch'], 'from' => $p['from'], 'to' => $p['to']];
                }
            }

            return ['status' => 'done', 'result' => ['scope' => $args['scope'], 'upgraded' => $upgraded, 'restarted' => ['httpd.service'], 'restart_failed' => [], 'soon' => [], 'pending' => [], 'reboot' => $this->updatesFound['reboot']]];
        }

        if ($op === 'updates.panel') {
            $this->upgrades[] = $args;
            $latest = (string) $this->updatesFound['panel']['latest'];

            if ($args['version'] !== $latest) {
                return ['status' => 'failed', 'error' => ['code' => 'updates_release', 'message' => 'the channel now offers ' . $latest . ', not ' . $args['version']]];
            }

            $this->upgradeStatus = ['state' => 'starting', 'target' => $latest, 'from' => '0.1.0', 'started' => '2026-11-24T03:00:00Z', 'finished' => '', 'error' => '', 'running' => true, 'installed' => '0.1.0', 'log' => []];

            return ['status' => 'done', 'result' => ['target' => $latest, 'from' => '0.1.0', 'unit' => 'lpanel-upgrade']];
        }

        if ($op === 'updates.reboot') {
            $this->reboots[] = (bool) $args['cancel'];

            return ['status' => 'done', 'result' => ['known' => true, 'needed' => false, 'why' => [], 'scheduled' => !$args['cancel']]];
        }
        if ($op === 'cron.apply') {
            $this->cronApplied[] = $args;

            if ($this->cronRefuse !== null) {
                return ['status' => 'failed', 'error' => ['code' => 'bad_args', 'message' => $this->cronRefuse]];
            }

            $user = (string) $args['username'];

            if ($args['suspended'] || $args['jobs'] === []) {
                unset($this->cron[$user]);
            } else {
                $this->cron[$user] = $args['jobs'];
            }

            return ['status' => 'done', 'result' => ['username' => $user, 'jobs' => \count($this->cron[$user] ?? []), 'changed' => true]];
        }

        if ($op === 'services.act') {
            $unit = (string) $args['unit'];
            $action = (string) $args['action'];
            $this->serviceActs[] = $unit . ' ' . $action;

            if (!isset($this->svc[$unit])) {
                return ['status' => 'failed', 'error' => ['code' => 'bad_args', 'message' => $unit . ' is not a service LPanel manages']];
            }

            if (!\in_array($action, $this->svc[$unit]['allowed'], true)) {
                return ['status' => 'failed', 'error' => ['code' => 'service_refused', 'message' => $unit . ' cannot be ' . $action . 'ed from here: the panel depends on it']];
            }

            if (\in_array($unit, $this->svcFailRestart, true) && $action === 'restart') {
                return ['status' => 'failed', 'error' => ['code' => 'service_failed', 'message' => $unit . ' could not be restarted: the control process exited with error code']];
            }

            match ($action) {
                'start', 'restart' => $this->svc[$unit]['active'] = 'active',
                'stop' => $this->svc[$unit]['active'] = 'inactive',
                'enable' => $this->svc[$unit]['enabled'] = true,
                'disable' => $this->svc[$unit]['enabled'] = false,
                default => null,
            };

            return ['status' => 'done', 'result' => \current(\array_filter($this->serviceList(), static fn(array $x): bool => $x['unit'] === $unit))];
        }

        if ($op === 'isolation.apply') {
            if ($this->isolation['refuse']) {
                return ['status' => 'failed', 'error' => ['code' => 'isolation_proc', 'message' => '/proc could not hide other users\' processes: permission denied']];
            }

            $this->isolation['hidepid'] = (bool) $args['hidepid'];
            $this->isolation['links'] = (bool) $args['links'];
            $this->isolationApplied[] = $args;

            return ['status' => 'done', 'result' => $this->isolationInfo()];
        }

        if ($op === 'waf.settings') {
            if (!$this->waf['available'] || $this->waf['server'] === 'nginx') {
                return ['status' => 'failed', 'error' => ['code' => 'waf_unavailable', 'message' => 'the web application firewall needs Apache']];
            }

            $this->waf['paranoia'] = (int) $args['paranoia'];

            return ['status' => 'done', 'result' => ['available' => true, 'server' => $this->waf['server'], 'rules' => $this->waf['rules'], 'paranoia' => $this->waf['paranoia']]];
        }

        if (\str_starts_with($op, 'backup.')) {
            return $this->runBackup($op, $args);
        }

        if ($op === 'ssh.apply') {
            if (!isset($this->users[$name])) {
                return ['status' => 'failed', 'error' => ['code' => 'no_such_account', 'message' => 'there is no user named ' . $name]];
            }

            foreach ($args['keys'] as $k) {
                if ($this->sshRefuse || !\in_array($k['type'] ?? '', ['ssh-ed25519', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'ssh-rsa', 'sk-ssh-ed25519@openssh.com', 'sk-ecdsa-sha2-nistp256@openssh.com'], true)
                    || \base64_decode((string) $k['key'], true) === false || \preg_match('/[\r\n]/', (string) $k['comment']) === 1) {
                    return ['status' => 'failed', 'error' => ['code' => 'bad_args', 'message' => 'key 1: the key is not a valid SSH public key']];
                }
            }

            if ($args['keys'] === []) {
                unset($this->sshKeys[$name]);
            } else {
                $this->sshKeys[$name] = $args['keys'];
            }

            return ['status' => 'done', 'result' => ['keys' => \count($args['keys']), 'fingerprints' => []]];
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
            unset($this->users[$name], $this->sites[$name], $this->dbs[$name], $this->sso[$name], $this->mail[$name], $this->ftp[$name], $this->sshKeys[$name]);
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
     * The FTP operations, checked the way the agent checks them.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function runFtp(string $op, array $args): array
    {
        $fail = static fn(string $code, string $message): array => ['status' => 'failed', 'error' => ['code' => $code, 'message' => $message]];

        if ($op === 'ftp.settings') {
            if (!\in_array($args['tls'] ?? '', ['optional', 'required', 'strict'], true) || !\is_int($args['passive_from'] ?? null) || !\is_int($args['max_per_ip'] ?? null)) {
                return $fail('bad_args', 'not settings');
            }

            $this->ftpSettings = $args;

            return ['status' => 'done', 'result' => ['changed' => true, 'running' => true]];
        }

        if ($op === 'ftp.kill') {
            foreach ($this->ftpSessions as $s) {
                if ($s['pid'] === $args['pid'] && (!isset($args['username']) || $s['account'] === $args['username'])) {
                    $this->ftpSessions = \array_values(\array_filter($this->ftpSessions, static fn(array $o): bool => $o['pid'] !== $args['pid']));
                    $this->ftpKilled[] = (int) $args['pid'];

                    return ['status' => 'done', 'result' => ['ended' => true, 'login' => $s['login']]];
                }
            }

            return $fail('ftp_no_session', 'no such FTP session');
        }

        $name = (string) $args['username'];

        if (!isset($this->users[$name])) {
            return $fail('no_such_account', 'there is no user named ' . $name);
        }

        foreach ($args['users'] as $u) {
            if (\preg_match('/^\$2[aby]\$\d\d\$[.\/A-Za-z0-9]{53}$/', (string) $u['hash']) !== 1) {
                return $fail('bad_args', 'not a bcrypt hash');
            }

            if (\preg_match('/^[a-z0-9._-]+@[a-z0-9.-]+$/', (string) $u['login']) !== 1 || \str_contains((string) $u['folder'], '..') || \str_starts_with((string) $u['folder'], '/')) {
                return $fail('bad_args', 'not a login or folder');
            }

            foreach ($this->ftp as $other => $state) {
                if ($other !== $name && \in_array($u['login'], \array_column($state['users'], 'login'), true)) {
                    return $fail('ftp_taken', $u['login'] . ' is another account\'s login');
                }
            }
        }

        // Sessions of logins that went, or of a suspended account, end.
        $logins = $args['suspended'] ? [] : \array_column($args['users'], 'login');
        $before = \count($this->ftpSessions);
        $this->ftpSessions = \array_values(\array_filter($this->ftpSessions, static fn(array $s): bool => $s['account'] !== $name || \in_array($s['login'], $logins, true)));
        $ended = $before - \count($this->ftpSessions);
        $this->ftp[$name] = $args;

        return ['status' => 'done', 'result' => ['users' => \count($logins), 'ended' => $ended]];
    }

    /**
     * The backup operations, checked as the agent checks them.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function runBackup(string $op, array $args): array
    {
        $fail = static fn(string $code, string $message): array => ['status' => 'failed', 'error' => ['code' => $code, 'message' => $message]];

        if ($op === 'backup.destination') {
            if (\preg_match('/^d\d+$/', (string) ($args['id'] ?? '')) !== 1 || !\in_array($args['type'] ?? '', ['local', 'sftp', 's3', 'b2'], true)) {
                return $fail('bad_args', 'not a destination');
            }

            if ($this->backupUnreachable) {
                return $fail('backup_unreachable', 'the destination did not answer: dial tcp: i/o timeout');
            }

            $old = $this->backupDestinations[$args['id']] ?? [];
            $created = $old === [];
            $this->backupDestinations[$args['id']] = $args + \array_intersect_key($old, ['access_key' => 0, 'secret_key' => 0]);

            return ['status' => 'done', 'result' => [
                'destination' => \array_diff_key($args, ['access_key' => 0, 'secret_key' => 0]),
                'created' => $created,
                'public_key' => $args['type'] === 'sftp' ? 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeKeyForTests lpanel-backup' : '',
                'snapshots' => 0,
            ]];
        }

        if ($op === 'backup.destination_remove') {
            if (!isset($this->backupDestinations[$args['id'] ?? ''])) {
                return $fail('backup_no_destination', 'there is no destination ' . ($args['id'] ?? ''));
            }

            unset($this->backupDestinations[$args['id']]);

            return ['status' => 'done', 'result' => ['removed' => true]];
        }

        if (!isset($this->backupDestinations[$args['destination'] ?? ''])) {
            return $fail('backup_no_destination', 'there is no destination');
        }

        if ($op === 'backup.restore' || $op === 'backup.download') {
            $snap = $this->snapshotOf((string) $args['snapshot']);

            if ($snap === null || $snap['username'] !== $args['username']) {
                return $fail('backup_not_in_snapshot', 'snapshot ' . $args['snapshot'] . ' is not ' . $args['username'] . '\'s');
            }

            if (!isset($this->users[$args['username']])) {
                return $fail('no_such_account', 'there is no user named ' . $args['username']);
            }

            $this->backupRestores[] = ['op' => $op] + $args;

            return ['status' => 'done', 'result' => $op === 'backup.download'
                ? ['file' => 'lpanel-backups/' . (($args['database'] ?? '') !== '' ? $args['database'] . '-20261118-023000.sql.gz' : $args['username'] . '-20261118-023000.tar.gz'), 'bytes' => 4096]
                : ['files' => \count($args['paths']) * 3, 'bytes' => 6144, 'skipped' => 0, 'folder' => ($args['folder'] ?? false) ? 'restored-20261119-101500' : '', 'databases' => $args['databases'], 'warnings' => []]];
        }

        // backup.run

        $this->backupRuns[] = $args;
        $out = ['accounts' => [], 'ok' => 0, 'failed' => 0, 'pruned' => (bool) $args['prune']];

        foreach ($args['accounts'] as $a) {
            $why = !isset($this->users[$a['username']]) ? 'there is no user named ' . $a['username'] : ($this->backupFail[$a['username']] ?? null);
            $out['accounts'][] = $why === null
                ? ['username' => $a['username'], 'ok' => true, 'snapshot' => \hash('sha256', $a['username'] . \count($this->backupRuns)), 'files' => 12, 'bytes' => 4096, 'added' => 1024, 'databases' => 0, 'seconds' => 0.5, 'forgotten' => 0]
                : ['username' => $a['username'], 'ok' => false, 'error' => $why];
            $out[$why === null ? 'ok' : 'failed']++;
        }

        return ['status' => 'done', 'result' => $out];
    }

    /**
     * A snapshot backup.snapshots listed, as the run that made it had it.
     *
     * @return array{username: string, meta: array<string, mixed>}|null
     */
    private function snapshotOf(string $id): ?array
    {
        foreach ($this->backupRuns as $n => $run) {
            foreach ($run['accounts'] as $a) {
                $full = self::snapshotId($n, (string) $a['username']);

                if ($id === $full || $id === \substr($full, 0, 8)) {
                    return ['username' => (string) $a['username'], 'meta' => \is_array($a['meta']) ? $a['meta'] : []];
                }
            }
        }

        return null;
    }

    /** The id of the snapshot run $n made of an account. */
    public static function snapshotId(int $n, string $username): string
    {
        return \hash('sha256', $n . '/' . $username);
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

    /**
     * files.*: the agent's file operations over the in-memory homes. Paths
     * arrive clean (the panel cleans them first); errors carry the agent's codes.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function files(string $op, array $args): array
    {
        $user = (string) $args['username'];
        $this->homes[$user] ??= ['public_html' => null, 'mail' => null, 'tmp' => null, 'logs' => null];
        $home = &$this->homes[$user];
        $fail = static fn(string $code, string $message = ''): array => ['status' => 'failed', 'error' => ['code' => $code, 'message' => $message !== '' ? $message : $code]];
        $done = static fn(array $result): array => ['status' => 'done', 'result' => $result];
        $isDir = static fn(string $p): bool => $p === '' || (\array_key_exists($p, $home) && $home[$p] === null);
        $parent = static fn(string $p): string => \str_contains($p, '/') ? \substr($p, 0, (int) \strrpos($p, '/')) : '';
        $join = static fn(string $d, string $n): string => $d === '' ? $n : $d . '/' . $n;
        $protected = static fn(string $p): bool => \in_array($p, ['public_html', 'mail', 'tmp', 'logs'], true);
        $staged = fn(string $token): string => $this->staging . '/' . $token;

        switch ($op) {
            case 'files.list':
                $dir = (string) $args['path'];

                if (!$isDir($dir)) {
                    return \array_key_exists($dir, $home) ? $fail('files_type') : $fail('files_missing', '/' . $dir . ' is not there');
                }

                $entries = [];

                foreach ($home as $p => $content) {
                    if ($parent((string) $p) === $dir) {
                        $entries[] = ['name' => \basename((string) $p), 'type' => $content === null ? 'dir' : 'file', 'size' => $content === null ? 0 : \strlen($content), 'mode' => $content === null ? '0755' : '0644', 'mtime' => 1763337600];
                    }
                }

                \usort($entries, static fn(array $a, array $b): int => [$a['type'] !== 'dir', \strtolower($a['name'])] <=> [$b['type'] !== 'dir', \strtolower($b['name'])]);

                return $done(['path' => $dir === '' ? '.' : $dir, 'mode' => '0711', 'entries' => $entries, 'truncated' => false]);

            case 'files.read':
                $p = (string) $args['path'];

                if (!\array_key_exists($p, $home)) {
                    return $fail('files_missing');
                }

                if ($home[$p] === null) {
                    return $fail('files_type');
                }

                $binary = \str_contains($home[$p], "\0");

                return $done(['path' => $p, 'size' => \strlen($home[$p]), 'mtime' => 1763337600, 'mode' => '0644', 'sha256' => \hash('sha256', $home[$p]), 'binary' => $binary, 'content' => $binary ? '' : $home[$p]]);

            case 'files.write':
                $p = (string) $args['path'];

                if (!$isDir($parent($p))) {
                    return $fail('files_missing');
                }

                if (\array_key_exists($p, $home) && ($args['create'] ?? false) === true) {
                    return $fail('files_exists');
                }

                if (($args['expect'] ?? '') !== '' && \hash('sha256', (string) ($home[$p] ?? '')) !== $args['expect']) {
                    return $fail('files_changed');
                }

                $home[$p] = (string) \file_get_contents($staged((string) $args['staged']));

                return $done(['path' => $p, 'size' => \strlen($home[$p]), 'sha256' => \hash('sha256', $home[$p])]);

            case 'files.upload':
                $p = $join((string) $args['dir'], (string) $args['name']);

                if (!$isDir((string) $args['dir'])) {
                    return $fail('files_missing');
                }

                if (\array_key_exists($p, $home) && ($args['overwrite'] ?? false) !== true) {
                    return $fail('files_exists');
                }

                $home[$p] = (string) \file_get_contents($staged((string) $args['staged']));

                return $done(['path' => $p, 'size' => \strlen($home[$p])]);

            case 'files.download':
                $p = (string) $args['path'];

                if (!\array_key_exists($p, $home)) {
                    return $fail('files_missing');
                }

                if ($home[$p] === null) {
                    return $fail('files_type');
                }

                \file_put_contents($staged((string) $args['into']), $home[$p]);

                return $done(['name' => \basename($p), 'size' => \strlen($home[$p])]);

            case 'files.mkdir':
            case 'files.create':
                $p = (string) $args['path'];

                if (\array_key_exists($p, $home)) {
                    return $fail('files_exists');
                }

                if (!$isDir($parent($p))) {
                    return $fail('files_missing');
                }

                $home[$p] = $op === 'files.mkdir' ? null : '';

                return $done(['path' => $p]);

            case 'files.rename':
            case 'files.move':
            case 'files.copy':
                $pairs = $op === 'files.rename' ? [(string) $args['from'] => (string) $args['to']] : [];

                foreach ($op === 'files.rename' ? [] : (array) $args['paths'] as $p) {
                    if (!$isDir((string) $args['to'])) {
                        return $fail('files_type');
                    }

                    $pairs[(string) $p] = $join((string) $args['to'], \basename((string) $p));
                }

                foreach ($pairs as $from => $to) {
                    if ($op !== 'files.copy' && $protected((string) $from)) {
                        return $fail('files_protected');
                    }

                    if (!\array_key_exists((string) $from, $home)) {
                        return $fail('files_missing');
                    }

                    if (\array_key_exists($to, $home)) {
                        return $fail('files_exists');
                    }
                }

                foreach ($pairs as $from => $to) {
                    foreach ($home as $p => $content) {
                        $p = (string) $p;

                        if ($p === (string) $from || \str_starts_with($p, $from . '/')) {
                            $home[$to . \substr($p, \strlen((string) $from))] = $content;

                            if ($op !== 'files.copy') {
                                unset($home[$p]);
                            }
                        }
                    }
                }

                return $done($op === 'files.rename' ? ['from' => $args['from'], 'to' => $args['to']] : ['count' => \count($pairs), 'skipped' => 0]);

            case 'files.delete':
                $count = 0;

                foreach ((array) $args['paths'] as $d) {
                    if ($protected((string) $d)) {
                        return $fail('files_protected');
                    }

                    foreach (\array_keys($home) as $p) {
                        if ((string) $p === $d || \str_starts_with((string) $p, $d . '/')) {
                            unset($home[$p]);
                            $count += (string) $p === $d ? 1 : 0;
                        }
                    }
                }

                return $done(['count' => $count, 'skipped' => \count((array) $args['paths']) - $count]);

            case 'files.chmod':
                if (\preg_match('/^0?[0-7]{3}$/', (string) $args['mode']) !== 1) {
                    return $fail('bad_args', 'permissions must be three octal digits such as 644 or 755');
                }

                return $done(['count' => \count((array) $args['paths']), 'skipped' => 0]);

            case 'files.compress':
                $home[(string) $args['to']] = 'PK-archive';

                return $done(['path' => $args['to'], 'size' => 10, 'items' => \count((array) $args['paths'])]);

            case 'files.extract':
                $home[$join((string) $args['to'], 'extracted.txt')] = 'x';

                return $done(['to' => $args['to'], 'files' => 1, 'dirs' => 0, 'bytes' => 1, 'skipped' => 0]);

            case 'files.usage':
                $dir = (string) $args['path'];
                $entries = [];
                $total = 0;

                foreach ($home as $p => $content) {
                    if ($parent((string) $p) !== $dir) {
                        continue;
                    }

                    $bytes = 0;
                    $items = 0;

                    foreach ($home as $q => $c) {
                        if ((string) $q === (string) $p || \str_starts_with((string) $q, $p . '/')) {
                            $bytes += \strlen((string) $c);
                            $items += (string) $q === (string) $p ? 0 : 1;
                        }
                    }

                    $entries[] = ['name' => \basename((string) $p), 'type' => $content === null ? 'dir' : 'file', 'bytes' => $bytes, 'items' => $items];
                    $total += $bytes;
                }

                \usort($entries, static fn(array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

                return $done(['path' => $dir === '' ? '.' : $dir, 'bytes' => $total, 'items' => \count($entries), 'entries' => $entries, 'truncated' => false]);
        }

        return $fail('unknown_op');
    }

    /**
     * firewall.*: the agent's rules, as far as the panel can see them: a deny
     * list or a country that holds an address in `protect` is refused, so is
     * closing a kept port or limiting one to addresses without it.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function runFirewall(string $op, array $args): array
    {
        $fail = static fn(string $code, string $message): array => ['status' => 'failed', 'error' => ['code' => $code, 'message' => $message]];

        if (!$this->fw['enabled'] || !$this->fw['running']) {
            return $fail('firewall_off', 'firewalld is not running');
        }

        $inside = static function (string $net, string $ip): bool {
            $parts = \explode('/', $net, 2);
            $base = $parts[0];
            $bits = $parts[1] ?? null;
            $a = \inet_pton($base);
            $b = \inet_pton($ip);

            if ($a === false || $b === false || \strlen($a) !== \strlen($b)) {
                return false;
            }

            $bits = $bits === null ? \strlen($a) * 8 : (int) $bits;

            for ($i = 0; $i < \strlen($a); $i++) {
                $keep = \max(0, \min(8, $bits - $i * 8));
                $mask = (0xFF << (8 - $keep)) & 0xFF;

                if ((\ord($a[$i]) & $mask) !== (\ord($b[$i]) & $mask)) {
                    return false;
                }
            }

            return true;
        };
        $any = static fn(array $nets, string $ip): bool => \array_reduce($nets, static fn(bool $c, string $n): bool => $c || $inside($n, $ip), false);

        if ($op === 'firewall.lists') {
            foreach ((array) ($args['protect'] ?? []) as $ip) {
                if ($any((array) $args['deny'], (string) $ip) && !$any((array) $args['allow'], (string) $ip)) {
                    return $fail('firewall_lockout', 'your address ' . $ip . ' is in the deny list: that would shut you out');
                }
            }

            $this->fw['allow'] = \array_values((array) $args['allow']);
            $this->fw['deny'] = \array_values((array) $args['deny']);

            return ['status' => 'done', 'result' => ['allow' => \count($this->fw['allow']), 'deny' => \count($this->fw['deny'])]];
        }

        if ($op === 'firewall.countries') {
            $nets = [];

            foreach ((array) $args['codes'] as $code) {
                if (!isset($this->fw['nets'][$code])) {
                    return $fail('firewall_fetch', 'the list for ' . $code . ' could not be fetched');
                }

                $nets = [...$nets, ...$this->fw['nets'][$code]];
            }

            foreach ((array) ($args['protect'] ?? []) as $ip) {
                if ($any($nets, (string) $ip) && !$any($this->fw['allow'], (string) $ip)) {
                    return $fail('firewall_lockout', 'your address ' . $ip . ' is in a country you chose: that would shut you out');
                }
            }

            $this->fw['countries'] = \array_values((array) $args['codes']);

            return ['status' => 'done', 'result' => ['codes' => $this->fw['countries'], 'v4' => \count($nets), 'v6' => 0, 'fetched' => []]];
        }

        // firewall.port
        $port = (string) $args['port'];
        $proto = (string) $args['proto'];
        $kept = \in_array((int) $port, $this->fw['keep'], true);

        if (!\preg_match('/^[0-9]{1,5}(-[0-9]{1,5})?$/', $port) || !\in_array($proto, ['tcp', 'udp'], true) || !\in_array($args['action'], ['open', 'close'], true)) {
            return $fail('bad_args', 'not a port');
        }

        if ($args['action'] === 'close' && $kept) {
            return $fail('firewall_kept', 'port ' . $port . ' is how the panel and SSH are reached: limit it to some addresses instead of closing it');
        }

        if ($args['action'] === 'open' && $kept && (array) $args['sources'] !== []) {
            foreach ((array) ($args['protect'] ?? []) as $ip) {
                if (!$any((array) $args['sources'], (string) $ip)) {
                    return $fail('firewall_lockout', 'your address ' . $ip . ' would no longer reach port ' . $port . ': add it to the addresses');
                }
            }
        }

        $this->fw['ports'] = \array_values(\array_filter($this->fw['ports'], static fn(array $p): bool => $p['port'] !== $port || $p['proto'] !== $proto));

        if ($args['action'] === 'open') {
            $this->fw['ports'][] = ['port' => $port, 'proto' => $proto, 'sources' => \array_values((array) $args['sources'])];
        }

        return ['status' => 'done', 'result' => ['port' => $port, 'proto' => $proto, 'sources' => $args['sources'], 'closed' => $args['action'] === 'close']];
    }
}
