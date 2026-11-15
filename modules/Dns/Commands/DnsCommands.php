<?php

declare(strict_types=1);

namespace App\Modules\Dns\Commands;

use App\Engine\Auth\Identity;
use App\Engine\Cli\Output;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Dns\DnsActions;
use App\Modules\Dns\DnsChecks;
use App\Modules\Dns\DnsOutcome;
use App\Modules\Dns\DnsRecords;
use App\Modules\Dns\DnsSync;
use App\Modules\Dns\DnsZones;
use App\Modules\Identity\PanelUsers;
use App\Modules\Resellers\Resellers;

/**
 * dns:* from the shell, as root: the same actions as the panels and the API.
 */
final class DnsCommands
{
    public function __construct(
        private readonly DnsZones $zones,
        private readonly DnsActions $actions,
        private readonly DnsSync $sync,
        private readonly DnsChecks $checks,
        private readonly Accounts $accounts,
        private readonly Resellers $resellers,
        private readonly PanelUsers $users,
        private readonly Localization $localization,
    ) {}

    public function zones(Output $output, ?string $username = null): int
    {
        $rows = $this->zones->all();

        if ($username !== null) {
            if ($this->accounts->findByUsernameUnscoped($username) === null) {
                $output->error(\sprintf('There is no account named %s.', $username));

                return 1;
            }

            $rows = \array_values(\array_filter($rows, static fn(array $z): bool => $z['username'] === $username));
        }

        $output->line(\sprintf('%-40s %-16s %8s  %s', 'ZONE', 'ACCOUNT', 'RECORDS', 'STATE'));

        foreach ($rows as $z) {
            $output->line(\sprintf('%-40s %-16s %8d  %s', (string) $z['name'], (string) ($z['username'] ?? '(server)'), (int) $z['records'], $z['state'] . ($z['error'] ? ' (' . $z['error'] . ')' : '')));
        }

        return 0;
    }

    public function records(Output $output, string $zone): int
    {
        $row = $this->zone($output, $zone);

        if ($row === null) {
            return 1;
        }

        $name = (string) $row['name'];
        $account = $row['account_id'] === null ? null : $this->accounts->byId((int) $row['account_id']);
        $nameservers = $this->zones->nameserversFor($account === null || $account['owner_id'] === null ? null : (int) $account['owner_id'], $name);
        $output->line(\sprintf('%-6s %-30s %-6s %-7s %s', 'ID', 'NAME', 'TTL', 'TYPE', 'CONTENT'));

        foreach ($nameservers as $ns) {
            $output->line(\sprintf('%-6s %-30s %-6d %-7s %s', '-', '@', 86400, 'NS', $ns));
        }

        foreach ($this->zones->records((int) $row['id']) as $r) {
            $output->line(\sprintf('%-6d %-30s %-6d %-7s %s', (int) $r['id'], (string) $r['name'], (int) $r['ttl'], (string) $r['type'], (string) $r['content']));
        }

        $look = $this->checks->lookup([$name], []);
        $found = $look['known'] ? ($look['zones'][$name] ?? []) : null;
        $output->line();
        $output->line('On the server: ' . $row['state'] . ($row['error'] ? ' (' . $row['error'] . ')' : ''));
        $output->line('Delegation: ' . match (DnsChecks::delegation($nameservers, $found)) {
            'ok' => 'the registry names this zone\'s nameservers (' . \implode(', ', (array) $found) . ')',
            'elsewhere' => 'the registry names other nameservers (' . \implode(', ', (array) $found) . '); these records are not what the world sees',
            'none' => 'the registry names no nameservers for it yet',
            default => 'could not be checked',
        });

        return 0;
    }

    public function add(Output $output, string $zone, string $name, string $type, string $content, ?string $ttl = null): int
    {
        $row = $this->zone($output, $zone);

        if ($row === null) {
            return 1;
        }

        [$outcome, $errors] = $this->actions->addRecord($this->root(), null, $row, ['name' => $name, 'type' => $type, 'content' => $content, 'ttl' => $ttl ?? ''], true);

        return $this->outcome($output, $outcome, $errors, 'Record added.');
    }

