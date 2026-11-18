<?php

declare(strict_types=1);

namespace App\Modules\Backups\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Backups\BackupRuns;
use App\Modules\Backups\BackupSettings;
use App\Modules\Backups\Destinations;
use App\Modules\Packages\Http\PackageApi;

/**
 * Backups through the API (server.backups), with the same rules as the
 * Admin panel. Secrets are sent in the body and never returned.
 *
 *   GET    /admin/api/v1/backups                      settings, destinations, the run going on, recent runs
 *   PUT    /admin/api/v1/backups/settings
 *   POST   /admin/api/v1/backups/destinations         201; 422 bad input; 502 the agent could not reach it
 *   PUT    /admin/api/v1/backups/destinations/{id}
 *   DELETE /admin/api/v1/backups/destinations/{id}    204; 409 used by the schedule
 *   POST   /admin/api/v1/backups/runs                 {accounts?: [...], destination?: id} 202
 *   GET    /admin/api/v1/backups/runs/{id}
 *   GET    /admin/api/v1/accounts/{username}/backups  the account's last backup and snapshots
 */
final class BackupApi
{
    public function __construct(
        private readonly Destinations $destinations,
        private readonly BackupSettings $settings,
        private readonly BackupRuns $runs,
        private readonly Accounts $accounts,
        private readonly Localization $localization,
    ) {}

    public function index(): JsonResponse
    {
        $this->runs->settle();
        $running = $this->runs->running();

        return ApiResponse::item([
            'settings' => $this->settings->get(),
            'destinations' => \array_map(self::destination(...), $this->destinations->all()),
            'running' => $running === null ? null : ['id' => (int) $running['id'], 'progress' => $this->runs->progress()],
            'runs' => \array_map(self::run(...), $this->runs->recent()),
        ]);
    }

    public function saveSettings(Request $request, Identity $identity): JsonResponse
    {
        $input = [];

        foreach (\array_keys(BackupSettings::DEFAULTS) as $name) {
            if ($request->input($name) !== null) {
                $input[$name] = $request->input($name);
            }
        }

        $errors = $this->settings->save($identity, $request, $input, $this->destinations->ids());

        return $errors !== [] ? PackageApi::invalid($errors, $this->localization) : ApiResponse::item(['settings' => $this->settings->get()]);
    }

    public function addDestination(Request $request, Identity $identity): JsonResponse
    {
        return $this->saved($this->destinations->save($identity, $request, $this->body($request)), 201);
    }

    public function changeDestination(Request $request, Identity $identity, string $id): JsonResponse
    {
        return $this->saved($this->destinations->save($identity, $request, $this->body($request), $this->find($id)), 200);
    }

    public function removeDestination(Request $request, Identity $identity, string $id): Response
    {
        $d = $this->find($id);

        if ($this->settings->get()['destination_id'] === (int) $d['id']) {
            return $this->error('conflict', 'Backups.error_in_use', 409);
        }

        $why = $this->destinations->remove($identity, $request, $d);

        return $why === null ? ApiResponse::noContent() : $this->error('refused', $why, $why === 'Backups.error_busy' ? 409 : 502);
    }

    public function startRun(Request $request, Identity $identity): JsonResponse
    {
        $accounts = $request->input('accounts');
        $destination = $request->input('destination');
        [$run, $why] = $this->runs->start(
            $identity,
            $request,
            'manual',
            \is_int($destination) || (\is_string($destination) && \ctype_digit($destination)) ? (int) $destination : null,
            \is_array($accounts) ? \array_values(\array_map('strval', \array_filter($accounts, 'is_string'))) : null,
        );

        if ($run === null || $why !== null) {
            return $this->error('refused', (string) $why, match ($why) {
                'Backups.error_busy' => 409,
                'Backups.error_unreachable' => 503,
                'Backups.error_accounts', 'Backups.error_no_accounts', 'Backups.error_destination_needed' => 422,
                default => 502,
            });
        }

        return ApiResponse::accepted(self::run($run));
    }

    public function showRun(string $id): JsonResponse
    {
        $this->runs->settle();
        $run = \ctype_digit($id) ? $this->runs->find((int) $id) : null;

        return $run === null ? throw HttpException::notFound() : ApiResponse::item(self::run($run));
    }

    public function account(Identity $identity, string $username): JsonResponse
    {
        if ($this->accounts->findByUsername($identity, $username) === null) {
            throw HttpException::notFound();
        }

        $destination = $this->settings->get()['destination_id'];

        return ApiResponse::item([
            'last' => $this->runs->lastFor($username),
            'snapshots' => $destination === 0 ? [] : $this->runs->snapshots($destination, $username),
        ]);
    }

    // -------------------------------------------------------------------------

    /** @param array{0: array<string, mixed>|null, 1: array<string, string>, 2: string|null} $saved */
    private function saved(array $saved, int $status): JsonResponse
    {
        [$d, $errors, $why] = $saved;

        if ($errors !== []) {
            return PackageApi::invalid($errors, $this->localization);
        }

        if ($d === null) {
            return $this->error('refused', (string) $why, match ($why) {
                'Backups.error_unreachable' => 503,
                'Backups.error_busy' => 409,
                default => 502,
            });
        }

        return ApiResponse::item(self::destination($d), $status);
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $input = [];

        foreach (['name', 'type', 'path', 'host', 'port', 'user', 'endpoint', 'scheme', 'region', 'bucket', 'prefix', 'access_key', 'secret_key', 'password'] as $field) {
            $v = $request->input($field);

            if (\is_string($v) || \is_int($v)) {
                $input[$field] = (string) $v;
            }
        }

        return $input;
    }

    private function error(string $code, string $why, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $code, 'message' => \str_starts_with($why, 'Backups.') ? $this->localization->get($why) : $why], $status);
    }

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        return (\ctype_digit($id) ? $this->destinations->find((int) $id) : null) ?? throw HttpException::notFound();
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    private static function destination(array $d): array
    {
        return [
            'id' => (int) $d['id'], 'name' => (string) $d['name'], 'type' => (string) $d['type'],
            'settings' => $d['settings'], 'where' => Destinations::describe($d),
            'public_key' => $d['public_key'], 'snapshots' => (int) $d['snapshots'], 'checked_at' => $d['checked_at'],
        ];
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private static function run(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'destination_id' => (int) $r['destination_id'], 'kind' => (string) $r['kind'], 'state' => (string) $r['state'],
            'accounts' => (int) $r['accounts'], 'ok' => (int) $r['ok'], 'failed' => (int) $r['failed'], 'error' => $r['error'],
            'started_by' => $r['started_by'], 'started_at' => $r['started_at'], 'finished_at' => $r['finished_at'],
            'results' => $r['results'], 'pruned' => $r['pruned'],
        ];
    }
}
