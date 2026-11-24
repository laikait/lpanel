<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Localization\Localization;
use App\Modules\Operations\Alerts\AlertChannels;
use App\Modules\Operations\Alerts\Alerts;
use App\Modules\Operations\Alerts\AlertSettings;

/**
 * Alerts through the API (server.alerts, root only). Secrets (bot token,
 * webhook address, signing key) go in and are never given back.
 *
 *   GET    /admin/api/v1/alerts                      channels, thresholds, the latest alerts (?limit=, ?category=)
 *   POST   /admin/api/v1/alerts/channels             {kind: email|telegram|slack|webhook, name, to | token, chat | url, signing_key?, categories?, min_severity?}   201
 *   PUT    /admin/api/v1/alerts/channels/{id}        {categories?, min_severity?, enabled?}                                      200
 *   DELETE /admin/api/v1/alerts/channels/{id}                                                                                   204
 *   POST   /admin/api/v1/alerts/channels/{id}/test   send a test alert to it                                                    200 or 502 with why
 *   PUT    /admin/api/v1/alerts/settings             {disk, memory, load, ssl, quiet}                                            200
 *   GET    /admin/api/v1/alerts/{id}                 one alert with what became of it, channel by channel                        200
 *
 *   404 no such channel or alert   422 refused arguments   502 the test did not arrive
 */
final class AlertApi
{
    public function __construct(
        private readonly AlertChannels $channels,
        private readonly Alerts $alerts,
        private readonly AlertSettings $settings,
        private readonly Localization $localization,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $category = (string) $request->query('category', '');

        return ApiResponse::item([
            'channels' => $this->channels->all(),
            'settings' => $this->settings->get(),
            'alerts' => $this->alerts->history((int) $request->query('limit', 50), \in_array($category, AlertChannels::CATEGORIES, true) ? $category : null),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $one = \array_values(\array_filter($this->alerts->history(500), static fn(array $a): bool => $a['id'] === (int) $id));

        return $one === [] ? $this->error('not_found', 'There is no such alert.', 404) : ApiResponse::item($one[0] + ['deliveries' => $this->alerts->deliveries((int) $id)]);
    }

    public function add(Request $request, Identity $identity): JsonResponse
    {
        [$c, $errors] = $this->channels->validate($this->input($request, ['kind', 'name', 'to', 'token', 'chat', 'url', 'signing_key', 'categories', 'min_severity', 'enabled']));

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $id = $this->channels->create($c, $identity, $request);

        return ApiResponse::item($this->channels->find($id) ?? [], 201);
    }

    public function change(Request $request, Identity $identity, string $id): JsonResponse
    {
        $existing = $this->channels->open((int) $id);

        if ($existing === null) {
            return $this->error('not_found', 'There is no such channel.', 404);
        }

        $input = $this->input($request, ['name', 'to', 'token', 'chat', 'url', 'signing_key', 'remove_signing_key', 'categories', 'min_severity', 'enabled']);
        [$c, $errors] = $this->channels->validate($input, $existing);

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $c['name'] = (string) ($input['name'] ?? $existing['name']);
        $this->channels->update((int) $id, $c, $identity, $request);

        return ApiResponse::item($this->channels->find((int) $id) ?? []);
    }

    public function remove(Request $request, Identity $identity, string $id): Response
    {
        return $this->channels->remove((int) $id, $identity, $request) ? ApiResponse::noContent() : $this->error('not_found', 'There is no such channel.', 404);
    }

    public function test(Request $request, Identity $identity, string $id): JsonResponse
    {
        [$ok, $why] = $this->alerts->test((int) $id, $identity, $request);

        if ($ok) {
            return ApiResponse::item(['delivered' => true]);
        }

        return $why === 'Operations.alerts_error_missing'
            ? $this->error('not_found', 'There is no such channel.', 404)
            : $this->error('delivery_failed', $why, 502);
    }

    public function saveSettings(Request $request, Identity $identity): JsonResponse
    {
        [$s, $errors] = $this->settings->validate($this->input($request, ['disk', 'memory', 'load', 'ssl', 'quiet']));

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $this->settings->save($s, $identity, $request);

        return ApiResponse::item($s);
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, mixed>
     */
    private function input(Request $request, array $names): array
    {
        $out = [];

        foreach ($names as $n) {
            if ($request->input($n) !== null) {
                $out[$n] = $request->input($n);
            }
        }

        return $out;
    }

    /** @param array<string, string> $errors */
    private function invalid(array $errors): JsonResponse
    {
        return $this->error('validation_failed', $this->localization->get('Operations.alerts_error_input'), 422, \array_map(fn(string $k): string => $this->localization->get($k), $errors));
    }

    /** @param array<string, string> $fields */
    private function error(string $code, string $message, int $status, array $fields = []): JsonResponse
    {
        return new JsonResponse(['error' => $code, 'message' => $message] + ($fields === [] ? [] : ['fields' => $fields]), $status);
    }
}
