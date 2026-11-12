<?php

declare(strict_types=1);

namespace App\Modules\Web\Commands;

use App\Engine\Auth\Identity;
use App\Engine\Cli\Output;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Identity\PanelUsers;
use App\Modules\Web\AutoSsl;
use App\Modules\Web\Domains;
use App\Modules\Web\SslSites;
use App\Modules\Web\WebOutcome;

/**
 * ssl:* from the shell, as root: the same actions as the panels and the API.
 * Certificates and keys come on standard input, as PEM (key and chain in one
 * stream, in any order), since the panel's user cannot read root's files.
 */
final class SslCommands
{
    /** How long a command waits for an order before leaving it to ssl:autossl. */
    private const WAIT_SECONDS = 600;

    public function __construct(
        private readonly AutoSsl $ssl,
        private readonly SslSites $sites,
        private readonly Domains $domains,
        private readonly Accounts $accounts,
        private readonly PanelUsers $users,
        private readonly Localization $localization,
    ) {}

    /** Every site of an account, its certificate and AutoSSL's state. */
    public function list(Output $output, string $username): int
    {
        $account = $this->account($output, $username);

        if ($account === null) {
            return 1;
        }

        $this->ssl->settleAll();
        $rows = $this->sites->forAccount((int) $account['id']);
        $output->line(\sprintf('%-32s %-8s %-11s %-12s %-6s %-6s %s', 'DOMAIN', 'SOURCE', 'EXPIRES', 'ISSUER', 'AUTO', 'FORCE', 'STATE'));

        foreach ($this->ssl->namesOf($account) as $domainId => $names) {
            $s = $rows[$domainId] ?? null;
            $domain = $this->domains->find((int) $account['id'], $domainId);
            $running = $this->ssl->runningFor($domainId) !== null;
            $state = match (true) {
                $running => 'ordering',
                $s !== null && $s['last_error'] !== null => 'failed, next try ' . ($s['retry_at'] ?? 'now') . ': ' . $s['last_error'],
                $s !== null && $s['cert_id'] !== null => 'ok',
                default => 'no certificate',
            };
            $output->line(\sprintf(
                '%-32s %-8s %-11s %-12s %-6s %-6s %s',
                (string) ($domain['name'] ?? $names[0]),
                (string) ($s['source'] ?? '-'),
                $s !== null && $s['not_after'] !== null ? \substr((string) $s['not_after'], 0, 10) : '-',
                \mb_substr((string) ($s['issuer'] ?? '-'), 0, 12),
                $s === null || $s['autossl'] ? 'yes' : 'no',
                $s === null || $s['force_https'] ? 'yes' : 'no',
                $state,
            ));

            foreach ((array) ($s['excluded'] ?? []) as $e) {
                $output->line(\sprintf('    left out %s: %s', (string) ($e['name'] ?? ''), (string) ($e['reason'] ?? '')));
            }
        }

        return 0;
    }

    /** Order a site's certificate now, and wait for it. */
    public function issue(Output $output, string $username, string $domain, bool $noWait = false): int
    {
        $found = $this->site($output, $username, $domain);

        if ($found === null) {
            return 1;
        }

        [$account, $row] = $found;

        [$order, $why] = $this->ssl->issueNow($this->root(), null, $account, $row);

        return $this->follow($output, $order, $why, $noWait, (string) $row['name']);
    }

    /**
     * Install a certificate from standard input: the chain, and its key
     * unless it answers a request made with ssl:csr.
     */
    public function install(Output $output, string $username, string $domain): int
    {
        $found = $this->site($output, $username, $domain);

        if ($found === null) {
            return 1;
        }

        [$account, $row] = $found;

        $pem = (string) \stream_get_contents(\STDIN);
        $key = \str_contains($pem, 'PRIVATE KEY-----') ? $pem : '';
        [$ok, $why, $detail] = $this->ssl->install($this->root(), null, $account, $row, $key, $pem);

        if (!$ok) {
            $output->error($this->localization->get((string) $why, ['names' => (string) $detail]) . ($detail !== null ? ' (' . $detail . ')' : ''));

            return 2;
        }

        $s = $this->sites->forDomain((int) $row['id']) ?? [];
        $output->success(\sprintf('Installed for %s: %s, until %s.', $row['name'], $s['issuer'] ?? '', \substr((string) ($s['not_after'] ?? ''), 0, 10)));

        return 0;
    }

