<?php

declare(strict_types=1);

namespace App\Modules\Files\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Files\Ssh\SshActions;
use App\Modules\Files\Ssh\SshKeys;
use App\Modules\Files\Ssh\SshOutcome;
use App\Modules\Files\Ssh\SshSync;
use App\Modules\Packages\Http\PackageApi;

/**
 * An account's SSH keys through the API, with the same rules as the User
 * panel (accounts.manage to read, accounts.modify to change).
 *
 *   GET    /admin/api/v1/accounts/{username}/ssh-keys           access and keys
 *   POST   /admin/api/v1/accounts/{username}/ssh-keys           {key, name?} adds a public key;
 *                                                               {generate: true, name} makes a pair and
 *                                                               returns private_key, once
 *   DELETE /admin/api/v1/accounts/{username}/ssh-keys/{key}     by name, or by fingerprint (SHA256:…,
 *                                                               URL-safe: - and _ for + and /)
 *
 *   200  done   201  added   202  recorded, still being carried out   204  removed
 *   404  no such key   409  not now   422  invalid input   502  the server refused it
 */
final class SshApi
{
    public function __construct(
        private readonly SshKeys $keys,
        private readonly SshActions $actions,
        private readonly SshSync $sync,
        private readonly Accounts $accounts,
        private readonly Localization $localization,
    ) {}

    public function index(Identity $identity, string $username): JsonResponse
    {
        return ApiResponse::item($this->present($this->account($identity, $username)));
    }

    public function store(Request $request, Identity $identity, string $username): Response
    {
        $account = $this->account($identity, $username);
        $name = (string) $request->input('name', '');

        if ($request->input('generate') === true || $request->input('generate') === '1') {
            [$outcome, $private] = $this->actions->generate($identity, $request, $account, $name);
            $response = $this->respond($outcome, $account, 201, $private === null ? [] : ['private_key' => $private]);

            return $response->withHeader('Cache-Control', 'no-store');
        }

        return $this->respond($this->actions->add($identity, $request, $account, $name, (string) $request->input('key', '')), $account, 201);
    }

    public function destroy(Request $request, Identity $identity, string $username, string $key): Response
    {
        $account = $this->account($identity, $username);
        // A fingerprint may come URL-safe (- and _ for + and /: a path segment cannot hold a /).
        $key = \rawurldecode($key);
        $key = \str_starts_with($key, 'SHA256:') ? \strtr($key, '-_', '+/') : $key;
        $row = $this->keys->key((int) $account['id'], $key) ?? throw HttpException::notFound();
        $outcome = $this->actions->delete($identity, $request, $account, $row);

        return $outcome->status === SshOutcome::DONE ? ApiResponse::noContent() : $this->respond($outcome, $account);
    }

    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>
     */
    private function present(array $account): array
    {
        $id = (int) $account['id'];
        $state = $this->sync->stateOf($id);

        return [
            'access' => $this->actions->access($account),
            'keys' => \array_map(static fn(array $k): array => [
                'name' => (string) $k['name'],
                'type' => (string) $k['type'],
                'bits' => (int) $k['bits'],
                'fingerprint' => (string) $k['fingerprint'],
                'public_key' => SshKeys::line($k),
                'created_at' => $k['created_at'],
            ], $this->keys->keys($id)),
            'state' => $state === null ? null : ['state' => $state['state'], 'error' => $state['error'], 'applied_at' => $state['applied_at']],
        ];
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $extra
     */
    private function respond(SshOutcome $outcome, array $account, int $done = 200, array $extra = []): Response
    {
        return match ($outcome->status) {
            SshOutcome::REFUSED => \in_array($outcome->message, ['Files.ssh_error_limit', 'Files.ssh_error_no_access'], true)
                ? new JsonResponse(['error' => 'conflict', 'message' => $this->localization->get((string) $outcome->message)], 409)
                : PackageApi::invalid([($outcome->code ?: 'input') => (string) $outcome->message], $this->localization),
            SshOutcome::FAILED => new JsonResponse(['error' => $outcome->code, 'message' => $this->localization->get('Files.ssh_refused'), 'detail' => $outcome->message], 502),
            SshOutcome::PENDING => ApiResponse::accepted($extra + $this->present($account)),
            default => ApiResponse::item($extra + $this->present($account), $done),
        };
    }

    /** @return array<string, mixed> */
    private function account(Identity $identity, string $username): array
    {
        return $this->accounts->findByUsername($identity, $username) ?? throw HttpException::notFound();
    }
}
