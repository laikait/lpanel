<?php

declare(strict_types=1);

namespace App\Modules\Operations\Alerts;

use App\Engine\Database\ConnectionManager;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;

/**
 * What the server says about itself, looked at every five minutes: a
 * filesystem or memory nearly full, a load that stays high, a certificate that
 * AutoSSL has not renewed in time, the agent not answering. Each is a
 * condition (see Alerts::condition): told when it begins, once a day while it
 * lasts, and when it ends.
 */
final class AlertChecks
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly ConnectionManager $connections,
        private readonly Alerts $alerts,
        private readonly AlertSettings $settings,
    ) {}

    /**
     * Look once.
     *
     * @return array{raised: int, resolved: int} how many conditions began and ended
     */
    public function run(): array
    {
        $s = $this->settings->get();
        $seen = [];
        $facts = $this->facts();

        // The agent answering is itself a condition; the numbers need it.
        $seen['services.agent'] = [$facts === null, 'critical', 'The server agent is not answering', 'LPanel cannot manage this server until it does (systemctl status lpa).'];

        foreach ($facts === null ? [] : $this->resources($facts, $s) as $key => $row) {
            $seen[$key] = $row;
        }

        foreach ($this->certificates($s['ssl']) as $key => $row) {
            $seen[$key] = $row;
        }

        $out = ['raised' => 0, 'resolved' => 0];

        foreach ($seen as $key => [$firing, $severity, $title, $detail]) {
            $did = $this->alerts->condition(self::category($key), $key, $firing, $severity, $title, $detail);
            $out['raised'] += $did === 'raised' ? 1 : 0;
            $out['resolved'] += $did === 'resolved' ? 1 : 0;
        }

        // A condition for something that is no longer there (a certificate
        // whose site is gone, an unmounted disk) has ended too. With the agent
        // silent, nothing is concluded about the numbers it gives.
        foreach ($this->alerts->activeKeys('') as $key) {
            if (isset($seen[$key]) || ($facts === null && !\str_starts_with($key, 'ssl.'))) {
                continue;
            }

            if ($this->alerts->condition(self::category($key), $key, false, 'warning', self::titleOf($key)) === 'resolved') {
                $out['resolved']++;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function facts(): ?array
    {
        try {
            $reply = $this->agent->call('system.facts', timeout: 10.0);
        } catch (AgentUnavailable) {
            return null;
        }

        return $reply->done() ? $reply->result : null;
    }

    /**
     * @param array<string, mixed>                                              $facts
     * @param array{disk: int, memory: int, load: int, ssl: int, quiet: int}    $s
     *
     * @return array<string, array{0: bool, 1: string, 2: string, 3: string}>
     */
    private function resources(array $facts, array $s): array
    {
        $out = [];

        foreach (\is_array($facts['disks'] ?? null) ? $facts['disks'] : [] as $d) {
            if (!\is_array($d) || !isset($d['mount'])) {
                continue;
            }

            $used = (float) ($d['used_bytes'] ?? 0);
            $avail = (float) ($d['available_bytes'] ?? 0);
            $pct = $used + $avail > 0 ? (int) \round(100 * $used / ($used + $avail)) : 0;
            $mount = (string) $d['mount'];
            $out['resources.disk.' . $mount] = [
                $s['disk'] > 0 && $pct >= $s['disk'],
                $pct >= 97 ? 'critical' : 'warning',
                self::titleOf('resources.disk.' . $mount),
                \sprintf('%d%% used, %s free.', $pct, self::size((int) $avail)),
            ];
        }

        $mem = \is_array($facts['memory'] ?? null) ? $facts['memory'] : [];
        $total = (float) ($mem['total_bytes'] ?? 0);

        if ($total > 0) {
            $pct = (int) \round(100 * (1 - (float) ($mem['available_bytes'] ?? 0) / $total));
            $out['resources.memory'] = [$s['memory'] > 0 && $pct >= $s['memory'], $pct >= 97 ? 'critical' : 'warning', self::titleOf('resources.memory'), \sprintf('%d%% of %s in use.', $pct, self::size((int) $total))];
        }

        $cpus = \max(1, (int) ($facts['cpus'] ?? 1));
        $five = (float) (((array) ($facts['load'] ?? []))['five'] ?? 0);
        $out['resources.load'] = [$s['load'] > 0 && $five / $cpus >= $s['load'], 'warning', self::titleOf('resources.load'), \sprintf('Load average %.1f over five minutes on %d CPU%s.', $five, $cpus, $cpus === 1 ? '' : 's')];

        return $out;
    }

    /**
     * @return array<string, array{0: bool, 1: string, 2: string, 3: string}>
     */
    private function certificates(int $days): array
    {
        $out = [];

        if ($days <= 0) {
            return $out;
        }

        $rows = $this->connections->connection()->table('ssl_sites')
            ->select('ssl_sites.not_after', 'ssl_sites.autossl', 'domains.name as domain')
            ->join('domains', 'domains.id', '=', 'ssl_sites.domain_id')
            ->whereNotNull('ssl_sites.not_after')
            ->get();

        foreach ($rows as $r) {
            $left = (int) \floor(((int) \strtotime((string) $r['not_after'] . ' UTC') - \time()) / 86400);
            $domain = (string) $r['domain'];
            $out['ssl.' . $domain] = [
                $left < $days,
                $left < 0 ? 'critical' : 'warning',
                $left < 0 ? 'The certificate for ' . $domain . ' has expired' : self::titleOf('ssl.' . $domain),
                $left < 0 ? 'It expired on ' . \substr((string) $r['not_after'], 0, 10) . '.' : \sprintf('It expires in %d day%s (%s).', $left, $left === 1 ? '' : 's', \substr((string) $r['not_after'], 0, 10)) . ((bool) $r['autossl'] ? ' AutoSSL could not renew it: see the site\'s SSL page.' : ''),
            ];
        }

        return $out;
    }

    private static function category(string $key): string
    {
        return \explode('.', $key, 2)[0];
    }

    /** A condition's title from its key, for when it ends and nothing else is known of it. */
    private static function titleOf(string $key): string
    {
        return match (true) {
            \str_starts_with($key, 'resources.disk.') => 'Disk ' . \substr($key, 15) . ' is nearly full',
            $key === 'resources.memory' => 'Memory is nearly all in use',
            $key === 'resources.load' => 'The server load is high',
            \str_starts_with($key, 'ssl.') => 'The certificate for ' . \substr($key, 4) . ' is about to expire',
            $key === 'services.agent' => 'The server agent is not answering',
            default => $key,
        };
    }

    private static function size(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $i => $unit) {
            if ($bytes < 1024 ** ($i + 1) || $unit === 'TB') {
                return \round($bytes / (1024 ** $i), $i > 1 ? 1 : 0) . ' ' . $unit;
            }
        }

        return $bytes . ' B';
    }
}
