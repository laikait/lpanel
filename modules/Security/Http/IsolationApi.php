<?php

declare(strict_types=1);

namespace App\Modules\Security\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Localization\Localization;
use App\Modules\Security\Isolation\Isolation;

/**
 * Account isolation through the API (server.firewall).
 *
 *   GET /admin/api/v1/isolation   what was chosen and what the server has
 *   PUT /admin/api/v1/isolation   {hidepid?: bool, links?: bool}
 *
 *   200 done   502 the server refused it   503 the agent is not answering
 */
final class IsolationApi
{
    public function __construct(
        private readonly Isolation $isolation,
        private readonly Localization $localization,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::item(['settings' => $this->isolation->settings(), 'server' => $this->isolation->info()]);
    }

    public function update(Request $request, Identity $identity): JsonResponse
    {
        $input = (array) $request->input();
        [$ok, $why] = $this->isolation->configure($identity, $request, \array_intersect_key($input, \array_flip(['hidepid', 'links'])));

        if (!$ok) {
            $why = (string) $why;
            $unreachable = $why === 'Security.iso_error_unreachable';

            return new JsonResponse(['error' => 'refused', 'message' => \str_starts_with($why, 'Security.') ? $this->localization->get($why) : $why], $unreachable ? 503 : 502);
        }

        return ApiResponse::item(['settings' => $this->isolation->settings()]);
    }
}
