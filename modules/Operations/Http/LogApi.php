<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http;

use App\Engine\Http\ApiResponse;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Localization\Localization;
use App\Modules\Operations\Logs\LogViewer;

/**
 * Logs and statistics through the API (server.logs).
 *
 *   GET /admin/api/v1/logs/sources?account=   the logs that can be read: the system's, or an account's
 *   GET /admin/api/v1/logs?source=&account=&lines=&filter=   the latest lines (lines 100, 200, 500 or 1000)
 *   GET /admin/api/v1/statistics?account=&days=&refresh=1    GoAccess's statistics of the access logs (days 7, 30 or 90)
 *
 *   422 refused arguments   502 the server could not do it   503 the agent is not answering
 */
final class LogApi
{
    public function __construct(
        private readonly LogViewer $logs,
        private readonly Localization $localization,
    ) {}

    public function sources(Request $request): JsonResponse
    {
        [$sources, $why] = $this->logs->sources($this->account($request));

        return $sources === null ? $this->failure((string) $why) : ApiResponse::item(['sources' => $sources]);
    }

    public function read(Request $request): JsonResponse
    {
        $source = (string) $request->query('source', '');

        if ($source === '') {
            return $this->failure('Operations.logs_error_source');
        }

        [$log, $why] = $this->logs->read($this->account($request), $source, (int) $request->query('lines', 200), (string) $request->query('filter', ''));

        return $log === null ? $this->failure((string) $why) : ApiResponse::item(['source' => $source] + $log);
    }

    public function statistics(Request $request): JsonResponse
    {
        [$report, $why] = $this->logs->report($this->account($request), (int) $request->query('days', 30), (string) $request->query('refresh', '') === '1');

        return $report === null ? $this->failure((string) $why) : ApiResponse::item($report);
    }

    private function account(Request $request): ?string
    {
        $a = (string) $request->query('account', '');

        return $a === '' ? null : $a;
    }

    private function failure(string $why): JsonResponse
    {
        $status = match ($why) {
            'Operations.logs_error_unreachable' => 503,
            'Operations.logs_error_source', 'Operations.logs_error_args' => 422,
            default => 502,
        };
        $text = \str_starts_with($why, 'Operations.') ? $this->localization->get($why) : $why;

        return new JsonResponse(['error' => match ($status) {
            503 => 'agent_unavailable',
            422 => 'validation_failed',
            default => 'server_refused',
        }, 'message' => $text], $status);
    }
}
