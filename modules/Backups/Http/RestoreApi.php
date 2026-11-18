<?php

declare(strict_types=1);

namespace App\Modules\Backups\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Backups\BackupRestores;
use App\Modules\Backups\BackupRuns;
use App\Modules\Backups\Destinations;
use App\Modules\Packages\Packages;

/**
 * Restores through the API (server.backups), with the same rules as the
 * Admin panel. destination is a destination's id; the schedule's when left
 * out.
 *
 *   GET  /admin/api/v1/backups/snapshots?destination=           every account's snapshots there
 *   GET  /admin/api/v1/backups/snapshots/{snapshot}/plan        whether its account can be made again here; 409 with problems if not
 *   POST /admin/api/v1/backups/restores                         {snapshot, destination?, package_id?}: make the account again; 202
 *   GET  /admin/api/v1/backups/restores                         recent restores and downloads
 *   GET  /admin/api/v1/backups/restores/{id}
 *   GET  /admin/api/v1/accounts/{username}/backups/files?snapshot=&path=
 *   POST /admin/api/v1/accounts/{username}/backups/restores     {snapshot, kind: files|mailbox|database|account, paths?, databases?, folder?}; 202
 *   POST /admin/api/v1/accounts/{username}/backups/downloads    {snapshot, path?, database?}; 202
 */
final class RestoreApi
{
    public function __construct(
        private readonly BackupRestores $restores,
        private readonly BackupRuns $runs,
        private readonly Destinations $destinations,
        private readonly Accounts $accounts,
        private readonly Packages $packages,
        private readonly Localization $localization,
    ) {}

    public function snapshots(Request $request): JsonResponse
    {
        $destination = $this->destination($request);
        $list = $this->runs->snapshots($destination);

        return $list === null ? $this->error('unavailable', 'Backups.error_snapshots', 502) : ApiResponse::item(['destination' => $destination, 'snapshots' => $list]);
    }

    public function plan(Request $request, string $snapshot): JsonResponse
    {
        [$plan, $why, $problems] = $this->restores->planAccount($this->destination($request), $snapshot);

        if ($plan === null) {
            return $this->problems($why, $problems);
        }

        unset($plan['meta']);

        return ApiResponse::item(['plan' => $plan]);
    }

    public function recreate(Request $request, Identity $identity): JsonResponse
    {
        $package = $request->input('package_id');
        $packageId = \is_int($package) || (\is_string($package) && \ctype_digit($package)) ? (int) $package : null;

        if ($packageId !== null && $this->packages->find($identity, $packageId) === null) {
            return $this->error('invalid', 'Backups.import_no_package', 422);
        }

        [$row, $why, $problems] = $this->restores->recreate($identity, $request, $this->destination($request), (string) $request->input('snapshot', ''), $packageId);

        if ($row === null) {
            return $this->problems($why, $problems);
        }

        return $why !== null ? $this->error('failed', $why, 502) : ApiResponse::item(self::present($row), 202);
    }

    public function restores(Request $request): JsonResponse
    {
        $this->restores->settle();
        $username = (string) $request->query('account', '');

        return ApiResponse::item(['restores' => \array_map(self::present(...), $this->restores->recent(null, 50, $username === '' ? null : $username))]);
    }

    public function show(string $id): JsonResponse
    {
        $this->restores->settle();

        return ApiResponse::item(self::present($this->restores->find((int) $id) ?? throw HttpException::notFound()));
    }

    public function files(Request $request, Identity $identity, string $username): JsonResponse
    {
        $this->account($identity, $username);
        [$listing, $why] = $this->restores->files($this->destination($request), (string) $request->query('snapshot', ''), $username, (string) $request->query('path', ''));

        return $listing === null ? $this->error('refused', (string) $why, self::status((string) $why)) : ApiResponse::item($listing);
    }

