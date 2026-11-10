<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\AccountRules;
use App\Modules\Accounts\Accounts;
use App\Modules\Accounts\AccountView;
use App\Modules\Accounts\Outcome;
use App\Modules\Accounts\Provisioner;
use App\Modules\Packages\Http\PackageApi;

/**
 * /admin/api/v1/accounts: the same rules and outcomes as the pages.
 *
 *   200  done                 202  recorded, still being carried out
 *   409  another change is in flight, or the account is in the wrong state
 *   422  invalid input        502  the agent tried and failed
 */
final class AccountApi
{
    public function __construct(
        private readonly Accounts $accounts,
        private readonly AccountRules $rules,
        private readonly AccountView $view,
        private readonly Provisioner $provisioner,
        private readonly Localization $localization,
    ) {}

    public function index(Request $request, Identity $identity): JsonResponse
    {
        $rows = $this->accounts->visibleTo($identity, (string) $request->query('q', ''), (string) $request->query('status', ''));

        return ApiResponse::collection(\array_map($this->view->present(...), $rows));
    }

    public function show(Identity $identity, string $username): JsonResponse
    {
        return ApiResponse::item($this->view->present($this->account($identity, $username)));
    }

    public function create(Request $request, Identity $identity): JsonResponse
    {
        $input = $request->input();
        [$clean, $errors] = $this->rules->validateNew($input, $identity);

        if ($errors !== []) {
            return PackageApi::invalid($errors, $this->localization);
        }

        $outcome = $this->provisioner->create($identity, $request, $clean);
        $row = $outcome->accountId === null ? null : $this->accounts->byId($outcome->accountId);

        if ($outcome->status === Outcome::DONE && $row !== null) {
            return ApiResponse::created($this->view->present($row));
        }

        return $this->respond($outcome, $row);
    }

    public function package(Request $request, Identity $identity, string $username): JsonResponse
    {
        $row = $this->account($identity, $username);
        $package = $this->rules->package($identity, $row['owner_id'], $request->input('package'));

        if ($package === null) {
            return PackageApi::invalid(['package' => 'Accounts.error_package'], $this->localization);
        }

        return $this->respond($this->provisioner->changePackage($identity, $request, (int) $row['id'], (int) $package['id']), $row);
    }

    public function suspend(Request $request, Identity $identity, string $username): JsonResponse
    {
        $row = $this->account($identity, $username);

        return $this->respond($this->provisioner->suspend($identity, $request, (int) $row['id'], (string) $request->input('reason', '')), $row);
    }

    public function unsuspend(Request $request, Identity $identity, string $username): JsonResponse
    {
        $row = $this->account($identity, $username);

        return $this->respond($this->provisioner->unsuspend($identity, $request, (int) $row['id']), $row);
    }

    public function terminate(Request $request, Identity $identity, string $username): JsonResponse
    {
        $row = $this->account($identity, $username);

        return $this->respond($this->provisioner->terminate($identity, $request, (int) $row['id']), $row);
    }

    /** @param array<string, mixed>|null $before the row before the change */
    private function respond(Outcome $outcome, ?array $before): JsonResponse
    {
        $now = $outcome->accountId === null ? null : $this->accounts->byId($outcome->accountId);

        return match ($outcome->status) {
            Outcome::DONE => $now === null
                ? new JsonResponse(['data' => ['username' => $before['username'] ?? null, 'status' => 'terminated']], 200)
                : ApiResponse::item($this->view->present($now)),
            Outcome::PENDING => ApiResponse::accepted($now === null ? null : $this->view->present($now)),
            Outcome::BUSY => new JsonResponse(['error' => 'busy', 'message' => $this->localization->get('Accounts.busy')], 409),
            Outcome::REFUSED => new JsonResponse(['error' => 'wrong_status', 'message' => $this->localization->get((string) $outcome->code)], 409),
            default => new JsonResponse([
                'error' => $outcome->code,
                'message' => $this->localization->get(AccountPages::agentMessage($outcome)),
                'detail' => $outcome->message,
            ], 502),
        };
    }

    /** @return array<string, mixed> */
    private function account(Identity $identity, string $username): array
    {
        return $this->accounts->findByUsername($identity, $username) ?? throw HttpException::notFound();
    }
}
