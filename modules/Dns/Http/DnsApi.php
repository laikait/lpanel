<?php

declare(strict_types=1);

namespace App\Modules\Dns\Http;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Dns\DnsActions;
use App\Modules\Dns\DnsChecks;
use App\Modules\Dns\DnsOutcome;
use App\Modules\Dns\DnsRecords;
use App\Modules\Dns\DnsSync;
use App\Modules\Dns\DnsZones;
use App\Modules\Packages\Http\PackageApi;

/**
 * DNS through the API, with the same rules as the panels.
 *
 *   /admin/api/v1/accounts/{username}/dns…   an account's zones (accounts.manage / accounts.modify)
 *   /admin/api/v1/dns…                       the server's settings and any zone (server.dns)
 *   /admin/api/v1/dns/nameservers            your own nameservers and template (dns.own)
 *
 *   200  done      201  made      202  recorded, still being carried out
 *   204  removed   422  invalid input   502  the server refused it
 */
final class DnsApi
{
    public function __construct(
        private readonly DnsZones $zones,
        private readonly DnsActions $actions,
        private readonly DnsChecks $checks,
        private readonly Accounts $accounts,
        private readonly Authorizer $authorizer,
        private readonly Localization $localization,
    ) {}

    // -------------------------------------------------------------------------
    // The server

    public function settings(): JsonResponse
    {
        return ApiResponse::item($this->presentSettings(DnsZones::SERVER));
    }

    public function saveSettings(Request $request, Identity $identity): Response
    {
        $errors = [];

        if ($request->input('ip4') !== null || $request->input('ip6') !== null) {
            $now = $this->zones->addresses();
            $errors = $this->actions->saveAddresses($identity, $request, ['ip4' => $request->input('ip4') ?? $now['ip4'], 'ip6' => $request->input('ip6') ?? $now['ip6']]);
        }

        if ($errors === [] && ($request->input('nameservers') !== null || \array_key_exists('template', (array) $request->json()))) {
            $errors = $this->saveProfile($request, $identity, DnsZones::SERVER);
        }

        return $errors !== [] ? $this->invalid($errors) : ApiResponse::item($this->presentSettings(DnsZones::SERVER));
    }

    public function own(Identity $identity): JsonResponse
    {
        return ApiResponse::item($this->presentSettings($this->ownerOf($identity)));
    }

    public function saveOwn(Request $request, Identity $identity): Response
    {
        $owner = $this->ownerOf($identity);
        $errors = $this->saveProfile($request, $identity, $owner);

        return $errors !== [] ? $this->invalid($errors) : ApiResponse::item($this->presentSettings($owner));
    }

    public function zones(): JsonResponse
    {
        return ApiResponse::collection(\array_map(fn(array $z): array => $this->presentZone($z) + ['account' => $z['username'], 'records' => (int) $z['records']], $this->zones->all()));
    }

    public function createZone(Request $request, Identity $identity): Response
    {
        [$outcome, $zone] = $this->actions->createServerZone($identity, $request, (string) $request->input('name', ''), $request->input('template') !== false);

        return $zone === null ? $this->respond($outcome, null) : $this->respond($outcome, $zone, 201);
    }

    public function removeZone(Request $request, Identity $identity, string $zone): Response
    {
        $outcome = $this->actions->removeServerZone($identity, $request, $this->anyZone($zone));

        return $outcome->status === DnsOutcome::DONE ? ApiResponse::noContent() : $this->respond($outcome, null);
    }

    public function zone(string $zone): JsonResponse
    {
        return ApiResponse::item($this->presentFull($this->anyZone($zone), true));
    }

    public function addRecord(Request $request, Identity $identity, string $zone): Response
    {
        return $this->doAdd($request, $identity, $this->anyZone($zone), true);
    }

    public function updateRecord(Request $request, Identity $identity, string $zone, string $id): Response
    {
        return $this->doUpdate($request, $identity, $this->anyZone($zone), $id, true);
    }

    public function deleteRecord(Request $request, Identity $identity, string $zone, string $id): Response
    {
        return $this->doDelete($request, $identity, $this->anyZone($zone), $id);
    }

