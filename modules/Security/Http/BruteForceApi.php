<?php

declare(strict_types=1);

namespace App\Modules\Security\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Localization\Localization;
use App\Modules\Security\BruteForce\BruteForce;

/**
 * Brute-force blocking through the API (server.firewall).
 *
 *   GET /admin/api/v1/bruteforce   the settings, the busiest addresses, the latest blocks
 *   PUT /admin/api/v1/bruteforce   {enabled?, threshold?, window?, duration?}
 *
 *   200 done   422 invalid input
 */
final class BruteForceApi
{
    public function __construct(
        private readonly BruteForce $bruteForce,
        private readonly Localization $localization,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::item(['settings' => $this->bruteForce->settings()] + $this->bruteForce->overview());
    }

    public function update(Request $request, Identity $identity): JsonResponse
    {
        $changes = \array_intersect_key((array) $request->input(), \array_flip(['enabled', 'threshold', 'window', 'duration']));
        $why = $this->bruteForce->configure($identity, $request, $changes);

        if ($why !== null) {
            return new JsonResponse(['error' => 'invalid', 'message' => $this->localization->get($why)], 422);
        }

        return ApiResponse::item(['settings' => $this->bruteForce->settings()]);
    }
}
