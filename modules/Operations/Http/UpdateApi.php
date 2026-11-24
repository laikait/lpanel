<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Localization\Localization;
use App\Modules\Operations\Updates\Updates;
use App\Modules\Operations\Updates\UpdateSettings;

/**
 * Updates through the API (server.updates, root only).
 *
 *   GET  /admin/api/v1/updates              the release running, what the last check found, what runs now, settings, latest runs
 *   POST /admin/api/v1/updates/check        ask the server what can be updated                       202 run
 *   POST /admin/api/v1/updates/apply        {scope: all|security|php|packages, packages?: [names]}    202 run
 *   POST /admin/api/v1/updates/panel        {version}: upgrade LPanel to the release the channel offers  202 run
 *   POST /admin/api/v1/updates/reboot       {cancel?: bool}: reboot a minute from now, or call it off   200
 *   PUT  /admin/api/v1/updates/settings     {os, php, hour, exclude, panel, reboot}                    200 settings
 *   GET  /admin/api/v1/updates/runs/{id}    one run, with what it did                                  200 run
 *
 *   409 something runs already   422 refused arguments   502 the server refused   503 the agent is not answering
 */
final class UpdateApi
{
    public function __construct(
        private readonly Updates $updates,
        private readonly UpdateSettings $settings,
        private readonly Localization $localization,
    ) {}

    public function index(): JsonResponse
    {
        $this->updates->settle();

        return ApiResponse::item([
            'installed' => $this->updates->installed(),
            'latest' => $this->updates->latest(),
            'running' => $this->updates->busy(),
            'settings' => $this->settings->get(),
            'runs' => $this->updates->runs(20),
        ]);
    }

    public function check(Request $request, Identity $identity): JsonResponse
    {
        return $this->started(...$this->updates->check($identity, $request));
    }

    public function apply(Request $request, Identity $identity): JsonResponse
    {
        $packages = $request->input('packages', []);

        return $this->started(...$this->updates->apply($identity, $request, (string) $request->input('scope', ''), \is_array($packages) ? \array_values(\array_filter($packages, 'is_string')) : []));
    }

    public function panel(Request $request, Identity $identity): JsonResponse
    {
        return $this->started(...$this->updates->upgrade($identity, $request, (string) $request->input('version', '')));
    }

    public function reboot(Request $request, Identity $identity): JsonResponse
    {
        [$ok, $why] = $this->updates->reboot($identity, $request, (bool) $request->input('cancel', false));

        return $ok ? ApiResponse::item(['rebooting' => !(bool) $request->input('cancel', false)]) : $this->failure((string) $why);
    }

    public function saveSettings(Request $request, Identity $identity): JsonResponse
    {
        $input = [];

        foreach (['os', 'php', 'hour', 'exclude', 'panel', 'reboot'] as $k) {
            if ($request->input($k) !== null) {
                $input[$k] = $request->input($k);
            }
        }

        [$s, $errors] = $this->settings->validate($input);

        if ($errors !== []) {
            return $this->error('validation_failed', $this->localization->get('Operations.updates_error_settings'), 422, \array_map(fn(string $k): string => $this->localization->get($k), $errors));
        }

        $this->settings->save($s, $identity, $request);

        return ApiResponse::item($s);
    }

    public function run(string $id): JsonResponse
    {
        $run = $this->updates->find((int) $id);

        return $run === null ? $this->error('not_found', 'There is no such run.', 404) : ApiResponse::item($run);
    }

    /** @param array<string, mixed>|null $run */
    private function started(?array $run, ?string $why): JsonResponse
    {
        if ($why !== null || $run === null) {
            return $this->failure((string) $why);
        }

        return ApiResponse::item($run, 202);
    }

    private function failure(string $why): JsonResponse
    {
        $status = match ($why) {
            'Operations.error_unreachable' => 503,
            'Operations.updates_busy' => 409,
            'Operations.updates_error_scope', 'Operations.updates_error_packages', 'Operations.updates_error_version', 'Operations.updates_error_not_newer' => 422,
            default => 502,
        };
        $text = \str_starts_with($why, 'Operations.') ? $this->localization->get($why) : $why;

        return $this->error(match ($status) {
            503 => 'agent_unavailable',
            409 => 'busy',
            422 => 'validation_failed',
            default => 'server_refused',
        }, $text, $status);
    }

    /** @param array<string, string> $fields */
    private function error(string $code, string $message, int $status, array $fields = []): JsonResponse
    {
        return new JsonResponse(['error' => $code, 'message' => $message] + ($fields === [] ? [] : ['fields' => $fields]), $status);
    }
}
