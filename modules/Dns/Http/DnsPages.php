<?php

declare(strict_types=1);

namespace App\Modules\Dns\Http;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Session\Session;
use App\Modules\Accounts\Accounts;
use App\Modules\Dns\DnsActions;
use App\Modules\Dns\DnsChecks;
use App\Modules\Dns\DnsOutcome;
use App\Modules\Dns\DnsRecords;
use App\Modules\Dns\DnsSync;
use App\Modules\Dns\DnsZones;
use App\Modules\Panel\Ui\PanelPage;
use App\Modules\Subusers\Subusers;

/**
 * "DNS" in both panels.
 *
 * User panel: the account's zones and the zone editor.
 *
 * Admin panel: for administrators, PowerDNS's state, the server's
 * addresses, nameservers and zone template, every zone, and the same editor
 * for any of them; for resellers, their own nameservers and template.
 */
final class DnsPages
{
    public function __construct(
        private readonly DnsZones $zones,
        private readonly DnsActions $actions,
        private readonly DnsChecks $checks,
        private readonly Accounts $accounts,
        private readonly Subusers $subusers,
        private readonly Authorizer $authorizer,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    // -------------------------------------------------------------------------
    // User panel

    public function index(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->account($identity);
        $zones = $this->zones->zonesOf((int) $account['id']);

        // One zone: straight to its records.
        if (\count($zones) === 1) {
            return new RedirectResponse($this->router->url('user.dns.zone', ['id' => $zones[0]['id']]), 303);
        }

        $look = $this->checks->lookup(\array_map(static fn(array $z): string => (string) $z['name'], $zones), []);
        $ownerId = $account['owner_id'] === null ? null : (int) $account['owner_id'];
        $rows = [];

        foreach ($zones as $zone) {
            $name = (string) $zone['name'];
            $rows[] = $zone + [
                'records' => \count($this->zones->records((int) $zone['id'])),
                'delegation' => DnsChecks::delegation($this->zones->nameserversFor($ownerId, $name), $look['known'] ? ($look['zones'][$name] ?? []) : null),
                'url' => $this->router->url('user.dns.zone', ['id' => $zone['id']]),
            ];
        }

        return $this->pages->render($request, $session, $identity, 'user', '@Dns/zones', ['zones' => $rows], title: 'Dns.title', active: 'dns');
    }

    public function zone(Request $request, Session $session, Identity $identity, string $id): Response
    {
        return $this->editor($request, $session, $identity, $this->find($request, $identity, $id));
    }

    public function addRecord(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $zone = $this->find($request, $identity, $id);
        $input = self::input($request);
        [$outcome, $errors] = $this->actions->addRecord($identity, $request, $zone, $input, $this->admin($request, $identity));

        if ($errors !== []) {
            return $this->editor($request, $session, $identity, $zone, ['errors' => $errors, 'old' => $input], 422);
        }

        return $this->after($request, $session, $zone, $outcome, 'Dns.record_added');
    }

    public function updateRecord(Request $request, Session $session, Identity $identity, string $id, string $record): Response
    {
        $zone = $this->find($request, $identity, $id);
        $row = $this->zones->record((int) $zone['id'], (int) $record) ?? throw HttpException::notFound();
        [$outcome, $errors] = $this->actions->updateRecord($identity, $request, $zone, $row, self::input($request), $this->admin($request, $identity));

        if ($errors !== []) {
            return $this->editor($request, $session, $identity, $zone, ['edit_errors' => $errors, 'edit_id' => (int) $row['id'], 'edit_old' => self::input($request)], 422);
        }

        return $this->after($request, $session, $zone, $outcome, 'Dns.record_saved');
    }

    public function deleteRecord(Request $request, Session $session, Identity $identity, string $id, string $record): Response
    {
        $zone = $this->find($request, $identity, $id);
        $row = $this->zones->record((int) $zone['id'], (int) $record) ?? throw HttpException::notFound();

        return $this->after($request, $session, $zone, $this->actions->deleteRecord($identity, $request, $zone, $row), 'Dns.record_removed');
    }

    public function reset(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $zone = $this->find($request, $identity, $id);

        return $this->after($request, $session, $zone, $this->actions->reset($identity, $request, $zone), 'Dns.zone_reset');
    }

    // -------------------------------------------------------------------------
    // Admin panel

    public function adminIndex(Request $request, Session $session, Identity $identity): Response
    {
        return $this->authorizer->allows($identity, 'server.dns')
            ? $this->server($request, $session, $identity)
            : $this->own($request, $session, $identity);
    }

    public function saveAddresses(Request $request, Session $session, Identity $identity): Response
    {
        $input = ['ip4' => (string) $request->input('ip4', ''), 'ip6' => (string) $request->input('ip6', '')];
        $errors = $this->actions->saveAddresses($identity, $request, $input);

        if ($errors !== []) {
            return $this->server($request, $session, $identity, ['errors' => $errors, 'old' => $input], 422);
        }

        PanelPage::notice($session, 'success', 'Dns.saved');

        return $this->toAdmin();
    }

    /** Nameservers or template: the server's for an administrator, their own for a reseller. */
    public function saveProfile(Request $request, Session $session, Identity $identity): Response
    {
        $server = $this->authorizer->allows($identity, 'server.dns');
        $ownerId = $server ? DnsZones::SERVER : (int) $identity->id;
        $profile = $this->zones->profile($ownerId);
        $what = (string) $request->input('what', 'nameservers');
        $nameservers = $what === 'nameservers' ? (string) $request->input('nameservers', '') : $profile['nameservers'];
        $template = match (true) {
            $what !== 'template' => $profile['template'],
            $request->input('default') === '1' => null,
            default => (string) $request->input('template', ''),
        };
        $errors = $this->actions->saveProfile($identity, $request, $ownerId, $nameservers, $template);

        if ($errors !== []) {
            $extra = ['errors' => $errors, 'old' => ['nameservers' => \is_string($nameservers) ? $nameservers : null, 'template' => $what === 'template' ? $template : null]];

            return $server ? $this->server($request, $session, $identity, $extra, 422) : $this->own($request, $session, $identity, $extra, 422);
        }

        PanelPage::notice($session, 'success', 'Dns.saved');

        return $this->toAdmin();
    }

    public function createZone(Request $request, Session $session, Identity $identity): Response
    {
        $name = (string) $request->input('name', '');
        [$outcome, $zone] = $this->actions->createServerZone($identity, $request, $name);

        if ($outcome->status === DnsOutcome::REFUSED) {
            return $this->server($request, $session, $identity, ['errors' => ['zone' => (string) $outcome->message], 'old' => ['zone' => $name]], 422);
        }

        self::notify($session, $outcome, 'Dns.zone_created');

        return $zone === null ? $this->toAdmin() : new RedirectResponse($this->router->url('admin.dns.zone', ['id' => $zone['id']]), 303);
    }

    public function removeZone(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $zone = $this->zones->zone((int) $id) ?? throw HttpException::notFound();
        self::notify($session, $this->actions->removeServerZone($identity, $request, $zone), 'Dns.zone_removed');

        return $this->toAdmin();
    }

    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $extra */
    private function server(Request $request, Session $session, Identity $identity, array $extra = [], int $status = 200): Response
    {
        $profile = $this->zones->profile(DnsZones::SERVER);
        $look = $this->checks->lookup([], $profile['nameservers']);
        $zones = \array_map(fn(array $z): array => $z + [
            'url' => $this->router->url('admin.dns.zone', ['id' => $z['id']]),
            'remove_url' => $z['account_id'] === null ? $this->router->url('admin.dns.zones.remove', ['id' => $z['id']]) : null,
        ], $this->zones->all());

        return $this->pages->render($request, $session, $identity, 'admin', '@Dns/admin', $extra + [
            'look' => $look,
            'addresses' => $this->zones->addresses(),
            'nameservers' => $profile['nameservers'],
            'glue' => self::glue($profile['nameservers'], $look),
            'template' => $profile['template'] ?? self::tidy(DnsRecords::DEFAULT_TEMPLATE),
            'template_custom' => $profile['template'] !== null,
            'zones' => $zones,
            'errors' => [],
            'old' => [],
            'addresses_url' => $this->router->url('admin.dns.addresses'),
            'profile_url' => $this->router->url('admin.dns.profile'),
            'zone_url' => $this->router->url('admin.dns.zones'),
        ], title: 'Dns.title', active: 'dns', status: $status);
    }

    /** @param array<string, mixed> $extra */
    private function own(Request $request, Session $session, Identity $identity, array $extra = [], int $status = 200): Response
    {
        $profile = $this->zones->profile((int) $identity->id);
        $server = $this->zones->profile(DnsZones::SERVER);
        $look = $this->checks->lookup([], $profile['nameservers']);

        return $this->pages->render($request, $session, $identity, 'admin', '@Dns/own', $extra + [
            'look' => $look,
            'addresses' => $this->zones->addresses(),
            'nameservers' => $profile['nameservers'],
            'server_nameservers' => $server['nameservers'],
            'glue' => self::glue($profile['nameservers'], $look),
            'template' => $profile['template'] ?? $server['template'] ?? self::tidy(DnsRecords::DEFAULT_TEMPLATE),
            'template_custom' => $profile['template'] !== null,
            'errors' => [],
            'old' => [],
            'profile_url' => $this->router->url('admin.dns.profile'),
        ], title: 'Dns.title', active: 'dns', status: $status);
    }

    /**
     * The zone editor, in either panel.
     *
     * @param array<string, mixed> $zone
     * @param array<string, mixed> $extra
     */
    private function editor(Request $request, Session $session, Identity $identity, array $zone, array $extra = [], int $status = 200): Response
    {
        $area = PanelPage::areaOf($request);
        $prefix = $area === 'admin' ? 'admin.dns' : 'user.dns';
        $id = (int) $zone['id'];
        $name = (string) $zone['name'];
        $account = $zone['account_id'] === null ? null : $this->accounts->byId((int) $zone['account_id']);
        $nameservers = $this->zones->nameserversFor($account === null || $account['owner_id'] === null ? null : (int) $account['owner_id'], $name);
        $look = $this->checks->lookup([$name], []);
        $records = \array_map(fn(array $r): array => $r + [
            'full' => DnsRecords::full((string) $r['name'], $name),
            'update_url' => $this->router->url($prefix . '.records.update', ['id' => $id, 'record' => $r['id']]),
            'delete_url' => $this->router->url($prefix . '.records.delete', ['id' => $id, 'record' => $r['id']]),
        ], $this->zones->records($id));
        $admin = $this->admin($request, $identity);

        return $this->pages->render($request, $session, $identity, $area, '@Dns/zone', $extra + [
            'zone' => $zone,
            'account' => $account,
            'records' => $records,
            'nameservers' => $nameservers,
            'found' => $look['known'] ? ($look['zones'][$name] ?? []) : [],
            'delegation' => DnsChecks::delegation($nameservers, $look['known'] ? ($look['zones'][$name] ?? []) : null),
            'types' => \array_values(\array_filter(DnsRecords::TYPES, static fn(string $t): bool => $admin || $t !== 'PTR')),
            'default_ttl' => DnsRecords::DEFAULT_TTL,
            'errors' => [], 'old' => [],
            'edit_errors' => [], 'edit_id' => 0, 'edit_old' => [],
            'back_url' => $this->router->url($prefix),
            'add_url' => $this->router->url($prefix . '.records', ['id' => $id]),
            'reset_url' => $this->router->url($prefix . '.reset', ['id' => $id]),
            'several' => $area === 'admin' || \count($this->zones->zonesOf($zone['account_id'] === null ? null : (int) $zone['account_id'])) > 1,
        ], title: 'Dns.title', active: 'dns', status: $status);
    }

    /**
     * Each nameserver, where its name leads, and what its registrar must be
     * told (glue).
     *
     * @param list<string>          $nameservers
     * @param array<string, mixed> $look
     *
     * @return list<array{name: string, addresses: list<string>, here: ?bool}>
     */
    private static function glue(array $nameservers, array $look): array
    {
        return \array_map(static fn(string $ns): array => [
            'name' => $ns,
            'addresses' => $look['hosts'][$ns]['addresses'] ?? [],
            'here' => $look['known'] ? ($look['hosts'][$ns]['here'] ?? false) : null,
        ], $nameservers);
    }

    /** A template without the indentation its source has. */
    private static function tidy(string $template): string
    {
        return \implode("\n", \array_map('trim', \explode("\n", $template))) . "\n";
    }

    /** @return array<string, string> */
    private static function input(Request $request): array
    {
        $input = [];

        foreach (['name', 'type', 'ttl', 'content'] as $field) {
            $value = $request->input($field);

            if (\is_string($value)) {
                $input[$field] = $value;
            }
        }

        return $input;
    }

    /** @param array<string, mixed> $zone */
    private function after(Request $request, Session $session, array $zone, DnsOutcome $outcome, string $done): Response
    {
        self::notify($session, $outcome, $done);
        $route = PanelPage::areaOf($request) === 'admin' ? 'admin.dns.zone' : 'user.dns.zone';

        return new RedirectResponse($this->router->url($route, ['id' => $zone['id']]), 303);
    }

    private static function notify(Session $session, DnsOutcome $outcome, string $done): void
    {
        match ($outcome->status) {
            DnsOutcome::REFUSED => PanelPage::notice($session, 'error', (string) $outcome->message),
            DnsOutcome::FAILED => PanelPage::notice($session, 'error', 'Dns.refused'),
            DnsOutcome::PENDING => PanelPage::notice($session, 'warning', 'Dns.pending'),
            default => PanelPage::notice($session, 'success', $done),
        };
    }

    /** The administrator's editor (any zone, PTR records) or the owner's (their account's zones). */
    private function admin(Request $request, Identity $identity): bool
    {
        return PanelPage::areaOf($request) === 'admin' && $this->authorizer->allows($identity, 'server.dns');
    }

    /** @return array<string, mixed> the zone, if this login may edit it here */
    private function find(Request $request, Identity $identity, string $id): array
    {
        $zone = \ctype_digit($id) ? $this->zones->zone((int) $id) : null;

        if ($zone === null || $zone['state'] === DnsSync::REMOVING) {
            throw HttpException::notFound();
        }

        if (PanelPage::areaOf($request) === 'admin') {
            return $zone;
        }

        $account = $this->account($identity);

        return $zone['account_id'] !== null && (int) $zone['account_id'] === (int) $account['id'] ? $zone : throw HttpException::notFound();
    }

    /** @return array<string, mixed> */
    private function account(Identity $identity): array
    {
        return $this->subusers->accountOf($identity) ?? throw HttpException::notFound();
    }

    private function toAdmin(): Response
    {
        return new RedirectResponse($this->router->url('admin.dns'), 303);
    }
}