    public function set(Output $output, string $zone, string $id, ?string $content = null, ?string $ttl = null, ?string $name = null): int
    {
        $row = $this->zone($output, $zone);
        $record = $row === null ? null : $this->record($output, $row, $id);

        if ($row === null || $record === null) {
            return 1;
        }

        $input = \array_filter(['content' => $content, 'ttl' => $ttl, 'name' => $name], static fn(?string $v): bool => $v !== null);
        [$outcome, $errors] = $this->actions->updateRecord($this->root(), null, $row, $record, $input, true);

        return $this->outcome($output, $outcome, $errors, 'Record changed.');
    }

    public function remove(Output $output, string $zone, string $id): int
    {
        $row = $this->zone($output, $zone);
        $record = $row === null ? null : $this->record($output, $row, $id);

        if ($row === null || $record === null) {
            return 1;
        }

        return $this->outcome($output, $this->actions->deleteRecord($this->root(), null, $row, $record), [], 'Record removed.');
    }

    public function reset(Output $output, string $zone, bool $yes = false): int
    {
        $row = $this->zone($output, $zone);

        if ($row === null) {
            return 1;
        }

        if (!$yes) {
            $output->error(\sprintf('This replaces every record of %s with its template\'s. Say --yes to go on.', $row['name']));

            return 2;
        }

        return $this->outcome($output, $this->actions->reset($this->root(), null, $row), [], 'Zone put back to its template.');
    }

    public function zoneCreate(Output $output, string $zone, bool $empty = false): int
    {
        [$outcome] = $this->actions->createServerZone($this->root(), null, $zone, !$empty);

        return $this->outcome($output, $outcome, [], 'Zone made.');
    }

    public function zoneRemove(Output $output, string $zone): int
    {
        $row = $this->zone($output, $zone);

        return $row === null ? 1 : $this->outcome($output, $this->actions->removeServerZone($this->root(), null, $row), [], 'Zone removed.');
    }

    public function settings(Output $output, ?string $ip = null, ?string $ip6 = null, ?string $ns = null): int
    {
        if ($ip !== null || $ip6 !== null) {
            $now = $this->zones->addresses();
            $errors = $this->actions->saveAddresses($this->root(), null, ['ip4' => $ip ?? $now['ip4'], 'ip6' => $ip6 === 'none' ? '' : ($ip6 ?? $now['ip6'])]);

            if ($errors !== []) {
                return $this->outcome($output, DnsOutcome::refused((string) \reset($errors)), $errors, '');
            }
        }

        if ($ns !== null) {
            $errors = $this->actions->saveProfile($this->root(), null, DnsZones::SERVER, $ns, $this->zones->profile(DnsZones::SERVER)['template']);

            if ($errors !== []) {
                return $this->outcome($output, DnsOutcome::refused((string) \reset($errors)), $errors, '');
            }
        }

        $addresses = $this->zones->addresses();
        $profile = $this->zones->profile(DnsZones::SERVER);
        $output->pairs([
            'IPv4 address' => $addresses['ip4'] ?: '(not set)',
            'IPv6 address' => $addresses['ip6'] ?: '(none)',
            'Nameservers' => $profile['nameservers'] === [] ? '(not set: ns1. and ns2. of each zone)' : \implode(', ', $profile['nameservers']),
            'Template' => $profile['template'] === null ? 'built-in' : 'custom',
        ]);

        return 0;
    }

    public function nameservers(Output $output, string $reseller, ?string $ns = null): int
    {
        $row = $this->resellers->findByUsername($reseller);

        if ($row === null) {
            $output->error(\sprintf('There is no reseller named %s.', $reseller));

            return 1;
        }

        $id = (int) $row['id'];

        if ($ns !== null) {
            $errors = $this->actions->saveProfile($this->root(), null, $id, $ns === 'none' ? '' : $ns, $this->zones->profile($id)['template']);

            if ($errors !== []) {
                return $this->outcome($output, DnsOutcome::refused((string) \reset($errors)), $errors, '');
            }
        }

        $own = $this->zones->profile($id)['nameservers'];
        $output->line($own === [] ? 'The server\'s: ' . \implode(', ', $this->zones->profile(DnsZones::SERVER)['nameservers']) : \implode(', ', $own));

        return 0;
    }

