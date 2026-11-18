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
use App\Modules\Files\Ftp\FtpActions;
use App\Modules\Files\Ftp\FtpOutcome;
use App\Modules\Files\Ftp\FtpSettings;
use App\Modules\Files\Ftp\FtpSync;
use App\Modules\Files\Ftp\FtpUsers;
use App\Modules\Packages\Http\PackageApi;

/**
 * FTP through the API, with the same rules as the panels.
 *
 *   /admin/api/v1/accounts/{username}/ftp…   an account's logins (accounts.manage / accounts.modify)
 *   /admin/api/v1/ftp…                       Pure-FTPd itself: state, settings, sessions (server.ftp)
 *
 * Passwords are sent in the request body and never returned.
 *
 *   200  done      201  made      202  recorded, still being carried out
 *   204  removed   404  no such login or session   409  not now
 *   422  invalid input   502  the server refused it   503  the agent is not answering
 */
final class FtpApi
{
    public function __construct(
        private readonly FtpUsers $users,
        private readonly FtpActions $actions,
        private readonly FtpSync $sync,
        private readonly FtpSettings $settings,
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
        $login = (string) $request->input('login', '');
        [$name, $domain] = \str_contains($login, '@') ? \explode('@', $login, 2) : [(string) $request->input('name', ''), (string) $request->input('domain', '')];
        $folder = $request->input('folder');
        $outcome = $this->actions->create($identity, $request, $account, $name, $domain, (string) $request->input('password', ''), \is_string($folder) ? $folder : 'public_html');

        return $this->respond($outcome, $account, 201);
    }

    public function update(Request $request, Identity $identity, string $username, string $login): Response
    {
        $account = $this->account($identity, $username);
        $user = $this->user($account, $login);
        $outcome = FtpOutcome::done();

        if ($request->input('folder') !== null) {
            $outcome = $this->actions->setFolder($identity, $request, $account, $user, (string) $request->input('folder'));
        }

        if ($outcome->ok() && $request->input('password') !== null) {
            $outcome = $this->actions->setPassword($identity, $request, $account, $this->user($account, $login), (string) $request->input('password'));
        }

        return $this->respond($outcome, $account);
    }

    public function destroy(Request $request, Identity $identity, string $username, string $login): Response
    {
        $account = $this->account($identity, $username);
        $outcome = $this->actions->delete($identity, $request, $account, $this->user($account, $login));

        return $outcome->status === FtpOutcome::DONE ? ApiResponse::noContent() : $this->respond($outcome, $account);
    }

    public function sessions(Identity $identity, string $username): JsonResponse
    {
        $info = $this->actions->info($this->account($identity, $username));

        return $info === null ? $this->unavailable() : ApiResponse::item(['sessions' => $info['sessions']]);
    }

    public function kill(Request $request, Identity $identity, string $username, string $pid): Response
    {
        return $this->killed($this->actions->kill($identity, $request, $this->account($identity, $username), (int) $pid));
    }

    // -------------------------------------------------------------------------
    // Pure-FTPd itself

    public function server(): JsonResponse
    {
        $info = $this->actions->info();

        if ($info === null) {
            return $this->unavailable();
        }

        return ApiResponse::item([
            'server' => \array_diff_key($info, ['sessions' => 0, 'settings' => 0]),
            'settings' => $this->settings->get(),
            'sessions' => $info['sessions'],
            'logins' => \array_map(static fn(array $u): array => [
                'login' => (string) $u['login'], 'account' => (string) $u['username'], 'folder' => '/' . $u['folder'], 'created_at' => $u['created_at'],
            ], $this->users->all()),
        ]);
    }

    public function saveSettings(Request $request, Identity $identity): JsonResponse
    {
        $input = [];

        foreach (\array_keys(FtpSettings::DEFAULTS) as $name) {
            $input[$name] = $request->input($name);
        }

        [$ok, $errors, $why] = $this->settings->save($identity, $request, $input);

        if ($errors !== []) {
            return PackageApi::invalid($errors, $this->localization);
        }

        if (!$ok) {
            $message = \str_starts_with((string) $why, 'Files.') ? $this->localization->get((string) $why) : (string) $why;

            return new JsonResponse(['error' => 'refused', 'message' => $message], $why === 'Files.ftp_error_unreachable' ? 503 : 502);
        }

        return ApiResponse::item(['settings' => $this->settings->get()]);
    }

    public function serverKill(Request $request, Identity $identity, string $pid): Response
    {
        return $this->killed($this->actions->kill($identity, $request, null, (int) $pid));
    }

    // -------------------------------------------------------------------------

    private function killed(FtpOutcome $outcome): Response
    {
        return match ($outcome->status) {
            FtpOutcome::DONE => ApiResponse::item(['ended' => true, 'login' => (string) ($outcome->result['login'] ?? '')]),
            FtpOutcome::REFUSED => $outcome->message === 'Files.ftp_error_unreachable'
                ? $this->unavailable()
                : new JsonResponse(['error' => 'not_found', 'message' => $this->localization->get('Files.ftp_no_session')], 404),
            default => new JsonResponse(['error' => $outcome->code, 'message' => $this->localization->get('Files.ftp_refused'), 'detail' => $outcome->message], 502),
        };
    }

    private function unavailable(): JsonResponse
    {
        return new JsonResponse(['error' => 'unavailable', 'message' => $this->localization->get('Files.ftp_error_unreachable')], 503);
    }

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
            'logins' => \array_map(static fn(array $u): array => [
                'login' => (string) $u['login'], 'folder' => '/' . $u['folder'], 'created_at' => $u['created_at'], 'updated_at' => $u['updated_at'],
            ], $this->users->users($id)),
            'domains' => $this->actions->domainsOf($account),
            'state' => $state === null ? null : ['state' => $state['state'], 'error' => $state['error'], 'applied_at' => $state['applied_at']],
        ];
    }

    /** @param array<string, mixed> $account */
    private function respond(FtpOutcome $outcome, array $account, int $done = 200): Response
    {
        return match ($outcome->status) {
            FtpOutcome::REFUSED => \in_array($outcome->message, ['Files.ftp_error_limit', 'Files.ftp_error_unreachable'], true)
                ? new JsonResponse(['error' => 'conflict', 'message' => $this->localization->get((string) $outcome->message)], 409)
                : PackageApi::invalid([($outcome->code ?: 'input') => (string) $outcome->message], $this->localization),
            FtpOutcome::FAILED => new JsonResponse(['error' => $outcome->code, 'message' => $this->localization->get('Files.ftp_refused'), 'detail' => $outcome->message], 502),
            FtpOutcome::PENDING => ApiResponse::accepted($this->present($account)),
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
    private function user(array $account, string $login): array
    {
        return $this->users->user((int) $account['id'], \strtolower(\rawurldecode($login))) ?? throw HttpException::notFound();
    }
}
