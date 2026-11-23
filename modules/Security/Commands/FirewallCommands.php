<?php

declare(strict_types=1);

namespace App\Modules\Security\Commands;

use App\Engine\Auth\Identity;
use App\Engine\Cli\Output;
use App\Engine\Localization\Localization;
use App\Modules\Identity\PanelUsers;
use App\Modules\Security\Firewall\Cidr;
use App\Modules\Security\Firewall\Countries;
use App\Modules\Security\Firewall\Entries;
use App\Modules\Security\Firewall\Firewall;

/**
 * firewall:* from the shell, as root: the same actions as the page and the
 * API. Nothing here knows the administrator's own address, so nothing is
 * protected from being locked out: the operator is on the server itself.
 */
final class FirewallCommands
{
    public function __construct(
        private readonly Firewall $firewall,
        private readonly Entries $entries,
        private readonly PanelUsers $panelUsers,
        private readonly Localization $localization,
    ) {}

    /** The firewall's state, its open ports, both lists and the countries blocked. */
    public function status(Output $output): int
    {
        $info = $this->firewall->info();

        if ($info === null) {
            return $this->fail($output, 'Security.fw_error_unreachable');
        }

        if (!($info['running'] ?? false)) {
            $output->warning($this->localization->get($info['enabled'] ? 'Security.fw_not_running' : 'Security.fw_error_off'));
        } else {
            $output->line(\sprintf('firewalld %s, zone %s', (string) ($info['version'] ?? '?'), (string) ($info['zone'] ?? '?')));
        }

        $output->line();
        $output->line(\sprintf('%-14s %s', 'PORT', 'REACHABLE BY'));

        foreach ($info['ports'] ?? [] as $p) {
            $who = ($p['sources'] ?? []) === [] ? 'everyone' : \implode(', ', $p['sources']);
            $output->line(\sprintf('%-14s %s%s', $p['port'] . '/' . $p['proto'], $who, ($p['via'] ?? '') !== '' ? '  (service ' . $p['via'] . ')' : ''));
        }

        foreach ([Entries::ALLOW => 'ALLOWED', Entries::DENY => 'DENIED'] as $list => $title) {
            $output->line();
            $output->line(\sprintf('%-6s %-6s %-20s %-17s %s', 'ID', $title, 'ADDRESS', 'ENDS (UTC)', 'NOTE'));

            foreach ($this->entries->all($list) as $row) {
                $output->line(\sprintf('%-6d %-6s %-20s %-17s %s', (int) $row['id'], '', (string) $row['cidr'], (string) ($row['expires_at'] ?? 'never'), (string) $row['note']));
            }
        }

        $output->line();
        $output->line('Countries blocked: ' . ($this->entries->countries() === [] ? 'none' : \implode(' ', $this->entries->countries())));

        return 0;
    }

    /** Open a port to everyone, or only to the addresses after --only. */
    public function open(Output $output, string $port, string $proto = 'tcp', ?string $only = null): int
    {
        [$ok, $why] = $this->firewall->openPort($this->root(), null, $port, $proto, $only === null ? [] : Cidr::split($only));

        return $this->report($output, $ok, $why, \sprintf('Port %s/%s is open%s.', $port, $proto, $only === null ? '' : ' to ' . $only));
    }

    /** Close a port. */
    public function close(Output $output, string $port, string $proto = 'tcp'): int
    {
        [$ok, $why] = $this->firewall->closePort($this->root(), null, $port, $proto);

        return $this->report($output, $ok, $why, \sprintf('Port %s/%s is closed.', $port, $proto));
    }

    /** Always let an address or network in. */
    public function allow(Output $output, string $address, ?string $note = null): int
    {
        [$ok, $why] = $this->firewall->addEntry($this->root(), null, Entries::ALLOW, $address, $note ?? '');

        return $this->report($output, $ok, $why, $address . ' is always let in.');
    }

    /** Never let an address or network in (--for: 1h, 1d, 7d, 30d or never). */
    public function deny(Output $output, string $address, ?string $note = null, string $for = 'never'): int
    {
        [$ok, $why] = $this->firewall->addEntry($this->root(), null, Entries::DENY, $address, $note ?? '', $for);

        return $this->report($output, $ok, $why, $address . ' is blocked.');
    }

    /** Take an address out of both lists (an id from firewall:status works too). */
    public function remove(Output $output, string $address): int
    {
        $rows = [];

        if (\ctype_digit($address)) {
            $row = $this->entries->find((int) $address);
            $rows = $row === null ? [] : [$row];
        } else {
            [$cidr] = Cidr::normalize($address);

            foreach (Entries::LISTS as $list) {
                $row = $cidr === null ? null : $this->entries->findAddress($list, $cidr);

                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        if ($rows === []) {
            return $this->fail($output, 'Security.fw_error_missing');
        }

        foreach ($rows as $row) {
            [$ok, $why] = $this->firewall->removeEntry($this->root(), null, (int) $row['id']);

            if (!$ok) {
                return $this->fail($output, (string) $why);
            }

            $output->success(\sprintf('%s is out of the %s list.', $row['cidr'], $row['list']));
        }

        return 0;
    }

    /** The countries blocked: --set CN,RU, or --none; --refresh fetches their lists again. */
    public function countries(Output $output, ?string $set = null, bool $none = false, bool $refresh = false): int
    {
        if ($set === null && !$none && !$refresh) {
            $output->line('Blocked: ' . ($this->entries->countries() === [] ? 'none' : \implode(', ', \array_map(static fn(string $c): string => $c . ' ' . (Countries::NAMES[$c] ?? ''), $this->entries->countries()))));
            $output->line('Lists fetched: ' . ($this->entries->setting('countries_fetched_at') ?? 'never') . ' UTC');

            return 0;
        }

        $codes = $none ? [] : ($set === null ? $this->entries->countries() : Countries::parse($set)[0]);

        if ($set !== null && !$none) {
            [, $bad] = Countries::parse($set);

            if ($bad !== []) {
                return $this->fail($output, 'Security.fw_error_country');
            }
        }

        [$ok, $why] = $this->firewall->setCountries($this->root(), null, $codes, $refresh);

        return $this->report($output, $ok, $why, $codes === [] ? 'No country is blocked.' : 'Blocked: ' . \implode(' ', $codes) . '.');
    }

    /** Send the lists and countries to the server again, as the panel has them. */
    public function sync(Output $output): int
    {
        [$ok, $why] = $this->firewall->resend();

        return $this->report($output, $ok, $why, 'The server has the lists and countries the panel has.');
    }

    /** End the deny entries whose time has passed. */
    public function expire(Output $output): int
    {
        $n = $this->firewall->expire();

        if ($n > 0) {
            $output->line(\sprintf('%d blocked address(es) ended.', $n));
        }

        return 0;
    }

    private function report(Output $output, bool $ok, ?string $why, string $success): int
    {
        if (!$ok) {
            return $this->fail($output, (string) $why);
        }

        $output->success($success);

        return 0;
    }

    private function fail(Output $output, string $why): int
    {
        $output->error(\str_starts_with($why, 'Security.') ? $this->localization->get($why) : $why);

        return 1;
    }

    private function root(): Identity
    {
        return $this->panelUsers->root()->identity;
    }
}
