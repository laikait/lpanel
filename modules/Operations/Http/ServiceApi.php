<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Localization\Localization;
use App\Modules\Operations\Services\Services;

/**
 * The services through the API (server.services).
 *
 *   GET  /admin/api/v1/services                     every service, how it is doing, what the watchdog knows
 *   POST /admin/api/v1/services/{unit}/{action}     start, stop, restart, reload, enable, disable
 *   PUT  /admin/api/v1/services/{unit}/watch        {watch: bool}
 *   GET  /admin/api/v1/services/events              what the watchdog and administrators did lately
 *
 *   200 done   409 not allowed for that service   422 not an action   502 the server refused   503 the agent is not answering
 */
final class ServiceApi
{
    public function __construct(
        private readonly Services $services,
        private readonly Localization $localization,
    ) {}

    public function index(): JsonResponse
    {
        $list = $this->services->list();

        if ($list === null) {
            return $this->failure('Operations.error_unreachable');
        }

        return ApiResponse::item(['services' => $list]);
    }

    public function events(): JsonResponse
    {
        return ApiResponse::item(['events' => $this->services->events(100)]);
    }

    public function act(Request $request, Identity $identity, string $unit, string $action): JsonResponse
    {
        [$ok, $why] = $this->services->act($identity, $request, $unit, $action);

        if (!$ok) {
            return $this->failure((string) $why);
        }

        foreach ($this->services->list() ?? [] as $s) {
            if ($s['unit'] === $unit) {
                return ApiResponse::item($s);
            }
        }

        return ApiResponse::item(['unit' => $unit]);
    }

    public function watch(Request $request, Identity $identity, string $unit): JsonResponse
    {
        $this->services->setWatch($identity, $request, $unit, (bool) $request->input('watch', true));

        return ApiResponse::item(['unit' => $unit, 'watch' => (bool) $request->input('watch', true)]);
    }

    private function failure(string $why): JsonResponse
    {
        $status = match (true) {
            $why === 'Operations.error_unreachable' => 503,
            $why === 'Operations.error_action' => 422,
            \str_contains($why, 'is not a service') || \str_contains($why, 'is not an action') => 422,
            \str_contains($why, 'cannot be') => 409,
            default => 502,
        };

        return new JsonResponse(['error' => $status === 422 ? 'invalid' : 'refused', 'message' => \str_starts_with($why, 'Operations.') ? $this->localization->get($why) : $why], $status);
    }
}
