<?php

declare(strict_types=1);

namespace App\Modules\Databases\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Audit\AuditLog;
use App\Modules\Databases\Databases;
use App\Modules\Databases\DbActions;
use App\Modules\Databases\DbOutcome;
use App\Modules\Databases\DbSync;
use App\Modules\Packages\Http\PackageApi;

/**
 * Databases through the API, with the same rules as the panels.
 *
 *   /admin/api/v1/accounts/{username}/databases…   an account's (accounts.manage / accounts.modify)
 *   /admin/api/v1/databases…                       MariaDB itself (server.databases)
 *
 * Passwords are sent in the request body and never returned.
 *
 *   200  done      201  made      202  recorded, still being carried out
 *   204  removed   409  not now   422  invalid input   502  the server refused it
 */
final class DatabaseApi
{
    public function __construct(
        private readonly Databases $databases,
        private readonly DbActions $actions,
        private readonly DbSync $sync,
        private readonly Accounts $accounts,
        private readonly AgentClient $agent,
        private readonly AuditLog $audit,
        private readonly Localization $localization,
    ) {}

    public function index(Identity $identity, string $username): JsonResponse
    {
        $account = $this->account($identity, $username);

        return ApiResponse::item($this->present($account));
    }

    public function store(Request $request, Identity $identity, string $username): Response
    {
        $account = $this->account($identity, $username);
        $outcome = $this->actions->createDatabase($identity, $request, $account, (string) $request->input('name', ''));

        return $this->respond($outcome, $account, 201);
    }

    public function rename(Request $request, Identity $identity, string $username, string $database): Response
    {
        $account = $this->account($identity, $username);
        $outcome = $this->actions->renameDatabase($identity, $request, $account, $this->database($account, $database), (string) $request->input('name', ''));

        return $this->respond($outcome, $account);
    }

    public function destroy(Request $request, Identity $identity, string $username, string $database): Response
    {
        $account = $this->account($identity, $username);
        $outcome = $this->actions->dropDatabase($identity, $request, $account, $this->database($account, $database));

        return $outcome->status === DbOutcome::DONE ? ApiResponse::noContent() : $this->respond($outcome, $account);
    }

    public function storeUser(Request $request, Identity $identity, string $username): Response
    {
        $account = $this->account($identity, $username);
        $grants = $request->input('grants', []);
        $outcome = $this->actions->createUser(
            $identity,
            $request,
            $account,
            (string) $request->input('name', ''),
            (string) $request->input('password', ''),
            \is_array($grants) ? $grants : [],
        );

        return $this->respond($outcome, $account, 201);
    }

    public function password(Request $request, Identity $identity, string $username, string $user): Response
    {
        $account = $this->account($identity, $username);
        $outcome = $this->actions->setPassword($identity, $request, $account, $this->user($account, $user), (string) $request->input('password', ''));

        return $this->respond($outcome, $account);
    }

    public function destroyUser(Request $request, Identity $identity, string $username, string $user): Response
    {
        $account = $this->account($identity, $username);
        $outcome = $this->actions->dropUser($identity, $request, $account, $this->user($account, $user));

        return $outcome->status === DbOutcome::DONE ? ApiResponse::noContent() : $this->respond($outcome, $account);
    }

    public function grant(Request $request, Identity $identity, string $username, string $user, string $database): Response
    {
        $account = $this->account($identity, $username);
        $outcome = $this->actions->setGrant($identity, $request, $account, $this->user($account, $user), $this->database($account, $database), $request->input('privileges', []));

        return $this->respond($outcome, $account);
    }

    public function revoke(Request $request, Identity $identity, string $username, string $user, string $database): Response
    {
        $account = $this->account($identity, $username);
        $outcome = $this->actions->setGrant($identity, $request, $account, $this->user($account, $user), $this->database($account, $database), null);

        return $this->respond($outcome, $account);
    }

    public function addHost(Request $request, Identity $identity, string $username): Response
    {
        $account = $this->account($identity, $username);
        $outcome = $this->actions->addHost($identity, $request, $account, (string) $request->input('host', ''), (string) $request->input('note', ''));

        return $this->respond($outcome, $account, 201);
    }

