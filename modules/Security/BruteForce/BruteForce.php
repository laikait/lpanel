<?php

declare(strict_types=1);

namespace App\Modules\Security\BruteForce;

use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Http\Request;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Audit\AuditLog;
use App\Modules\Security\Firewall\Cidr;
use App\Modules\Security\Firewall\Entries;
use App\Modules\Security\Firewall\Firewall;

/**
 * Built-in brute-force blocking: too many failed sign-ins from one address
 * within a few minutes, on the panel, SSH, FTP, mail or SMTP, and the address
 * goes on the firewall's deny list for a while.
 *
 * The failures come from two places: the panel records its own (the
 * `auth.failed` hook), and the agent reads the services' logs
 * (`authlog.scan`, from a cursor kept here). Every minute the failures inside
 * the window are added up per address; an address over the limit is blocked
 * through the same Firewall class the pages use, so the block is on the
 * server before it is on the page, audited, and ends by itself. A repeat
 * offender is blocked for longer each time.
 *
 * Never blocked: an address on the allow list, and an address that is not a
 * public one the firewall would accept (loopback). An IPv6 attacker is blocked
 * as its /64, as that is what one customer holds.
 */
final class BruteForce
{
    /** What a block lasts, from the first and on to the next for a repeat offender. */
    public const LADDER = ['1h', '1d', '7d', '30d'];

    public const SERVICES = ['panel', 'ssh', 'ftp', 'mail', 'smtp'];

    public const DEFAULTS = ['enabled' => true, 'threshold' => 10, 'window' => 10, 'duration' => '1h'];

    /** How long failures and the memory of earlier blocks are kept, in seconds. */
    private const KEEP_FAILURES = 86400;

    private const KEEP_BANS = 2592000;