    public function reset(Request $request, Identity $identity, string $zone): Response
    {
        $row = $this->anyZone($zone);

        return $this->respond($this->actions->reset($identity, $request, $row), $row);
    }

    // -------------------------------------------------------------------------
    // An account's zones

    public function accountZones(Identity $identity, string $username): JsonResponse
    {
        $account = $this->accounts->findByUsername($identity, $username) ?? throw HttpException::notFound();

        return ApiResponse::collection(\array_map(fn(array $z): array => $this->presentZone($z) + ['records' => \count($this->zones->records((int) $z['id']))], $this->zones->zonesOf((int) $account['id'])));
    }

    public function accountZone(Identity $identity, string $username, string $zone): JsonResponse
    {
        return ApiResponse::item($this->presentFull($this->accountsZone($identity, $username, $zone), true));
    }

    public function accountAddRecord(Request $request, Identity $identity, string $username, string $zone): Response
    {
        return $this->doAdd($request, $identity, $this->accountsZone($identity, $username, $zone), false);
    }

    public function accountUpdateRecord(Request $request, Identity $identity, string $username, string $zone, string $id): Response
    {
        return $this->doUpdate($request, $identity, $this->accountsZone($identity, $username, $zone), $id, false);
    }

    public function accountDeleteRecord(Request $request, Identity $identity, string $username, string $zone, string $id): Response
    {
        return $this->doDelete($request, $identity, $this->accountsZone($identity, $username, $zone), $id);
    }

    public function accountReset(Request $request, Identity $identity, string $username, string $zone): Response
    {
        $row = $this->accountsZone($identity, $username, $zone);

        return $this->respond($this->actions->reset($identity, $request, $row), $row);
    }

    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $zone */
    private function doAdd(Request $request, Identity $identity, array $zone, bool $server): Response
    {
        [$outcome, $errors] = $this->actions->addRecord($identity, $request, $zone, self::input($request), $server && $this->authorizer->allows($identity, 'server.dns'));

        return $errors !== [] ? $this->invalid($errors) : $this->respond($outcome, $zone, 201);
    }

    /** @param array<string, mixed> $zone */
    private function doUpdate(Request $request, Identity $identity, array $zone, string $id, bool $server): Response
    {
        $record = $this->zones->record((int) $zone['id'], (int) $id) ?? throw HttpException::notFound();
        [$outcome, $errors] = $this->actions->updateRecord($identity, $request, $zone, $record, self::input($request), $server && $this->authorizer->allows($identity, 'server.dns'));

        return $errors !== [] ? $this->invalid($errors) : $this->respond($outcome, $zone);
    }

    /** @param array<string, mixed> $zone */
    private function doDelete(Request $request, Identity $identity, array $zone, string $id): Response
    {
        $record = $this->zones->record((int) $zone['id'], (int) $id) ?? throw HttpException::notFound();
        $outcome = $this->actions->deleteRecord($identity, $request, $zone, $record);

        return $outcome->status === DnsOutcome::DONE ? ApiResponse::noContent() : $this->respond($outcome, $zone);
    }

    /** @return array<string, string> errors */
    private function saveProfile(Request $request, Identity $identity, int $ownerId): array
    {
        $now = $this->zones->profile($ownerId);
        $body = (array) $request->json();
        $template = \array_key_exists('template', $body) ? (\is_string($body['template']) ? $body['template'] : null) : $now['template'];

        return $this->actions->saveProfile($identity, $request, $ownerId, $request->input('nameservers') ?? $now['nameservers'], $template);
    }

    /** The profile this login may set: the server's for an administrator, their own for a reseller. */
    private function ownerOf(Identity $identity): int
    {
        return $this->authorizer->allows($identity, 'server.dns') ? DnsZones::SERVER : (int) $identity->id;
    }