    public function removeHost(Request $request, Identity $identity, string $username, string $id): Response
    {
        $account = $this->account($identity, $username);
        $host = $this->databases->host((int) $account['id'], (int) $id) ?? throw HttpException::notFound();
        $outcome = $this->actions->removeHost($identity, $request, $account, $host);

        return $outcome->status === DbOutcome::DONE ? ApiResponse::noContent() : $this->respond($outcome, $account);
    }

    // -------------------------------------------------------------------------
    // MariaDB itself

    public function server(): JsonResponse
    {
        try {
            $info = $this->agent->call('db.info', [], timeout: 10.0);
            $processes = $this->agent->call('db.processes', [], timeout: 10.0);
        } catch (AgentUnavailable) {
            return new JsonResponse(['error' => 'unavailable', 'message' => $this->localization->get('Databases.error_unreachable')], 503);
        }

        return ApiResponse::item([
            'server' => $info->done() ? $info->result : null,
            'processes' => $processes->done() ? ($processes->result['processes'] ?? []) : [],
            'databases' => \array_map(static fn(array $d): array => [
                'name' => (string) $d['name'], 'account' => (string) $d['username'], 'size_bytes' => (int) $d['size_bytes'],
            ], $this->databases->all()),
        ]);
    }

    public function kill(Request $request, Identity $identity, string $id): Response
    {
        try {
            $reply = $this->agent->call('db.kill', ['id' => (int) $id], id: Protocol::newId(), timeout: 10.0);
        } catch (AgentUnavailable) {
            return new JsonResponse(['error' => 'unavailable', 'message' => $this->localization->get('Databases.error_unreachable')], 503);
        }

        $this->audit->record('database.kill', $identity, $request, $id, $reply->done() ? AuditLog::OK : AuditLog::FAILED, $reply->done() ? [] : ['reason' => $reply->errorCode]);

        if (!$reply->done()) {
            return new JsonResponse(['error' => $reply->errorCode, 'message' => $reply->errorMessage], $reply->errorCode === 'db_not_account' ? 403 : 502);
        }

        return ApiResponse::item(['killed' => (bool) ($reply->result['killed'] ?? false)]);
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
            'prefix' => Databases::prefix($account),
            'databases' => \array_map(static fn(array $d): array => ['name' => (string) $d['name'], 'size_bytes' => (int) $d['size_bytes'], 'created_at' => $d['created_at']], $this->databases->databases($id)),
            'users' => \array_map(static fn(array $u): array => ['name' => (string) $u['name'], 'grants' => (object) $u['grants'], 'created_at' => $u['created_at']], $this->databases->users($id)),
            'hosts' => \array_map(static fn(array $h): array => ['id' => (int) $h['id'], 'host' => (string) $h['host'], 'note' => $h['note']], $this->databases->hosts($id)),
            'state' => $state === null ? null : ['state' => $state['state'], 'error' => $state['error'], 'applied_at' => $state['applied_at']],
        ];
    }

    /** @param array<string, mixed> $account */
    private function respond(DbOutcome $outcome, array $account, int $done = 200): Response
    {
        return match ($outcome->status) {
            DbOutcome::REFUSED => \in_array($outcome->message, ['Databases.error_pending', 'Databases.error_unreachable', 'Databases.error_limit'], true)
                ? new JsonResponse(['error' => 'conflict', 'message' => $this->localization->get((string) $outcome->message)], 409)
                : PackageApi::invalid([($outcome->code ?: 'input') => (string) $outcome->message], $this->localization),
            DbOutcome::FAILED => new JsonResponse(['error' => $outcome->code, 'message' => $this->localization->get('Databases.refused'), 'detail' => $outcome->message], 502),
            DbOutcome::PENDING => ApiResponse::accepted($this->present($account)),
            default => ApiResponse::item($this->present($account), $done),
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
    private function database(array $account, string $name): array
    {
        return $this->databases->database((int) $account['id'], \strtolower($name)) ?? throw HttpException::notFound();
    }

    /**
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>
     */
    private function user(array $account, string $name): array
    {
        return $this->databases->user((int) $account['id'], \strtolower($name)) ?? throw HttpException::notFound();
    }
}
