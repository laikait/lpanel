<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http;

use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Modules\Audit\AuditLog;

/**
 * GET /api/v1/audit?before=<id>&limit=<n>&action=<name or prefix.>&outcome=<o>
 * -- newest first, keyset paging. The same under /api/token/v1.
 */
final class AuditApi
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(Request $request): JsonResponse
    {
        $limit = \filter_var($request->query('limit', 50), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200]]);
        $before = $request->query('before');
        $before = $before === null ? null : \filter_var($before, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($limit === false || $before === false) {
            throw HttpException::badRequest('limit must be 1-200, and before a positive id.');
        }

        $action = $request->query('action');
        $outcome = $request->query('outcome');

        if (($action !== null && (!\is_string($action) || \preg_match(AuditLog::ACTION, $action) !== 1))
            || ($outcome !== null && !\in_array($outcome, AuditLog::OUTCOMES, true))) {
            throw HttpException::badRequest('action must be an action or a prefix ending in ".", and outcome one of ' . \implode(', ', AuditLog::OUTCOMES) . '.');
        }

        $events = $this->audit->latest($limit, $before, $action, $outcome);
        $last = $events === [] ? null : $events[\count($events) - 1]['id'];

        return ApiResponse::collection($events, ['next_before' => \count($events) === $limit ? $last : null]);
    }
}
