<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http;

use App\Engine\Http\ApiResponse;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Localization\Localization;
use App\Modules\Operations\Tasks\Tasks;

/**
 * The agent's tasks through the API (server.services).
 *
 *   GET /admin/api/v1/tasks?state=running|done|failed|interrupted&limit=50
 *
 *   200 listed   503 the agent is not answering
 */
final class TaskApi
{
    public function __construct(
        private readonly Tasks $tasks,
        private readonly Localization $localization,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $list = $this->tasks->list((string) $request->query('state', ''), (int) $request->query('limit', 50));

        if ($list === null) {
            return new JsonResponse(['error' => 'refused', 'message' => $this->localization->get('Operations.error_unreachable')], 503);
        }

        return ApiResponse::item(['tasks' => $list]);
    }
}
