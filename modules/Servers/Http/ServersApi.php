<?php

declare(strict_types=1);

namespace App\Modules\Servers\Http;

use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Modules\Servers\Agent\AgentSettings;
use App\Modules\Servers\Store\Server;
use App\Modules\Servers\Store\ServerStore;

/** GET /servers and /servers/{id} (servers.read), under /api/v1 and /api/token/v1. */
final class ServersApi
{
    public function __construct(private readonly ServerStore $servers, private readonly AgentSettings $settings) {}

    public function index(): JsonResponse
    {
        $offline = $this->settings->offlineAfter();

        return ApiResponse::collection(\array_map(static fn(Server $s): array => $s->toArray($offline), $this->servers->all()));
    }

    public function show(string $id): JsonResponse
    {
        $server = $this->servers->byUid($id) ?? throw HttpException::notFound();

        return ApiResponse::item($server->toArray($this->settings->offlineAfter()));
    }
}
