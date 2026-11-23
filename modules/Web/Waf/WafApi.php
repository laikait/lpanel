<?php

declare(strict_types=1);

namespace App\Modules\Web\Waf;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Localization\Localization;

/**
 * The web application firewall through the API (server.waf).
 *
 *   GET /admin/api/v1/waf   the state, the server's settings, the latest events
 *   PUT /admin/api/v1/waf   {mode?: off|detect|block, paranoia?: 1-4}
 *
 *   200 done   422 invalid input   502 the server refused it   503 the agent is not answering
 */
final class WafApi
{
    public function __construct(
        private readonly Waf $waf,
        private readonly Localization $localization,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::item(['waf' => $this->waf->info(), 'settings' => $this->waf->settings(), 'counts' => $this->waf->counts(), 'events' => $this->waf->recent(limit: 50)]);
    }

    public function update(Request $request, Identity $identity): JsonResponse
    {
        $mode = $request->input('mode');
        $level = $request->input('paranoia');

        if ($mode !== null) {
            $why = $this->waf->setDefault($identity, $request, (string) $mode);

            if ($why !== null) {
                return $this->failure($why);
            }
        }

        if ($level !== null) {
            [$ok, $why] = $this->waf->setParanoia($identity, $request, (int) $level);

            if (!$ok) {
                return $this->failure((string) $why);
            }
        }

        return ApiResponse::item(['settings' => $this->waf->settings()]);
    }

    private function failure(string $why): JsonResponse
    {
        $status = match ($why) {
            'Web.waf_error_mode', 'Web.waf_error_paranoia' => 422,
            'Web.waf_error_unreachable', 'Web.waf_error_unavailable' => 503,
            default => 502,
        };

        return new JsonResponse(['error' => $status === 422 ? 'invalid' : 'refused', 'message' => \str_starts_with($why, 'Web.') ? $this->localization->get($why) : $why], $status);
    }
}
