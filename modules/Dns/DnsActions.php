<?php

declare(strict_types=1);

namespace App\Modules\Dns;

use App\Engine\Auth\Identity;
use App\Engine\Http\Request;
use App\Modules\Accounts\Accounts;
use App\Modules\Audit\AuditLog;

/**
 * Changing zones, records, nameservers and templates: the one place both
 * panels, the API and the CLI go through.
 *
 * A change to a zone's records is saved, then the zone's whole state goes to
 * the server (DnsSync). If the server refuses it, the records are put back
 * as they were. If the agent cannot be reached, the change stays recorded
 * and dns:settle finishes it.
 */
final class DnsActions
{
    public function __construct(
        private readonly DnsZones $zones,
        private readonly DnsSync $sync,
        private readonly Accounts $accounts,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param array<string, mixed> $zone
     * @param array<string, mixed> $input name, type, ttl, content
     *
     * @return array{0: DnsOutcome, 1: array<string, string>} [outcome, errors by field]
     */
    public function addRecord(?Identity $actor, ?Request $request, array $zone, array $input, bool $admin = false): array
    {
        [$clean, $errors] = DnsRecords::validate((string) $zone['name'], $input, $admin);
        $errors = $errors ?: self::conflictError($this->zones->records((int) $zone['id']), $clean);

        if ($errors !== []) {
            return [DnsOutcome::refused((string) \reset($errors), (string) \array_key_first($errors)), $errors];
        }

        return [$this->attempt($actor, $request, $zone, 'dns.record_add', self::describe($clean), fn() => $this->zones->addRecord((int) $zone['id'], $clean)), []];
    }

    /**
     * @param array<string, mixed> $zone
     * @param array<string, mixed> $record
     * @param array<string, mixed> $input  any of name, type, ttl, content
     *
     * @return array{0: DnsOutcome, 1: array<string, string>}
     */
    public function updateRecord(?Identity $actor, ?Request $request, array $zone, array $record, array $input, bool $admin = false): array
    {
        $merged = \array_intersect_key($input, ['name' => 1, 'type' => 1, 'ttl' => 1, 'content' => 1]) + $record;
        [$clean, $errors] = DnsRecords::validate((string) $zone['name'], $merged, $admin || $record['type'] === 'PTR');
        $errors = $errors ?: self::conflictError($this->zones->records((int) $zone['id']), $clean, (int) $record['id']);

        if ($errors !== []) {
            return [DnsOutcome::refused((string) \reset($errors), (string) \array_key_first($errors)), $errors];
        }

        return [$this->attempt($actor, $request, $zone, 'dns.record_change', self::describe($clean), fn() => $this->zones->updateRecord((int) $record['id'], $clean)), []];
    }

    /**
     * @param array<string, mixed> $zone
     * @param array<string, mixed> $record
     */
    public function deleteRecord(?Identity $actor, ?Request $request, array $zone, array $record): DnsOutcome
    {
        return $this->attempt($actor, $request, $zone, 'dns.record_remove', self::describe($record), fn() => $this->zones->deleteRecord((int) $record['id']));
    }

    /**
     * Put a zone back to its template: every record is replaced.
     *
     * @param array<string, mixed> $zone
     */
    public function reset(?Identity $actor, ?Request $request, array $zone): DnsOutcome
    {
        $account = $zone['account_id'] === null ? null : $this->accounts->byId((int) $zone['account_id']);
        $ownerId = $account === null || $account['owner_id'] === null ? null : (int) $account['owner_id'];

        return $this->attempt($actor, $request, $zone, 'dns.zone_reset', '', fn() => $this->zones->fill($zone, $ownerId));
    }

    /**
     * A zone of the server's own: its hostname's domain, a reverse zone.
     *
     * @return array{0: DnsOutcome, 1: ?array<string, mixed>} [outcome, zone]
     */
    public function createServerZone(?Identity $actor, ?Request $request, string $input, bool $template = true): array
    {
        $name = DnsRecords::domain($input);

        if ($name === null) {
            return [DnsOutcome::refused('Dns.error_zone_name', 'name'), null];
        }

        if ($this->zones->zoneByName($name) !== null || $this->accounts->domainTaken($name)) {
            return [DnsOutcome::refused('Dns.error_zone_taken', 'name'), null];
        }

        $id = $this->zones->addZone(null, $name);
        $zone = $this->zones->zone($id) ?? throw new \LogicException('zone vanished');

        // A reverse zone holds PTR records only; a template's addresses mean nothing there.
        if ($template && !\str_ends_with($name, '.arpa')) {
            $this->zones->fill($zone, null);
        }

        $outcome = $this->sync->touch($id);

        if ($outcome->status === DnsOutcome::FAILED) {
            $this->zones->deleteZone($id);
            $zone = null;
        }

        $this->record('dns.zone_create', $actor, $request, $name, [], $outcome);

        return [$outcome, $zone];
    }

    /**
     * Remove a zone of the server's own. An account's zones go with their
     * domains.
     *
     * @param array<string, mixed> $zone
     */
    public function removeServerZone(?Identity $actor, ?Request $request, array $zone): DnsOutcome
    {
        if ($zone['account_id'] !== null) {
            return DnsOutcome::refused('Dns.error_zone_account');
        }

        $outcome = $this->sync->remove($zone);
        $this->record('dns.zone_remove', $actor, $request, (string) $zone['name'], [], $outcome);

        return $outcome;
    }

    /**
     * The addresses new records point at. Zones that exist keep theirs.
     *
     * @param array<string, mixed> $input ip4, ip6
     *
     * @return array<string, string> errors by field
     */
    public function saveAddresses(?Identity $actor, ?Request $request, array $input): array
    {
        $errors = [];
        $ip4 = \trim((string) ($input['ip4'] ?? ''));
        $ip6 = \trim((string) ($input['ip6'] ?? ''));

        if ($ip4 === '' || \filter_var($ip4, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) === false) {
            $errors['ip4'] = 'Dns.error_ipv4';
        }

        if ($ip6 !== '' && \filter_var($ip6, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) === false) {
            $errors['ip6'] = 'Dns.error_ipv6';
        }

        if ($errors !== []) {
            return $errors;
        }

        $this->zones->set('ip4', $ip4);
        $this->zones->set('ip6', $ip6 === '' ? '' : (string) \inet_ntop((string) \inet_pton($ip6)));
        $this->audit->record('dns.settings', $actor, $request, 'addresses', AuditLog::OK, ['ip4' => $ip4, 'ip6' => $ip6]);
        // Glue records carry the server's addresses.
        $this->sync->touchAll();

        return [];
    }

    /**
     * Nameservers and template of the server (DnsZones::SERVER) or of a
     * reseller. Empty nameservers and a null template mean "the server's".
     * Every zone that uses them is sent again.
     *
     * @param mixed $nameservers a list, or names separated by commas, spaces or lines
     *
     * @return array<string, string> errors by field
     */
    public function saveProfile(?Identity $actor, ?Request $request, int $ownerId, mixed $nameservers, ?string $template): array
    {
        $errors = [];
        $list = \is_array($nameservers) ? $nameservers : (\preg_split('/[\s,]+/', (string) $nameservers, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        $names = [];

        foreach ($list as $n) {
            $clean = \is_string($n) ? DnsRecords::domain($n) : null;

            if ($clean === null) {
                $errors['nameservers'] = 'Dns.error_nameserver';

                break;
            }

            $names[$clean] = true;
        }

        $names = \array_keys($names);

        if (!isset($errors['nameservers']) && (\count($names) === 1 || \count($names) > 6 || ($names === [] && $ownerId === DnsZones::SERVER))) {
            $errors['nameservers'] = 'Dns.error_nameserver_count';
        }

        $template = $template === null || \trim($template) === '' ? null : \str_replace("\r\n", "\n", \trim($template)) . "\n";

        if ($template !== null) {
            [, $why] = DnsRecords::parseTemplate($template);

            if ($why !== null) {
                $errors['template'] = $why;
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        if ($names === [] && $template === null && $ownerId !== DnsZones::SERVER) {
            $this->zones->deleteProfile($ownerId);
        } else {
            $this->zones->saveProfile($ownerId, $names, $template);
        }

        $this->audit->record('dns.nameservers', $actor, $request, $ownerId === DnsZones::SERVER ? 'server' : 'reseller ' . $ownerId, AuditLog::OK, [
            'nameservers' => \implode(',', $names), 'template' => $template === null ? 'default' : 'custom',
        ]);
        $this->sync->touchAll();

        return [];
    }

    // -------------------------------------------------------------------------

    /**
     * Save, send, and put the records back if the server refuses; audit
     * either way.
     *
     * @param array<string, mixed> $zone
     */
    private function attempt(?Identity $actor, ?Request $request, array $zone, string $action, string $what, \Closure $do): DnsOutcome
    {
        $before = $this->zones->records((int) $zone['id']);
        $do();
        $outcome = $this->sync->touch((int) $zone['id']);

        if ($outcome->status === DnsOutcome::FAILED) {
            $this->zones->restoreRecords((int) $zone['id'], $before);
            $this->sync->touch((int) $zone['id']);
        }

        return $this->record($action, $actor, $request, (string) $zone['name'], $what === '' ? [] : ['record' => $what], $outcome);
    }

    /** @param array<string, mixed> $details */
    private function record(string $action, ?Identity $actor, ?Request $request, string $subject, array $details, DnsOutcome $outcome): DnsOutcome
    {
        if ($outcome->status === DnsOutcome::PENDING) {
            $details['pending'] = true;
        } elseif ($outcome->status === DnsOutcome::FAILED) {
            $details += ['reason' => $outcome->code, 'error' => $outcome->message];
        }

        if ($outcome->status !== DnsOutcome::REFUSED) {
            $this->audit->record($action, $actor, $request, $subject, $outcome->status === DnsOutcome::FAILED ? AuditLog::FAILED : AuditLog::OK, $details);
        }

        return $outcome;
    }

    /**
     * @param list<array<string, mixed>>                                       $existing
     * @param array{name: string, type: string, ttl: int, content: string} $clean
     *
     * @return array<string, string>
     */
    private static function conflictError(array $existing, array $clean, ?int $exceptId = null): array
    {
        $why = DnsRecords::conflict($existing, $clean, $exceptId);

        return $why === null ? [] : [$why === 'Dns.error_duplicate' ? 'content' : 'name' => $why];
    }

    /** @param array<string, mixed> $record */
    private static function describe(array $record): string
    {
        return $record['name'] . ' ' . $record['type'] . ' ' . \mb_substr((string) $record['content'], 0, 120);
    }
}