    public function template(Output $output, ?string $reseller = null, bool $set = false, bool $default = false): int
    {
        $owner = DnsZones::SERVER;

        if ($reseller !== null) {
            $row = $this->resellers->findByUsername($reseller);

            if ($row === null) {
                $output->error(\sprintf('There is no reseller named %s.', $reseller));

                return 1;
            }

            $owner = (int) $row['id'];
        }

        if ($set || $default) {
            $errors = $this->actions->saveProfile($this->root(), null, $owner, $this->zones->profile($owner)['nameservers'], $default ? null : (string) \stream_get_contents(\STDIN));

            if ($errors !== []) {
                return $this->outcome($output, DnsOutcome::refused((string) \reset($errors)), $errors, '');
            }
        }

        $output->write($owner === DnsZones::SERVER
            ? ($this->zones->profile($owner)['template'] ?? \implode("\n", \array_map('trim', \explode("\n", DnsRecords::DEFAULT_TEMPLATE))) . "\n")
            : $this->zones->templateFor($owner));

        return 0;
    }

    public function info(Output $output): int
    {
        $look = $this->checks->lookup([], []);

        if ($look['running'] === null) {
            $output->error('The agent could not be asked.');

            return 1;
        }

        $output->pairs(['Running' => $look['running'] ? 'yes' : 'no', 'Version' => $look['version'] ?: '-', 'Zones in the panel' => (string) \count($this->zones->all())]);

        return $look['running'] ? 0 : 1;
    }

    public function settle(Output $output): int
    {
        $parts = [];

        foreach ($this->sync->settleAll() as $status => $n) {
            $parts[] = $n . ' ' . $status;
        }

        $output->line($parts === [] ? 'Nothing waiting.' : 'Sent: ' . \implode(', ', $parts) . '.');

        return 0;
    }

    public function sync(Output $output, bool $all = false): int
    {
        foreach ($this->accounts->withSites() as $account) {
            $this->sync->reconcile((int) $account['id']);
        }

        $counts = $this->sync->touchAll($all);
        $parts = [];

        foreach ($counts as $status => $n) {
            $parts[] = $n . ' ' . $status;
        }

        $output->line('Zones: ' . ($parts === [] ? 'none' : \implode(', ', $parts)) . '.');

        return ($counts[DnsOutcome::FAILED] ?? 0) > 0 ? 1 : 0;
    }

    // -------------------------------------------------------------------------

    /** @param array<string, string> $errors */
    private function outcome(Output $output, DnsOutcome $outcome, array $errors, string $done): int
    {
        if ($errors !== [] || $outcome->status === DnsOutcome::REFUSED) {
            foreach ($errors ?: ['' => (string) $outcome->message] as $key) {
                [$key, $line] = \explode('|', $key . '|');
                $output->error($this->localization->get($key, ['line' => $line]));
            }

            return 1;
        }

        return match ($outcome->status) {
            DnsOutcome::FAILED => $this->fail($output, 'The server refused it: ' . $outcome->message),
            DnsOutcome::PENDING => $this->ok($output, 'Saved; the server has not answered yet (dns:settle sends it).'),
            default => $this->ok($output, $done),
        };
    }

    private function ok(Output $output, string $message): int
    {
        $output->success($message);

        return 0;
    }

    private function fail(Output $output, string $message): int
    {
        $output->error($message);

        return 1;
    }

    /** @return array<string, mixed>|null */
    private function zone(Output $output, string $name): ?array
    {
        $zone = $this->zones->zoneByName(\strtolower(\rtrim($name, '.')));

        if ($zone === null || $zone['state'] === DnsSync::REMOVING) {
            $output->error(\sprintf('There is no zone named %s.', $name));

            return null;
        }

        return $zone;
    }

    /**
     * @param array<string, mixed> $zone
     *
     * @return array<string, mixed>|null
     */
    private function record(Output $output, array $zone, string $id): ?array
    {
        $record = \ctype_digit($id) ? $this->zones->record((int) $zone['id'], (int) $id) : null;

        if ($record === null) {
            $output->error(\sprintf('%s has no record %s (dns:records lists them).', $zone['name'], $id));
        }

        return $record;
    }

    private function root(): Identity
    {
        return $this->users->root()->identity;
    }
}