    /** @return array<string, mixed> */
    private function presentSettings(int $ownerId): array
    {
        $profile = $this->zones->profile($ownerId);
        $look = $this->checks->lookup([], $profile['nameservers']);

        return ($ownerId === DnsZones::SERVER ? ['addresses' => $this->zones->addresses(), 'server' => ['running' => $look['running'], 'version' => $look['version']]] : []) + [
            'nameservers' => \array_map(static fn(string $ns): array => [
                'name' => $ns,
                'addresses' => $look['hosts'][$ns]['addresses'] ?? [],
                'leads_here' => $look['known'] ? ($look['hosts'][$ns]['here'] ?? false) : null,
            ], $profile['nameservers']),
            'template' => $profile['template'],
        ];
    }

    /**
     * @param array<string, mixed> $zone
     *
     * @return array<string, mixed>
     */
    private function presentZone(array $zone): array
    {
        return [
            'name' => (string) $zone['name'],
            'state' => (string) $zone['state'],
            'error' => $zone['error'],
            'applied_at' => $zone['applied_at'],
        ];
    }

    /**
     * @param array<string, mixed> $zone
     *
     * @return array<string, mixed>
     */
    private function presentFull(array $zone, bool $check): array
    {
        $zone = $this->zones->zone((int) $zone['id']) ?? $zone;
        $name = (string) $zone['name'];
        $account = $zone['account_id'] === null ? null : $this->accounts->byId((int) $zone['account_id']);
        $nameservers = $this->zones->nameserversFor($account === null || $account['owner_id'] === null ? null : (int) $account['owner_id'], $name);
        $out = $this->presentZone($zone) + [
            'nameservers' => $nameservers,
            'records' => \array_map(static fn(array $r): array => [
                'id' => (int) $r['id'], 'name' => (string) $r['name'], 'full_name' => DnsRecords::full((string) $r['name'], $name),
                'type' => (string) $r['type'], 'ttl' => (int) $r['ttl'], 'content' => (string) $r['content'],
            ], $this->zones->records((int) $zone['id'])),
        ];

        if ($check) {
            $look = $this->checks->lookup([$name], []);
            $found = $look['known'] ? ($look['zones'][$name] ?? []) : null;
            $out['delegation'] = ['state' => DnsChecks::delegation($nameservers, $found), 'nameservers' => $found];
        }

        return $out;
    }

    /** @param array<string, mixed>|null $zone */
    private function respond(DnsOutcome $outcome, ?array $zone, int $done = 200): Response
    {
        return match ($outcome->status) {
            DnsOutcome::REFUSED => $this->invalid([($outcome->code ?: 'input') => (string) $outcome->message]),
            DnsOutcome::FAILED => new JsonResponse(['error' => $outcome->code, 'message' => $this->localization->get('Dns.refused'), 'detail' => $outcome->message], 502),
            DnsOutcome::PENDING => ApiResponse::accepted($zone === null ? null : $this->presentFull($zone, false)),
            default => ApiResponse::item($zone === null ? null : $this->presentFull($zone, false), $done),
        };
    }

    /** @param array<string, string> $errors */
    private function invalid(array $errors): JsonResponse
    {
        // A template's error names its line: "key|line".
        $messages = [];

        foreach ($errors as $field => $key) {
            [$key, $line] = \explode('|', $key . '|');
            $messages[$field] = $this->localization->get($key, ['line' => $line]);
        }

        return new JsonResponse(['error' => 'invalid', 'errors' => $messages], 422);
    }

    /** @return array<string, mixed> */
    private static function input(Request $request): array
    {
        $input = [];

        foreach (['name', 'type', 'ttl', 'content'] as $field) {
            if ($request->input($field) !== null) {
                $input[$field] = $request->input($field);
            }
        }

        return $input;
    }

    /** @return array<string, mixed> */
    private function anyZone(string $name): array
    {
        $zone = $this->zones->zoneByName(\strtolower($name));

        return $zone !== null && $zone['state'] !== DnsSync::REMOVING ? $zone : throw HttpException::notFound();
    }

    /** @return array<string, mixed> */
    private function accountsZone(Identity $identity, string $username, string $name): array
    {
        $account = $this->accounts->findByUsername($identity, $username) ?? throw HttpException::notFound();
        $zone = $this->anyZone($name);

        return $zone['account_id'] !== null && (int) $zone['account_id'] === (int) $account['id'] ? $zone : throw HttpException::notFound();
    }
}
