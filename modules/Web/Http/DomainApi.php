<?php

declare(strict_types=1);

namespace App\Modules\Web\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Packages\Http\PackageApi;
use App\Modules\Web\DomainActions;
use App\Modules\Web\Domains;
use App\Modules\Web\WebOutcome;
use App\Modules\Web\WebSync;

/**
 * /admin/api/v1/accounts/{username}/domains: an account's domains, with the
 * same rules as the User panel.
 *
 *   200  done      201  added      202  recorded, still being carried out
 *   409  cannot be removed (main domain, or others depend on it)
 *   422  invalid input             502  the server refused it; nothing changed
 */
final class DomainApi
{
    public function __construct(
        private readonly Accounts $accounts,
        private readonly Domains $domains,
        private readonly DomainActions $actions,
        private readonly Localization $localization,
    ) {}

    public function index(Identity $identity, string $username): JsonResponse
    {
        $account = $this->account($identity, $username);

        return ApiResponse::collection(\array_map(self::present(...), $this->domains->forAccount((int) $account['id'])));
    }

    public function store(Request $request, Identity $identity, string $username): JsonResponse
    {
        $account = $this->account($identity, $username);
        $target = (string) $request->input('target', '');

        if ($target !== '' && !\ctype_digit($target)) {
            $target = (string) ($this->domains->findByName((int) $account['id'], $target)['id'] ?? '');
        }

        [$outcome, $errors, $clean] = $this->actions->add($identity, $request, $account, [
            'kind' => (string) $request->input('kind', Domains::ADDON),
            'name' => (string) $request->input('name', ''),
            'target' => $target,
            'docroot' => (string) $request->input('docroot', ''),
            'php' => (string) $request->input('php', ''),
            'wildcard' => $request->input('wildcard') === true,
        ]);

        if ($outcome === null) {
            return PackageApi::invalid($errors, $this->localization);
        }

        $row = $this->domains->findByName((int) $account['id'], (string) $clean['name']);

        return $outcome->status === WebOutcome::DONE && $row !== null
            ? ApiResponse::created(self::present($row))
            : $this->respond($outcome, $row);
    }

    public function update(Request $request, Identity $identity, string $username, string $domain): JsonResponse
    {
        $account = $this->account($identity, $username);
        $row = $this->domain($account, $domain);
        $input = [];

        foreach (['docroot', 'php', 'wildcard', 'canonical'] as $field) {
            if ($request->input($field) !== null) {
                $input[$field] = $field === 'wildcard' ? $request->input($field) === true : (string) $request->input($field);
            }
        }

        [$outcome, $errors] = $this->actions->change($identity, $request, $account, $row, $input);

        if ($outcome === null) {
            return PackageApi::invalid($errors, $this->localization);
        }

        return $this->respond($outcome, $this->domains->find((int) $account['id'], (int) $row['id']));
    }

    public function destroy(Request $request, Identity $identity, string $username, string $domain): JsonResponse
    {
        $account = $this->account($identity, $username);
        $row = $this->domain($account, $domain);
        [$outcome, $blocker] = $this->actions->remove($identity, $request, $account, $row);

        if ($outcome === null) {
            return new JsonResponse(['error' => 'cannot_remove', 'message' => $this->localization->get((string) $blocker)], 409);
        }

        return $outcome->status === WebOutcome::DONE
            ? new JsonResponse(['data' => ['name' => $row['name'], 'status' => 'removed']], 200)
            : $this->respond($outcome, null);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        return [
            'name' => $row['name'],
            'kind' => $row['kind'],
            'parent' => $row['parent_id'],
            'docroot' => $row['docroot'],
            'php' => $row['php'] === null ? null : WebSync::phpLabel((string) $row['php']),
            'wildcard' => (bool) $row['wildcard'],
            'created_at' => $row['created_at'],
        ];
    }

    /** @param array<string, mixed>|null $row */
    private function respond(WebOutcome $outcome, ?array $row): JsonResponse
    {
        return match ($outcome->status) {
            WebOutcome::PENDING => ApiResponse::accepted($row === null ? null : self::present($row)),
            WebOutcome::FAILED => new JsonResponse([
                'error' => $outcome->code,
                'message' => $this->localization->get('Web.refused'),
                'detail' => $outcome->message,
            ], 502),
            default => ApiResponse::item($row === null ? null : self::present($row)),
        };
    }

    /** @return array<string, mixed> */
    private function account(Identity $identity, string $username): array
    {
        return $this->accounts->findByUsername($identity, $username) ?? throw HttpException::notFound();
    }

    /**
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>
     */
    private function domain(array $account, string $name): array
    {
        return $this->domains->findByName((int) $account['id'], $name) ?? throw HttpException::notFound();
    }
}
