<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Modules\Operations\Catalogue\Catalogue;
use App\Modules\Operations\Catalogue\Definition;
use App\Modules\Operations\OperationException;
use App\Modules\Operations\OperationRequests;
use App\Modules\Operations\Store\Operation;
use App\Modules\Operations\Store\OperationStore;
use App\Modules\Servers\Store\ServerStore;
use App\Modules\Shared\Ui\Pages;

/**
 * Under /api/v1 (session) and /api/token/v1 (bearer token):
 *
 *   GET  /operations?before=&limit=&status=&server=   newest first, keyset paging
 *   GET  /operations/catalogue                        what can be asked for
 *   GET  /operations/{id}                             one, with its events
 *   POST /servers/{id}/operations {op, op_version?, payload?}
 *                                                     202, or 200 when an identical read-only request is still running
 *   POST /operations/{id}/cancel                      202
 *
 * Clients poll GET /operations/{id} until status is final (ARCHITECTURE.md §2).
 */
final class OperationsApi
{
    public function __construct(
        private readonly OperationStore $operations,
        private readonly OperationRequests $requests,
        private readonly ServerStore $servers,
        private readonly Catalogue $catalogue,
        private readonly Pages $pages,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $limit = \filter_var($request->query('limit', 50), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200]]);
        $before = $request->query('before');
        $before = $before === null ? null : \filter_var($before, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $group = $request->query('status');
        $serverId = $request->query('server');

        if ($limit === false || $before === false || ($group !== null && !isset(OperationStore::GROUPS[$group]))) {
            throw HttpException::badRequest('limit must be 1-200, before a positive cursor, and status one of ' . \implode(', ', \array_keys(OperationStore::GROUPS)) . '.');
        }

        $server = null;

        if ($serverId !== null) {
            $server = \is_string($serverId) ? $this->servers->byUid($serverId) : null;

            if ($server === null) {
                throw HttpException::notFound();
            }
        }

        $page = $this->operations->page($limit, $before, $group, $server?->id);
        $cursor = \count($page) === $limit ? $page[\count($page) - 1]->id : null;

        return ApiResponse::collection(\array_map(fn(Operation $op): array => $this->present($op), $page), ['next_before' => $cursor]);
    }

    public function catalogue(): JsonResponse
    {
        return ApiResponse::collection(\array_map(static fn(Definition $d): array => $d->toArray(), $this->catalogue->all()));
    }

    public function show(string $id): JsonResponse
    {
        $op = $this->operations->byUid($id) ?? throw HttpException::notFound();

        return ApiResponse::item($this->present($op) + ['events' => $this->operations->events($op)]);
    }

    public function store(Request $request, Identity $identity, string $id): Response
    {
        $server = $this->servers->byUid($id) ?? throw HttpException::notFound();
        $body = \json_decode($request->body(), false, 32);

        if (!$body instanceof \stdClass) {
            return (new OperationException('bad_request', 'Send a JSON object: {"op": "system.info"}.', 422))->toResponse();
        }

        $fields = \get_object_vars($body);
        $unknown = \array_diff(\array_map('strval', \array_keys($fields)), ['op', 'op_version', 'payload']);
        $op = $fields['op'] ?? null;
        $version = $fields['op_version'] ?? null;

        if ($unknown !== [] || !\is_string($op) || ($version !== null && !\is_int($version))) {
            return (new OperationException('bad_request', 'The body takes "op" (a name), and optionally "op_version" (a number) and "payload" (an object).', 422))->toResponse();
        }

        try {
            [$queued, $new] = $this->requests->request($identity, $server, $op, $version, $fields['payload'] ?? new \stdClass());
        } catch (OperationException $e) {
            return $e->toResponse();
        }

        $location = $this->pages->url('api.v1.operations.show', ['id' => $queued->uid]);

        return $new
            ? ApiResponse::accepted($this->present($queued), ['Location' => $location])
            : ApiResponse::item($this->present($queued), 200, ['Location' => $location]);
    }

    public function cancel(Identity $identity, string $id): Response
    {
        $op = $this->operations->byUid($id) ?? throw HttpException::notFound();

        try {
            $this->requests->cancel($identity, $op);
        } catch (OperationException $e) {
            return $e->toResponse();
        }

        // Read again: it is cancelled, or now carries cancel_requested.
        $fresh = $this->operations->byUid($op->uid);

        return ApiResponse::accepted($this->present($fresh ?? $op));
    }

    /** @return array<string, mixed> */
    private function present(Operation $op): array
    {
        $server = $this->servers->byId($op->serverId);

        return $op->toArray() + ['server' => $server === null ? null : ['id' => $server->uid, 'name' => $server->name]];
    }
}
