<?php

declare(strict_types=1);

namespace App\Modules\Mail;

use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Dns\DnsZones;

/**
 * Deliverability: whether the world's DNS lets the account's mail through.
 * The agent asks (mail.check: SPF evaluated for this server's address, DKIM,
 * DMARC and MX of each domain; the address's reverse name and the
 * blocklists); here each finding becomes good, a warning or a problem, with
 * what to do about it.
 *
 * Each check is ['key' => …, 'state' => ok|warn|bad|info, 'text' => lang
 * key, 'args' => […], 'value' => what was found].
 */
final class MailChecks
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const BAD = 'bad';
    public const INFO = 'info';

    public function __construct(
        private readonly AgentClient $agent,
        private readonly Mailboxes $mail,
        private readonly DnsZones $zones,
        private readonly MailHosts $hosts,
    ) {}

    /**
     * Check the account's mail domains (or the domains named) and the
     * server's address.
     *
     * @param list<array<string, mixed>> $domains rows of mail_domains
     *
     * @return array{domains: list<array<string, mixed>>, server: array<string, mixed>, error: ?string}
     */
    public function run(array $domains, bool $lists = true): array
    {
        $ip = $this->zones->addresses()['ip4'];
        $hostname = $this->hosts->hostname();
        $names = \array_values(\array_slice(\array_map(static fn(array $d): string => (string) $d['name'], $domains), 0, 40));

        try {
            $reply = $this->agent->call('mail.check', ['domains' => $names, 'ip' => $ip, 'hostname' => $hostname, 'lists' => $lists], id: Protocol::newId(), timeout: 60.0);
        } catch (AgentUnavailable) {
            return ['domains' => [], 'server' => [], 'error' => 'Mail.error_unreachable'];
        }

        if (!$reply->done()) {
            return ['domains' => [], 'server' => [], 'error' => 'Mail.check_failed'];
        }

        $found = $reply->result;
        $relay = $this->mail->setting('smarthost');
        $ips = \is_array($found['ips'] ?? null) ? $found['ips'] : [];
        $address = $ip !== '' ? $ip : (string) (\array_key_first($ips) ?? '');
        $out = [];

        foreach ($domains as $d) {
            $name = (string) $d['name'];
            $c = \is_array($found['domains'][$name] ?? null) ? $found['domains'][$name] : [];
            $out[] = ['name' => $name, 'local' => (bool) ($d['local'] ?? true), 'checks' => $this->domainChecks($d, $c, $address, $relay)];
        }

        return ['domains' => $out, 'server' => $this->serverChecks(\is_array($ips[$address] ?? null) ? $ips[$address] : null, $address, $hostname, $relay), 'error' => null];
    }

    /**
     * @param array<string, mixed> $d a row of mail_domains
     * @param array<string, mixed> $c what the agent found
     *
     * @return list<array<string, mixed>>
     */
    private function domainChecks(array $d, array $c, string $ip, string $relay): array
    {
        if (($c['error'] ?? '') !== '') {
            return [self::check('dns', self::BAD, 'Mail.check_dns_failed', ['error' => (string) $c['error']])];
        }

        $checks = [];
        $spf = (string) ($c['spf'] ?? '');
        $result = (string) (((array) ($c['spf_result'] ?? []))[$ip] ?? '');
        $checks[] = match (true) {
            $spf === '' => self::check('spf', self::BAD, 'Mail.check_spf_none'),
            (int) ($c['spf_count'] ?? 1) > 1 => self::check('spf', self::BAD, 'Mail.check_spf_twice', [], $spf),
            $relay !== '' => self::check('spf', self::INFO, 'Mail.check_spf_relay', ['relay' => $relay], $spf),
            $ip === '' => self::check('spf', self::INFO, 'Mail.check_spf_noip', [], $spf),
            $result === 'pass' => self::check('spf', self::OK, 'Mail.check_spf_pass', ['ip' => $ip], $spf),
            \in_array($result, ['softfail', 'neutral'], true) => self::check('spf', self::WARN, 'Mail.check_spf_soft', ['ip' => $ip, 'result' => $result], $spf),
            $result === 'temperror' => self::check('spf', self::WARN, 'Mail.check_spf_temp', [], $spf),
            default => self::check('spf', self::BAD, 'Mail.check_spf_fail', ['ip' => $ip, 'result' => $result], $spf),
        };

        $dkim = (string) ($c['dkim'] ?? '');
        $ours = self::publicKey((string) ($d['dkim'] ?? ''));
        $checks[] = match (true) {
            $dkim === '' => self::check('dkim', self::BAD, 'Mail.check_dkim_none', ['name' => 'default._domainkey.' . $d['name']]),
            $ours !== '' && self::publicKey($dkim) !== $ours => self::check('dkim', self::BAD, 'Mail.check_dkim_other', ['name' => 'default._domainkey.' . $d['name']], $dkim),
            default => self::check('dkim', self::OK, 'Mail.check_dkim_ok', [], $dkim),
        };

        $dmarc = (string) ($c['dmarc'] ?? '');
        $policy = \preg_match('/(?:^|;)\s*p\s*=\s*(none|quarantine|reject)/i', $dmarc, $m) === 1 ? \strtolower($m[1]) : '';
        $checks[] = match (true) {
            $dmarc === '' => self::check('dmarc', self::BAD, 'Mail.check_dmarc_none'),
            $policy === '' => self::check('dmarc', self::BAD, 'Mail.check_dmarc_broken', [], $dmarc),
            $policy === 'none' => self::check('dmarc', self::OK, 'Mail.check_dmarc_none_policy', [], $dmarc),
            default => self::check('dmarc', self::OK, 'Mail.check_dmarc_ok', ['policy' => $policy], $dmarc),
        };

        $mx = \array_values(\array_filter((array) ($c['mx'] ?? []), 'is_array'));
        $hosts = \implode(', ', \array_map(static fn(array $m): string => (string) $m['host'], $mx));

        if (!(bool) ($d['local'] ?? true)) {
            $checks[] = self::check('mx', self::INFO, 'Mail.check_mx_elsewhere', [], $hosts);
        } else {
            $checks[] = match (true) {
                $mx === [] => self::check('mx', self::BAD, 'Mail.check_mx_none'),
                (bool) ($mx[0]['here'] ?? false) => self::check('mx', self::OK, 'Mail.check_mx_ok', ['host' => (string) $mx[0]['host']], $hosts),
                \array_filter($mx, static fn(array $m): bool => (bool) ($m['here'] ?? false)) !== [] => self::check('mx', self::WARN, 'Mail.check_mx_backup', ['host' => (string) $mx[0]['host']], $hosts),
                default => self::check('mx', self::BAD, 'Mail.check_mx_other', ['host' => (string) $mx[0]['host']], $hosts),
            };
        }

        return $checks;
    }

    /**
     * @param array<string, mixed>|null $c what the agent found of the address
     *
     * @return array<string, mixed>
     */
    private function serverChecks(?array $c, string $ip, string $hostname, string $relay): array
    {
        $checks = [];

        if ($relay !== '') {
            $checks[] = self::check('relay', self::INFO, 'Mail.check_relay_text', ['relay' => $relay]);
        }

        if ($c === null || $ip === '') {
            $checks[] = self::check('ptr', self::INFO, 'Mail.check_noip');

            return ['ip' => $ip, 'hostname' => $hostname, 'checks' => $checks, 'lists' => []];
        }

        if ((bool) ($c['private'] ?? false)) {
            $checks[] = self::check('ptr', self::INFO, 'Mail.check_private', ['ip' => $ip]);

            return ['ip' => $ip, 'hostname' => $hostname, 'checks' => $checks, 'lists' => []];
        }

        $ptr = \array_values(\array_map('strval', (array) ($c['ptr'] ?? [])));
        $checks[] = match (true) {
            $ptr === [] => self::check('ptr', self::BAD, 'Mail.check_ptr_none', ['ip' => $ip]),
            !(bool) ($c['forward'] ?? false) => self::check('ptr', self::BAD, 'Mail.check_ptr_forward', ['ip' => $ip, 'name' => $ptr[0]], \implode(', ', $ptr)),
            $hostname !== '' && !\in_array($hostname, $ptr, true) => self::check('ptr', self::WARN, 'Mail.check_ptr_hostname', ['name' => $ptr[0], 'hostname' => $hostname], \implode(', ', $ptr)),
            default => self::check('ptr', self::OK, 'Mail.check_ptr_ok', ['name' => $ptr[0]], \implode(', ', $ptr)),
        };

        $lists = [];

        foreach ((array) ($c['lists'] ?? []) as $list => $state) {
            $lists[] = ['name' => (string) $list, 'state' => match ((string) $state) {
                'listed' => self::BAD,
                'clear' => self::OK,
                default => self::INFO,
            }];
        }

        \usort($lists, static fn(array $a, array $b): int => \strcmp($a['name'], $b['name']));
        $listed = \array_values(\array_filter($lists, static fn(array $l): bool => $l['state'] === self::BAD));
        $checks[] = match (true) {
            $lists === [] => self::check('lists', self::INFO, 'Mail.check_lists_skipped'),
            $listed !== [] => self::check('lists', self::BAD, 'Mail.check_lists_listed', ['lists' => \implode(', ', \array_column($listed, 'name'))]),
            default => self::check('lists', self::OK, 'Mail.check_lists_clear', ['count' => (string) \count($lists)]),
        };

        return ['ip' => $ip, 'hostname' => $hostname, 'checks' => $checks, 'lists' => $lists];
    }

    /**
     * The worst state among checks, for a summary.
     *
     * @param list<array<string, mixed>> $checks
     */
    public static function worst(array $checks): string
    {
        $states = \array_column($checks, 'state');

        foreach ([self::BAD, self::WARN, self::OK] as $s) {
            if (\in_array($s, $states, true)) {
                return $s;
            }
        }

        return self::INFO;
    }

    /** A DKIM record's key (p=), without spaces; "" when it has none. */
    public static function publicKey(string $record): string
    {
        return \preg_match('/(?:^|;)\s*p\s*=\s*([A-Za-z0-9+\/=\s]+)/', $record, $m) === 1 ? (string) \preg_replace('/\s+/', '', $m[1]) : '';
    }

    /**
     * @param array<string, string> $args
     *
     * @return array{key: string, state: string, text: string, args: array<string, string>, value: string}
     */
    private static function check(string $key, string $state, string $text, array $args = [], string $value = ''): array
    {
        return ['key' => $key, 'state' => $state, 'text' => $text, 'args' => $args, 'value' => $value];
    }
}
