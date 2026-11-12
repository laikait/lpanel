<?php

declare(strict_types=1);

namespace App\Modules\Web\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Packages\Http\PackageApi;
use App\Modules\Web\AutoSsl;
use App\Modules\Web\Domains;
use App\Modules\Web\SslSites;
use App\Modules\Web\WebOutcome;

/**
 * Certificates through the API, with the same rules as the panels.
 *
 *   /admin/api/v1/accounts/{username}/ssl…   an account's sites (accounts.manage / accounts.modify)
 *   /admin/api/v1/ssl…                       AutoSSL's settings and the hostname (server.ssl)
 *
 *   200  done      202  ordered or recorded, still being carried out
 *   409  not now (an order is running, no authority, account not active)
 *   422  invalid input             502  the server refused it
 */
final class SslApi
{
    public function __construct(
        private readonly AutoSsl $ssl,
        private readonly SslSites $sites,
        private readonly Accounts $accounts,
        private readonly Domains $domains,
        private readonly Localization $localization,
    ) {}

    public function index(Identity $identity, string $username): JsonResponse
    {
        $account = $this->account($identity, $username);
        $this->ssl->settleAll();
        $rows = $this->sites->forAccount((int) $account['id']);
        $out = [];

        foreach ($this->ssl->namesOf($account) as $domainId => $names) {
            $domain = $this->domains->find((int) $account['id'], $domainId);

            if ($domain !== null) {
                $out[] = $this->present($domain, $rows[$domainId] ?? null, $names);
            }
        }

        return ApiResponse::collection($out);
    }

    public function issue(Request $request, Identity $identity, string $username, string $domain): JsonResponse
    {
        [$account, $row] = $this->site($identity, $username, $domain);
        [$order, $why] = $this->ssl->issueNow($identity, $request, $account, $row);

        if ($order === null) {
            return new JsonResponse(['error' => 'not_now', 'message' => $this->localization->get((string) $why)], 409);
        }

        $body = ['data' => self::order($order)];

        return new JsonResponse($body, $order['state'] === AutoSsl::RUNNING ? 202 : 200);
    }

    public function options(Request $request, Identity $identity, string $username, string $domain): JsonResponse
    {
        [$account, $row] = $this->site($identity, $username, $domain);
        $flag = static fn(mixed $v): ?bool => \is_bool($v) ? $v : null;
        $outcome = $this->ssl->setOptions($identity, $request, $account, $row, $flag($request->input('autossl')), $flag($request->input('force_https')));

        return $this->respond($outcome, $account, $row);
    }

    public function install(Request $request, Identity $identity, string $username, string $domain): JsonResponse
    {
        [$account, $row] = $this->site($identity, $username, $domain);
        [$ok, $why, $detail] = $this->ssl->install($identity, $request, $account, $row, (string) $request->input('key', ''), (string) $request->input('certificate', ''));

        if (!$ok) {
            return new JsonResponse([
                'error' => 'invalid',
                'message' => $this->localization->get((string) $why, ['names' => (string) $detail]),
                'detail' => $detail,
            ], 422);
        }

        return $this->respond(WebOutcome::done(), $account, $row);
    }

    public function remove(Request $request, Identity $identity, string $username, string $domain): JsonResponse
    {
        [$account, $row] = $this->site($identity, $username, $domain);

        return $this->respond($this->ssl->remove($identity, $request, $account, $row), $account, $row);
    }

    public function csr(Request $request, Identity $identity, string $username, string $domain): JsonResponse
    {
        [$account, $row] = $this->site($identity, $username, $domain);
        $subject = [];

        foreach (['key_type', 'country', 'state', 'locality', 'organization'] as $field) {
            $subject[$field] = (string) $request->input($field, '');
        }

        [$csr, $why, $detail] = $this->ssl->csr($identity, $request, $account, $row, $subject);

        if ($csr === null) {
            return new JsonResponse(['error' => 'invalid', 'message' => $this->localization->get((string) $why), 'detail' => $detail], 422);
        }

        return ApiResponse::item(['domain' => $row['name'], 'csr' => $csr]);
    }

    // -------------------------------------------------------------------------
    // Server-wide (server.ssl)

    public function settings(): JsonResponse
    {
        $this->ssl->settleAll();
        $s = $this->ssl->settings();
        $host = $this->ssl->hostInfo();

        return ApiResponse::item([
            'autossl' => $s['enabled'],
            'primary' => $s['primary'],
            'fallback' => $s['fallback'],
            'authorities' => $this->ssl->authorities(),
            'zerossl_eab_kid' => $s['eab_kid'] !== '' ? $s['eab_kid'] : null,
            'hostname' => $s['hostname'] !== '' ? $s['hostname'] : null,
            'hostname_autossl' => $s['host_autossl'],
            'hostname_certificate' => $host['cert'] ?? null,
        ]);
    }

