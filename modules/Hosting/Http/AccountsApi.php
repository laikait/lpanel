<?php

declare(strict_types=1);

namespace App\Modules\Hosting\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Modules\Hosting\Accounts\AccountAccess;
use App\Modules\Hosting\Accounts\AccountActions;
use App\Modules\Hosting\Accounts\AccountStore;
use App\Modules\Identity\Users\UserStore;
use App\Modules\Operations\OperationException;
use App\Modules\Servers\Store\ServerStore;
use App\Modules\Shared\Ui\Pages;

/**
 * Hosting accounts, under /api/v1 and /api/token/v1:
 *
 *   GET    /accounts[?status=current|active|suspended|busy|failed|deleted]
 *   GET    /accounts/{id}
 *   POST   /accounts                    {"server": id, "owner": user id, "label": "…"}  202
 *   POST   /accounts/{id}/suspend       202
 *   POST   /accounts/{id}/unsuspend     202
 *   POST   /accounts/{id}/retry         202 (create again, after it failed)
 *   DELETE /accounts/{id}               {"confirm": "<system_user>"}  202
 *
 * A customer lists and reads its own; everything else is accounts.manage.
 * Clients poll GET /accounts/{id} until status no longer ends in -ing.
 */
final class AccountsApi
{
    public function __construct(
        private readonly AccountStore $accounts,
        private readonly AccountAccess $access,
        private readonly AccountActions $actions,
        private readonly AccountViews $views,
        private readonly ServerStore $servers,
        private readonly UserStore $users,
        private readonly Pages $pages,
    ) {}

    public function index(Request $request, Identity $identity): JsonResponse
    {
        $group = $request->query('status') ?? 'current';

        if (!\is_string($group) || !isset(AccountStore::GROUPS[$group])) {
            throw HttpException::badRequest('status must be one of ' . \implode(', ', \array_keys(AccountStore::GROUPS)) . '.');
        }

        return ApiResponse::collection(\array_map($this->views->present(...), $this->accounts->page($this->access->ownerScope($identity), $group)));
    }

    public function show(Identity $identity, string $id): JsonResponse
    {
        return ApiResponse::item($this->views->present($this->access->find($identity, $id)));
    }

    public function store(Request $request, Identity $identity): Response
    {
        $body = $this->body($request, ['server', 'owner', 'label']);

        if ($body instanceof Response) {
            return $body;
        }

        $server = \is_string($body['server'] ?? null) ? $this->servers->byUid($body['server']) : null;
        $owner = \is_string($body['owner'] ?? null) ? $this->users->byUid($body['owner']) : null;

        if ($server === null || $owner === null || !\is_string($body['label'] ?? null)) {
            return (new OperationException('bad_request', 'The body takes "server" and "owner" (ids) and "label" (a name).', 422))->toResponse();
        }

        try {
            $account = $this->actions->create($identity, $server, $owner, $body['label']);
        } catch (OperationException $e) {
            return $e->toResponse();
        }

        return ApiResponse::accepted($this->views->present($account), ['Location' => $this->pages->url('api.v1.accounts.show', ['id' => $account->uid])]);
    }

    public function suspend(Identity $identity, string $id): Response
    {
        return $this->act($identity, $id, 'suspend', null);
    }

    public function unsuspend(Identity $identity, string $id): Response
    {
        return $this->act($identity, $id, 'unsuspend', null);
    }

    public function retry(Identity $identity, string $id): Response
    {
        return $this->act($identity, $id, 'create', null);
    }

    public function destroy(Request $request, Identity $identity, string $id): Response
    {
        $body = $this->body($request, ['confirm']);

        if ($body instanceof Response) {
            return $body;
        }

        return $this->act($identity, $id, 'delete', \is_string($body['confirm'] ?? null) ? $body['confirm'] : null);
    }

    private function act(Identity $identity, string $id, string $action, ?string $confirm): Response
    {
        $account = $this->access->find($identity, $id, $action);

        try {
            return ApiResponse::accepted($this->views->present($this->actions->act($identity, $account, $action, $confirm)));
        } catch (OperationException $e) {
            return $e->toResponse();
        }
    }

    /**
     * The JSON object body, with only $allowed fields.
     *
     * @param list<string> $allowed
     *
     * @return array<string, mixed>|Response
     */
    private function body(Request $request, array $allowed): array|Response
    {
        $raw = $request->body();
        // No body, or an empty list from a client that cannot send {}, is no fields.
        $body = \in_array(\trim($raw), ['', '[]'], true) ? new \stdClass() : \json_decode($raw, false, 8);

        if (!$body instanceof \stdClass) {
            return (new OperationException('bad_request', 'Send a JSON object.', 422))->toResponse();
        }

        $fields = \get_object_vars($body);

        if (\array_diff(\array_map('strval', \array_keys($fields)), $allowed) !== []) {
            return (new OperationException('bad_request', 'The body takes only: ' . \implode(', ', $allowed) . '.', 422))->toResponse();
        }

        return $fields;
    }
}