    /** Make a key (kept on the server) and print a certificate request. */
    public function csr(Output $output, string $username, string $domain, ?string $keyType = null, ?string $country = null, ?string $state = null, ?string $city = null, ?string $org = null): int
    {
        $found = $this->site($output, $username, $domain);

        if ($found === null) {
            return 1;
        }

        [$account, $row] = $found;

        [$csr, $why, $detail] = $this->ssl->csr($this->root(), null, $account, $row, [
            'key_type' => (string) $keyType, 'country' => (string) $country, 'state' => (string) $state,
            'locality' => (string) $city, 'organization' => (string) $org,
        ]);

        if ($csr === null) {
            $output->error($this->localization->get((string) $why) . ($detail !== null ? ' (' . $detail . ')' : ''));

            return 2;
        }

        $output->line(\rtrim($csr));

        return 0;
    }

    public function remove(Output $output, string $username, string $domain): int
    {
        $found = $this->site($output, $username, $domain);

        if ($found === null) {
            return 1;
        }

        [$account, $row] = $found;

        return $this->outcome($output, $this->ssl->remove($this->root(), null, $account, $row), 'The certificate is no longer served.');
    }

    /** --autossl=yes|no --force=yes|no */
    public function options(Output $output, string $username, string $domain, ?string $autossl = null, ?string $force = null): int
    {
        $found = $this->site($output, $username, $domain);

        if ($found === null) {
            return 1;
        }

        [$account, $row] = $found;

        $flag = static fn(?string $v): ?bool => $v === null ? null : \in_array(\strtolower($v), ['yes', 'on', '1', 'true'], true);

        return $this->outcome($output, $this->ssl->setOptions($this->root(), null, $account, $row, $flag($autossl), $flag($force)), 'Saved.');
    }

    /** Run AutoSSL once; --wait follows its orders to the end. */
    public function autossl(Output $output, bool $wait = false): int
    {
        $r = $this->ssl->run();
        $output->line(\sprintf('%d due, %d ordered, %d answered before', $r['due'], $r['started'], \array_sum($r['settled'])));

        if (!$wait) {
            return 0;
        }

        $until = \time() + self::WAIT_SECONDS;

        while (\time() < $until) {
            $running = \array_filter($this->ssl->recentOrders(50), static fn(array $o): bool => $o['state'] === AutoSsl::RUNNING);

            if ($running === []) {
                $r = $this->ssl->run();

                if ($r['started'] === 0) {
                    break;
                }

                continue;
            }

            \sleep(2);
            $this->ssl->settleAll();
        }

        foreach ($this->ssl->recentOrders(10) as $o) {
            $output->line(\sprintf('order %d  %-5s %-8s %s', $o['id'], $o['kind'], $o['state'], (string) ($o['error'] ?? '')));
        }

        return 0;
    }

    /** Show the hostname's certificate; --issue orders one, --install reads one from standard input. */
    public function hostname(Output $output, bool $issue = false, bool $install = false, bool $noWait = false): int
    {
        if ($issue) {
            [$order, $why] = $this->ssl->hostIssueNow($this->root(), null);

            return $this->follow($output, $order, $why, $noWait, $this->ssl->hostname());
        }

        if ($install) {
            $pem = (string) \stream_get_contents(\STDIN);
            [$ok, $why, $detail] = $this->ssl->hostInstall($this->root(), null, $pem, $pem);

            if (!$ok) {
                $output->error($this->localization->get((string) $why) . ($detail !== null ? ' (' . $detail . ')' : ''));

                return 2;
            }
        }

        $info = $this->ssl->hostInfo();

        if ($info === null) {
            $output->error($this->localization->get('Web.ssl_agent_down'));

            return 1;
        }

        $c = $info['cert'];
        $output->line('hostname  ' . ($this->ssl->hostname() ?: '(not set: ssl:settings --hostname)'));
        $output->line('names     ' . ($c !== null ? \implode(', ', (array) $c['names']) : '-'));
        $output->line('issuer    ' . ($c !== null ? (string) $c['issuer'] . ((bool) ($c['self_signed'] ?? false) ? ' (self-signed)' : '') : '-'));
        $output->line('expires   ' . ($c !== null ? \gmdate('Y-m-d H:i', (int) $c['not_after']) . ' UTC' : '-'));

        return 0;
    }

