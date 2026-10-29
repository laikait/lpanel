<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\System\Plugins\Server\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\System\Security\SystemAuthorizationException;
use App\Engine\System\Service\ServiceException;
use App\Engine\System\Service\ServiceNotFoundException;
use App\Tests\Fixtures\Modules\System\Plugins\Server\Services\ServerService;

/**
 * The HTTP face of ServerService: JSON, read-only.
 *
 * Its whole job is translation. System's refusals are not HTTP errors -- the
 * same service answers the console and MCP -- so this is where "not allowed"
 * becomes 401 or 403 and "no such unit" becomes 404.
 */
final class ServerEndpoints
{
    public function __construct(private readonly ServerService $server) {}

    public function info(Identity $identity): JsonResponse
    {
        return $this->respond(fn(): JsonResponse => ApiResponse::item($this->server->info($identity)));
    }

    public function service(Identity $identity, string $name): JsonResponse
    {
        return $this->respond(fn(): JsonResponse => ApiResponse::item($this->server->serviceStatus($identity, $name)));
    }

    public function cron(Identity $identity): JsonResponse
    {
        return $this->respond(fn(): JsonResponse => ApiResponse::collection($this->server->cronJobs($identity)));
    }

    /** @param \Closure(): JsonResponse $answer */
    private function respond(\Closure $answer): JsonResponse
    {
        try {
            return $answer();
        } catch (SystemAuthorizationException $e) {
            throw $e->guest ? HttpException::unauthorized() : HttpException::forbidden($e->getMessage());
        } catch (ServiceNotFoundException) {
            throw HttpException::notFound();
        } catch (ServiceException $e) {
            throw HttpException::badRequest($e->getMessage());
        }
    }
}