    public function restore(Request $request, Identity $identity, string $username): JsonResponse
    {
        $account = $this->account($identity, $username);
        $folder = $request->input('folder');
        [$row, $why] = $this->restores->restore(
            $identity,
            $request,
            $account,
            $this->destination($request),
            (string) $request->input('snapshot', ''),
            (string) $request->input('kind', 'files'),
            self::strings($request->input('paths')),
            self::strings($request->input('databases')),
            $folder === true || $folder === 1 || $folder === '1' || $folder === 'true',
        );

        return $this->answer($row, $why);
    }

    public function download(Request $request, Identity $identity, string $username): JsonResponse
    {
        $account = $this->account($identity, $username);
        [$row, $why] = $this->restores->download(
            $identity,
            $request,
            $account,
            $this->destination($request),
            (string) $request->input('snapshot', ''),
            (string) $request->input('path', ''),
            (string) $request->input('database', ''),
        );

        return $this->answer($row, $why);
    }

    // -----------------------------------------------------------------------

    /** @param array<string, mixed>|null $row */
    private function answer(?array $row, ?string $why): JsonResponse
    {
        if ($row === null) {
            return $this->error('refused', (string) $why, self::status((string) $why));
        }

        if ($why !== null) {
            return new JsonResponse(['error' => 'failed', 'message' => $this->say($why), 'restore' => self::present($row)], 502);
        }

        return ApiResponse::item(self::present($row), $row['state'] === BackupRestores::DONE ? 200 : 202);
    }

    /** @param list<array{0: string, 1: array<string, string>}> $problems */
    private function problems(?string $why, array $problems): JsonResponse
    {
        if ($problems === []) {
            return $this->error('refused', (string) $why, self::status((string) $why));
        }

        return new JsonResponse([
            'error' => 'conflict',
            'message' => $this->say((string) $why),
            'problems' => \array_map(fn(array $p): string => $this->localization->get($p[0], $p[1]), $problems),
        ], 409);
    }

    private static function status(string $why): int
    {
        return match ($why) {
            'Backups.error_unreachable' => 503,
            'Backups.error_restore_busy', 'Backups.error_account_status' => 409,
            'Backups.error_not_in_snapshot' => 404,
            'Backups.error_off', 'Backups.error_failed', 'Backups.error_wrong_password' => 502,
            default => 422,
        };
    }

    private function destination(Request $request): int
    {
        $id = $request->input('destination') ?? $request->query('destination');

        if (\is_int($id) || (\is_string($id) && \ctype_digit($id) && $id !== '0')) {
            return (int) ($this->destinations->find((int) $id)['id'] ?? throw HttpException::notFound());
        }

        return (int) ($this->restores->defaultDestination()['id'] ?? 0);
    }

    /** @return array<string, mixed> */
    private function account(Identity $identity, string $username): array
    {
        return $this->accounts->findByUsername($identity, $username) ?? throw HttpException::notFound();
    }

    private function error(string $code, string $why, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $code, 'message' => $this->say($why)], $status);
    }

    private function say(string $why): string
    {
        [$key, $rest] = \explode(':', $why, 2) + [1 => ''];

        return \str_starts_with($key, 'Backups.') ? $this->localization->get($key) . ($rest !== '' ? ':' . $rest : '') : $why;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (\is_string($value)) {
            return $value === '' ? [] : \array_map('trim', \explode(',', $value));
        }

        return \is_array($value) ? \array_values(\array_filter(\array_map(static fn(mixed $v): string => \is_scalar($v) ? (string) $v : '', $value), static fn(string $v): bool => $v !== '')) : [];
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    public static function present(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'account' => (string) $r['username'], 'kind' => (string) $r['kind'], 'state' => (string) $r['state'],
            'destination_id' => (int) $r['destination_id'], 'snapshot' => (string) $r['snapshot'], 'what' => $r['what'],
            'result' => $r['result'] === [] ? null : $r['result'], 'error' => $r['error'],
            'requested_by' => (string) $r['requested_by'], 'by_owner' => (bool) $r['by_owner'],
            'created_at' => $r['created_at'], 'finished_at' => $r['finished_at'],
        ];
    }
}