    /** Show or change AutoSSL's settings. --eab-hmac=- reads the key from standard input. */
    public function settings(
        Output $output,
        ?string $enabled = null,
        ?string $primary = null,
        ?string $fallback = null,
        ?string $eabKid = null,
        ?string $eabHmac = null,
        ?string $hostname = null,
        ?string $hostAutossl = null,
    ): int {
        $input = [];

        foreach (['enabled' => $enabled, 'fallback' => $fallback, 'host_autossl' => $hostAutossl] as $k => $v) {
            if ($v !== null) {
                $input[$k] = \in_array(\strtolower($v), ['yes', 'on', '1', 'true'], true);
            }
        }

        foreach (['primary' => $primary, 'eab_kid' => $eabKid, 'hostname' => $hostname] as $k => $v) {
            if ($v !== null) {
                $input[$k] = $v;
            }
        }

        if ($eabHmac !== null) {
            $input['eab_hmac'] = $eabHmac === '-' ? \trim((string) \fgets(\STDIN)) : $eabHmac;
        }

        if ($input !== []) {
            $errors = $this->ssl->saveSettings($this->root(), null, $input);

            foreach ($errors as $field => $key) {
                $output->error($field . ': ' . $this->localization->get($key));
            }

            if ($errors !== []) {
                return 2;
            }
        }

        $s = $this->ssl->settings();
        $output->line('autossl       ' . ($s['enabled'] ? 'on' : 'off'));
        $output->line('authorities   ' . (\implode(' then ', $this->ssl->authorities()) ?: 'none'));
        $output->line('zerossl eab   ' . ($s['eab_set'] ? $s['eab_kid'] : 'not set'));
        $output->line('hostname      ' . ($s['hostname'] ?: '-') . ($s['host_autossl'] ? ' (AutoSSL)' : ''));

        return 0;
    }

    // -------------------------------------------------------------------------

    /** @param array<string, mixed>|null $order */
    private function follow(Output $output, ?array $order, ?string $why, bool $noWait, string $label): int
    {
        if ($order === null) {
            $output->error($this->localization->get((string) $why));

            return 2;
        }

        $id = (int) $order['id'];

        if ($noWait && $order['state'] === AutoSsl::RUNNING) {
            $output->line(\sprintf('Ordering a certificate for %s (order %d); ssl:autossl follows it.', $label, $id));

            return 0;
        }

        $until = \time() + self::WAIT_SECONDS;

        while ($order['state'] === AutoSsl::RUNNING && \time() < $until) {
            \sleep(2);
            $this->ssl->settleAll();
            $order = $this->ssl->order($id) ?? $order;
        }

        return match ($order['state']) {
            AutoSsl::DONE => $this->done($output, \sprintf('Certificate for %s issued.', $label)),
            AutoSsl::FAILED => $this->failed($output, \sprintf('No certificate for %s: %s', $label, (string) $order['error'])),
            default => $this->done($output, \sprintf('Still ordering for %s (order %d); ssl:autossl follows it.', $label, $id)),
        };
    }

    private function outcome(Output $output, WebOutcome $outcome, string $done): int
    {
        return match ($outcome->status) {
            WebOutcome::FAILED => $this->failed($output, 'The server refused it: ' . $outcome->message),
            WebOutcome::PENDING => $this->done($output, 'Saved; the server has not answered yet (web:settle sends it).'),
            default => $this->done($output, $done),
        };
    }

    /** @return array<string, mixed>|null */
    private function account(Output $output, string $username): ?array
    {
        $account = $this->accounts->findByUsernameUnscoped($username);

        if ($account === null) {
            $output->error(\sprintf('There is no account named %s.', $username));
        }

        return $account;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>}|null the account and its site, or null after saying why not */
    private function site(Output $output, string $username, string $domain): ?array
    {
        $account = $this->account($output, $username);

        if ($account === null) {
            return null;
        }

        $row = $this->domains->findByName((int) $account['id'], \strtolower($domain));

        if ($row === null || $row['kind'] === Domains::ALIAS) {
            $output->error(\sprintf('%s has no site named %s (an alias shares its site\'s certificate).', $username, $domain));

            return null;
        }

        return [$account, $row];
    }

    private function done(Output $output, string $message): int
    {
        $output->success($message);

        return 0;
    }

    private function failed(Output $output, string $message): int
    {
        $output->error($message);

        return 1;
    }

    private function root(): Identity
    {
        return $this->users->root()->identity;
    }
}
