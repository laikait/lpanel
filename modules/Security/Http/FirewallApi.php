<?php

declare(strict_types=1);

namespace App\Modules\Security\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Localization\Localization;
use App\Modules\Security\Firewall\Cidr;
use App\Modules\Security\Firewall\Entries;
use App\Modules\Security\Firewall\Firewall;

/**
 * The firewall through the API (server.firewall), with the same rules as the page.
 *
 *   GET    /admin/api/v1/firewall                 state, ports, both lists, blocked countries
 *   POST   /admin/api/v1/firewall/ports           {port, proto, sources?}   open a port, or limit it to addresses
 *   DELETE /admin/api/v1/firewall/ports/{proto}/{port}
 *   POST   /admin/api/v1/firewall/entries         {list: allow|deny, address, note?, duration?}
 *   DELETE /admin/api/v1/firewall/entries/{id}
 *   PUT    /admin/api/v1/firewall/countries       {codes: ["CN", …], refresh?}   the countries blocked, whole
 *
 *   200 done   201 made   204 removed   404 no such entry
 *   409 refused (it would shut the caller out, or a port the panel needs)
 *   422 invalid input   502 the server refused it   503 the agent is not answering
 */
final class FirewallApi
{
    public function __construct(
        private readonly Firewall $firewall,
        private readonly Entries $entries,
        private readonly Localization $localization,
    ) {}

    public function show(): JsonResponse
    {
        $info = $this->firewall->info();

        if ($info === null) {
            return $this->failure('Security.fw_error_unreachable');
        }

        return ApiResponse::item([
            'firewall' => $info,
            'allow' => $this->rows($this->entries->all(Entries::ALLOW)),
            'deny' => $this->rows($this->entries->all(Entries::DENY)),
            'countries' => $this->entries->countries(),
        ]);
    }

    public function openPort(Request $request, Identity $identity): JsonResponse
    {
        $sources = $request->input('sources');
        $sources = \is_array($sources) ? \array_values(\array_map(static fn(mixed $s): string => \is_scalar($s) ? (string) $s : '', $sources)) : [];
        [$ok, $why] = $this->firewall->openPort($identity, $request, \trim((string) $request->input('port')), (string) $request->input('proto'), $sources);

        return $ok ? ApiResponse::item(['port' => (string) $request->input('port'), 'proto' => (string) $request->input('proto'), 'sources' => $sources]) : $this->failure((string) $why);
    }

    public function closePort(Request $request, Identity $identity, string $proto, string $port): Response
    {
        [$ok, $why] = $this->firewall->closePort($identity, $request, $port, $proto);

        return $ok ? ApiResponse::noContent() : $this->failure((string) $why);
    }

    public function addEntry(Request $request, Identity $identity): JsonResponse
    {
        $list = (string) $request->input('list');
        [$ok, $why] = $this->firewall->addEntry($identity, $request, $list, (string) $request->input('address'), (string) $request->input('note'), (string) ($request->input('duration') ?: 'never'));

        if (!$ok) {
            return $this->failure((string) $why);
        }

        [$cidr] = Cidr::normalize((string) $request->input('address'));
        $row = $cidr === null ? null : $this->entries->findAddress($list, $cidr);

        return ApiResponse::created($row === null ? [] : $this->row($row));
    }

    public function removeEntry(Request $request, Identity $identity, string $id): Response
    {
        [$ok, $why] = $this->firewall->removeEntry($identity, $request, (int) $id);

        return $ok ? ApiResponse::noContent() : $this->failure((string) $why);
    }

    public function countries(Request $request, Identity $identity): JsonResponse
    {
        $codes = $request->input('codes');
        [$ok, $why] = $this->firewall->setCountries($identity, $request, \is_array($codes) ? \array_values($codes) : [], (bool) $request->input('refresh'));

        return $ok ? ApiResponse::item(['countries' => $this->entries->countries()]) : $this->failure((string) $why);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $rows): array
    {
        return \array_map($this->row(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function row(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'list' => (string) $row['list'], 'address' => (string) $row['cidr'], 'note' => (string) $row['note'],
            'source' => (string) $row['source'], 'expires_at' => $row['expires_at'], 'created_at' => $row['created_at'],
        ];
    }

    /** A refusal, in the status that says whose it is. */
    private function failure(string $why): JsonResponse
    {
        $message = \str_starts_with($why, 'Security.') ? $this->localization->get($why) : $why;
        $status = match (true) {
            $why === 'Security.fw_error_unreachable', $why === 'Security.fw_error_off' => 503,
            $why === 'Security.fw_error_missing' => 404,
            $why === 'Security.fw_error_exists' => 409,
            \str_starts_with($why, 'Security.') => 422,
            \str_contains($why, 'shut you out') || \str_contains($why, 'no longer reach') || \str_contains($why, 'how the panel and SSH') => 409,
            default => 502,
        };

        return new JsonResponse(['error' => $status === 422 ? 'invalid' : 'refused', 'message' => $message], $status);
    }
}
