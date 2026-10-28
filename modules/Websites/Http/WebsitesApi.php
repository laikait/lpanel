<?php

declare(strict_types=1);

namespace App\Modules\Websites\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Modules\Hosting\Accounts\Account;
use App\Modules\Hosting\Accounts\AccountStore;
use App\Modules\Operations\OperationException;
use App\Modules\Shared\Ui\Pages;
use App\Modules\Websites\Sites\WebsiteAccess;
use App\Modules\Websites\Sites\WebsiteActions;
use App\Modules\Websites\Sites\WebsiteStore;

/**
 * Websites, under /api/v1 and /api/token/v1:
 *
 *   GET    /websites[?status=current|active|suspended|busy|failed|deleted]
 *   GET    /websites/{id}
 *   POST   /websites                 {"account": id, "domains": [...], "document_root": "…", "php": true}  202
 *   PUT    /websites/{id}/settings   {"domains", "document_root", "php", "index", "redirects", "headers"}  202
 *   POST   /websites/{id}/suspend    202
 *   POST   /websites/{id}/unsuspend  202
 *   POST   /websites/{id}/retry      202 (create again, after it failed)
 *   POST   /websites/{id}/restore    202 (an administrator replaces a hand edit with LPanel's version)
 *   DELETE /websites/{id}            {"confirm": "<domain>"}  202
 *
 * A customer lists and reads its own, and may PUT the settings of its own
 * (domains and document_root as they are). Clients poll GET /websites/{id}
 * until status no longer ends in -ing.
 */
final class WebsitesApi
{
    private const SETTINGS = ['domains', 'document_root', 'php', 'index', 'redirects', 'headers'];

    public function __construct(
        private readonly WebsiteStore $websites,
        private readonly WebsiteAccess $access,
        private readonly WebsiteActions $actions,
        private readonly WebsiteViews $views,
        private readonly AccountStore $accounts,
        private readonly Pages $pages,
    ) {}

    public function index(Request $request, Identity $identity): JsonResponse
    {
        $group = $request->query('status') ?? 'current';

        if (!\is_string($group) || !isset(WebsiteStore::GROUPS[$group])) {
            throw HttpException::badRequest('status must be one of ' . \implode(', ', \array_keys(WebsiteStore::GROUPS)) . '.');
        }

        return ApiResponse::collection(\array_map($this->views->present(...), $this->websites->page($this->access->ownerScope($identity), $group)));
    }

    public function show(Identity $identity, string $id): JsonResponse
    {
        return ApiResponse::item($this->views->present($this->access->find($identity, $id)));
    }

    public function store(Request $request, Identity $identity): Response
    {
        $body = $this->body($request, ['account', ...self::SETTINGS]);

        if ($body instanceof Response) {
            return $body;
        }

        $account = \is_string($body['account'] ?? null) ? $this->accounts->byUid($body['account']) : null;

        if ($account === null || $account->status === Account::DELETED) {
            return (new OperationException('bad_request', 'The body takes "account" (a hosting account id) and the website\'s settings.', 422))->toResponse();
        }

        try {
            $website = $this->actions->create($identity, $account, $body);
        } catch (OperationException $e) {
            return $e->toResponse();
        }

        return ApiResponse::accepted($this->views->present($website), ['Location' => $this->pages->url('api.v1.websites.show', ['id' => $website->uid])]);
    }

    public function settings(Request $request, Identity $identity, string $id): Response
    {
        $website = $this->access->find($identity, $id, 'update');
        $body = $this->body($request, self::SETTINGS);

        if ($body instanceof Response) {
            return $body;
        }

        try {
            // A field left out keeps its value.
            return ApiResponse::accepted($this->views->present($this->actions->update($identity, $website, $body + $website->settings)));
        } catch (OperationException $e) {
            return $e->toResponse();
        }
    }

    public function restore(Identity $identity, string $id): Response
    {
        $website = $this->access->find($identity, $id, 'restore');

        try {
            return ApiResponse::accepted($this->views->present($this->actions->update($identity, $website, $website->settings, true)));
        } catch (OperationException $e) {
            return $e->toResponse();
        }
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
        $website = $this->access->find($identity, $id, $action);

        try {
            return ApiResponse::accepted($this->views->present($this->actions->act($identity, $website, $action, $confirm)));
        } catch (OperationException $e) {
            return $e->toResponse();
        }
    }

    /**
     * The JSON object body, with only $allowed fields, as arrays.
     *
     * @param list<string> $allowed
     *
     * @return array<string, mixed>|Response
     */
    private function body(Request $request, array $allowed): array|Response
    {
        $raw = $request->body();
        $body = \in_array(\trim($raw), ['', '[]'], true) ? new \stdClass() : \json_decode($raw, false, 8);

        if (!$body instanceof \stdClass) {
            return (new OperationException('bad_request', 'Send a JSON object.', 422))->toResponse();
        }

        $fields = \get_object_vars($body);

        if (\array_diff(\array_map('strval', \array_keys($fields)), $allowed) !== []) {
            return (new OperationException('bad_request', 'The body takes only: ' . \implode(', ', $allowed) . '.', 422))->toResponse();
        }

        // Settings rows arrive as objects; WebsiteSettings reads arrays.
        $fields = \json_decode(\json_encode($fields, \JSON_THROW_ON_ERROR), true, 8, \JSON_THROW_ON_ERROR);

        return \is_array($fields) ? $fields : [];
    }
}