    public function saveSettings(Request $request, Identity $identity): JsonResponse
    {
        $input = [];
        $map = ['autossl' => 'enabled', 'fallback' => 'fallback', 'hostname_autossl' => 'host_autossl', 'primary' => 'primary',
            'zerossl_eab_kid' => 'eab_kid', 'zerossl_eab_hmac' => 'eab_hmac', 'hostname' => 'hostname'];

        foreach ($map as $field => $key) {
            if ($request->input($field) !== null) {
                $input[$key] = $request->input($field);
            }
        }

        $errors = $this->ssl->saveSettings($identity, $request, $input);

        if ($errors !== []) {
            $named = [];

            foreach ($errors as $key => $message) {
                $named[(string) (\array_search($key, $map, true) ?: $key)] = $message;
            }

            return PackageApi::invalid($named, $this->localization);
        }

        return $this->settings();
    }

    public function run(): JsonResponse
    {
        return ApiResponse::item($this->ssl->run());
    }

    public function hostIssue(Request $request, Identity $identity): JsonResponse
    {
        [$order, $why] = $this->ssl->hostIssueNow($identity, $request);

        if ($order === null) {
            return new JsonResponse(['error' => 'not_now', 'message' => $this->localization->get((string) $why)], 409);
        }

        return new JsonResponse(['data' => self::order($order)], $order['state'] === AutoSsl::RUNNING ? 202 : 200);
    }

    public function hostInstall(Request $request, Identity $identity): JsonResponse
    {
        [$ok, $why, $detail] = $this->ssl->hostInstall($identity, $request, (string) $request->input('key', ''), (string) $request->input('certificate', ''));

        if (!$ok) {
            return new JsonResponse(['error' => 'invalid', 'message' => $this->localization->get((string) $why), 'detail' => $detail], 422);
        }

        return $this->settings();
    }

    public function showOrder(string $id): JsonResponse
    {
        $this->ssl->settleAll();
        $order = $this->ssl->order((int) $id) ?? throw HttpException::notFound();

        return ApiResponse::item(self::order($order));
    }

    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private static function order(array $order): array
    {
        $result = \json_decode((string) ($order['result'] ?? ''), true);

        return [
            'id' => (int) $order['id'],
            'kind' => $order['kind'],
            'state' => $order['state'],
            'error' => $order['error'],
            'excluded' => \is_array($result) ? ($result['excluded'] ?? []) : [],
            'authority' => \is_array($result) ? ($result['authority'] ?? null) : null,
            'created_at' => $order['created_at'],
            'finished_at' => $order['finished_at'],
        ];
    }

    /**
     * @param array<string, mixed> $domain
     * @param array<string, mixed>|null $s
     * @param list<string> $names
     *
     * @return array<string, mixed>
     */
    private function present(array $domain, ?array $s, array $names): array
    {
        $cert = $s !== null && $s['cert_id'] !== null ? [
            'id' => $s['cert_id'],
            'source' => $s['source'],
            'authority' => $s['authority'],
            'issuer' => $s['issuer'],
            'names' => $s['names'],
            'key_type' => $s['key_type'],
            'self_signed' => (bool) $s['self_signed'],
            'not_before' => $s['not_before'],
            'not_after' => $s['not_after'],
        ] : null;

        return [
            'domain' => $domain['name'],
            'names' => $names,
            'certificate' => $cert,
            'autossl' => $s === null || (bool) $s['autossl'],
            'force_https' => $s === null || (bool) $s['force_https'],
            'ordering' => $this->ssl->runningFor((int) $domain['id']) !== null,
            'last_error' => $s['last_error'] ?? null,
            'retry_at' => $s['retry_at'] ?? null,
            'excluded' => $s['excluded'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $row
     */
    private function respond(WebOutcome $outcome, array $account, array $row): JsonResponse
    {
        $names = $this->ssl->namesOf($account)[(int) $row['id']] ?? [(string) $row['name']];
        $body = $this->present($row, $this->sites->forDomain((int) $row['id']), $names);

        return match ($outcome->status) {
            WebOutcome::PENDING => ApiResponse::accepted($body),
            WebOutcome::FAILED => new JsonResponse(['error' => $outcome->code, 'message' => $this->localization->get('Web.refused'), 'detail' => $outcome->message], 502),
            default => ApiResponse::item($body),
        };
    }

    /** @return array<string, mixed> */
    private function account(Identity $identity, string $username): array
    {
        return $this->accounts->findByUsername($identity, $username) ?? throw HttpException::notFound();
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function site(Identity $identity, string $username, string $domain): array
    {
        $account = $this->account($identity, $username);
        $row = $this->domains->findByName((int) $account['id'], \strtolower($domain));

        if ($row === null || $row['kind'] === Domains::ALIAS) {
            throw HttpException::notFound();
        }

        return [$account, $row];
    }
}