    public function __construct(
        private readonly AgentClient $agent,
        private readonly Entries $entries,
        private readonly Firewall $firewall,
        private readonly ConnectionManager $connections,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @return array{enabled: bool, threshold: int, window: int, duration: string}
     */
    public function settings(): array
    {
        $threshold = (int) ($this->entries->setting('bf_threshold') ?? self::DEFAULTS['threshold']);
        $window = (int) ($this->entries->setting('bf_window') ?? self::DEFAULTS['window']);
        $duration = (string) ($this->entries->setting('bf_duration') ?? self::DEFAULTS['duration']);

        return [
            'enabled' => ($this->entries->setting('bf_enabled') ?? '1') === '1',
            'threshold' => $threshold >= 3 && $threshold <= 1000 ? $threshold : self::DEFAULTS['threshold'],
            'window' => $window >= 1 && $window <= 1440 ? $window : self::DEFAULTS['window'],
            'duration' => \in_array($duration, self::LADDER, true) ? $duration : self::DEFAULTS['duration'],
        ];
    }

    /**
     * Change the settings (a missing key stays as it is).
     *
     * @param array<string, mixed> $changes
     *
     * @return string|null a language key saying what is wrong, or null when saved
     */
    public function configure(?Identity $actor, ?Request $request, array $changes): ?string
    {
        $now = $this->settings();

        if (isset($changes['threshold'])) {
            $t = $changes['threshold'];

            if (!\is_scalar($t) || !\ctype_digit((string) $t) || (int) $t < 3 || (int) $t > 1000) {
                return 'Security.bf_error_threshold';
            }

            $now['threshold'] = (int) $t;
        }

        if (isset($changes['window'])) {
            $w = $changes['window'];

            if (!\is_scalar($w) || !\ctype_digit((string) $w) || (int) $w < 1 || (int) $w > 1440) {
                return 'Security.bf_error_window';
            }

            $now['window'] = (int) $w;
        }

        if (isset($changes['duration'])) {
            if (!\in_array($changes['duration'], self::LADDER, true)) {
                return 'Security.bf_error_duration';
            }

            $now['duration'] = (string) $changes['duration'];
        }

        if (isset($changes['enabled'])) {
            $now['enabled'] = \filter_var($changes['enabled'], \FILTER_VALIDATE_BOOLEAN);
        }

        $this->entries->remember('bf_enabled', $now['enabled'] ? '1' : '0');
        $this->entries->remember('bf_threshold', (string) $now['threshold']);
        $this->entries->remember('bf_window', (string) $now['window']);
        $this->entries->remember('bf_duration', $now['duration']);
        $this->audit->record('bruteforce.settings', $actor, $request, 'bruteforce', AuditLog::OK, [
            'enabled' => $now['enabled'], 'threshold' => $now['threshold'], 'window' => $now['window'], 'duration' => $now['duration'],
        ]);

        return null;
    }

    /** One failed sign-in (or several) from an address, as the panel or the agent saw it. */
    public function record(string $ip, string $service, int $count = 1): void
    {
        $address = $this->address($ip);

        if ($address === null || !\in_array($service, self::SERVICES, true) || $count < 1) {
            return;
        }

        $this->connections->connection()->table('bruteforce_failures')->insert([
            'ip' => $address, 'service' => $service, 'count' => \min($count, 100), 'seen_at' => \gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Read the services' logs, count, block and tidy: what runs every minute.
     *
     * @return array{read: int, blocked: list<string>, missing: list<string>, error: string|null}
     */
    public function scan(): array
    {
        $out = ['read' => 0, 'blocked' => [], 'missing' => [], 'error' => null];

        if (!$this->settings()['enabled']) {
            return $out;
        }

        try {
            $reply = $this->agent->call('authlog.scan', ['cursors' => (object) $this->cursors()], timeout: 60.0);
        } catch (AgentUnavailable) {
            $out['error'] = 'Security.fw_error_unreachable';
            $reply = null;
        }

        if ($reply !== null && $reply->done()) {
            $result = $reply->result;

            foreach ((array) ($result['failures'] ?? []) as $f) {
                if (\is_array($f)) {
                    $this->record((string) ($f['ip'] ?? ''), (string) ($f['service'] ?? ''), (int) ($f['count'] ?? 0));
                    $out['read'] += (int) ($f['count'] ?? 0);
                }
            }

            $cursors = (array) ($result['cursors'] ?? []);
            $this->entries->remember('bf_cursors', (string) \json_encode($cursors === [] ? new \stdClass() : $cursors));
            $out['missing'] = \array_values(\array_map('strval', (array) ($result['missing'] ?? [])));
            $this->rememberMissing($out['missing']);
        } elseif ($reply !== null) {
            $out['error'] = (string) ($reply->errorMessage ?? 'Security.fw_error_failed');
        }

        $out['blocked'] = $this->block();
        $this->tidy();

        return $out;
    }

    /**
     * Block the addresses over the limit now.
     *
     * @return list<string> the networks blocked
     */
    public function block(): array
    {
        $s = $this->settings();

        if (!$s['enabled']) {
            return [];
        }

        $since = \gmdate('Y-m-d H:i:s', \time() - $s['window'] * 60);
        $rows = $this->connections->connection()->table('bruteforce_failures')->where('seen_at', '>=', $since)->get();
        $totals = [];

        foreach ($rows as $r) {
            $ip = (string) $r['ip'];
            $totals[$ip]['count'] = ($totals[$ip]['count'] ?? 0) + (int) $r['count'];
            $totals[$ip]['services'][(string) $r['service']] = true;
        }

        $allow = $this->entries->active(Entries::ALLOW);
        $blocked = [];

        foreach ($totals as $ip => $t) {
            if ($t['count'] < $s['threshold']) {
                continue;
            }

            $cidr = $this->network($ip);

            if ($cidr === null || $this->listed($allow, $cidr)) {
                continue;
            }

            if ($this->entries->findAddress(Entries::DENY, $cidr) !== null) {
                continue;
            }

            $services = \array_keys($t['services']);
            \sort($services);
            $duration = $this->durationFor($cidr, $s['duration']);
            $note = \sprintf('%d failed sign-ins on %s', $t['count'], \implode(', ', $services));
            [$ok] = $this->firewall->addEntry(null, null, Entries::DENY, $cidr, $note, $duration, 'bruteforce');

            if (!$ok) {
                continue;
            }

            $this->connections->connection()->table('bruteforce_bans')->insert([
                'cidr' => $cidr, 'services' => \substr(\implode(',', $services), 0, 40), 'failures' => $t['count'],
                'duration' => $duration, 'banned_at' => \gmdate('Y-m-d H:i:s'),
            ]);
            $blocked[] = $cidr;
        }

        return $blocked;
    }

    /** Forget old failures and old blocks. */
    public function tidy(): void
    {
        $db = $this->connections->connection();
        $db->table('bruteforce_failures')->where('seen_at', '<', \gmdate('Y-m-d H:i:s', \time() - self::KEEP_FAILURES))->delete();
        $db->table('bruteforce_bans')->where('banned_at', '<', \gmdate('Y-m-d H:i:s', \time() - self::KEEP_BANS))->delete();
    }

    /**
     * What the page shows: the last blocks, the busiest addresses in the window.
     *
     * @return array{bans: list<array<string, mixed>>, top: list<array{ip: string, count: int, services: list<string>}>, failures_hour: int, bans_day: int, missing: list<string>}
     */
    public function overview(): array
    {
        $db = $this->connections->connection();
        $s = $this->settings();
        $since = \gmdate('Y-m-d H:i:s', \time() - $s['window'] * 60);
        $top = [];

        foreach ($db->table('bruteforce_failures')->where('seen_at', '>=', $since)->get() as $r) {
            $ip = (string) $r['ip'];
            $top[$ip]['count'] = ($top[$ip]['count'] ?? 0) + (int) $r['count'];
            $top[$ip]['services'][(string) $r['service']] = true;
        }

        \uasort($top, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
        $rows = [];

        foreach (\array_slice($top, 0, 8, true) as $ip => $t) {
            $rows[] = ['ip' => (string) $ip, 'count' => $t['count'], 'services' => \array_map('strval', \array_keys($t['services']))];
        }

        $hour = (int) $db->table('bruteforce_failures')->where('seen_at', '>=', \gmdate('Y-m-d H:i:s', \time() - 3600))->sum('count');
        $day = $db->table('bruteforce_bans')->where('banned_at', '>=', \gmdate('Y-m-d H:i:s', \time() - 86400))->count();
        $missing = \json_decode($this->entries->setting('bf_missing') ?? '[]', true);

        return [
            'bans' => \array_values($db->table('bruteforce_bans')->orderByDesc('id')->limit(15)->get()),
            'top' => $rows,
            'failures_hour' => $hour,
            'bans_day' => $day,
            'missing' => \is_array($missing) ? \array_values(\array_map('strval', $missing)) : [],
        ];
    }

    /**
     * Remember which logs were missing at the last scan (the page says so).
     *
     * @param list<string> $missing
     */
    private function rememberMissing(array $missing): void
    {
        $this->entries->remember('bf_missing', (string) \json_encode(\array_values($missing)));
    }

    /** @return array<string, array<string, mixed>> */
    private function cursors(): array
    {
        $raw = \json_decode($this->entries->setting('bf_cursors') ?? '{}', true);

        return \is_array($raw) ? $raw : [];
    }

    /** The next duration for an address blocked before (within the memory), starting at the chosen one. */
    private function durationFor(string $cidr, string $base): string
    {
        $before = $this->connections->connection()->table('bruteforce_bans')->where('cidr', $cidr)->count();
        $start = (int) \array_search($base, self::LADDER, true);

        return self::LADDER[\min($start + $before, \count(self::LADDER) - 1)];
    }

    /** @param list<string> $list */
    private function listed(array $list, string $cidr): bool
    {
        foreach ($list as $entry) {
            if (Cidr::contains($entry, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /** An address as it is kept: lower case, IPv4 in IPv6 written as IPv4; null when it is not an address worth counting. */
    private function address(string $ip): ?string
    {
        if (\filter_var($ip, \FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = (string) \inet_pton($ip);

        if (\strlen($packed) === 16 && \str_starts_with($packed, \str_repeat("\0", 10) . "\xff\xff")) {
            $packed = \substr($packed, 12);
        }

        $text = (string) \inet_ntop($packed);

        return $text === '127.0.0.1' || $text === '::1' || $text === '0.0.0.0' || $text === '::' ? null : $text;
    }

    /** What is blocked for an address: itself, or an IPv6 address's /64. */
    private function network(string $ip): ?string
    {
        [$cidr] = Cidr::normalize(\str_contains($ip, ':') ? $ip . '/64' : $ip);

        return $cidr;
    }
}
