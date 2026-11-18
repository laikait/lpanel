<?php

declare(strict_types=1);

namespace App\Modules\Files\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Session\Session;
use App\Modules\Files\Ftp\FtpActions;
use App\Modules\Files\Ftp\FtpOutcome;
use App\Modules\Files\Ftp\FtpSettings;
use App\Modules\Files\Ftp\FtpSync;
use App\Modules\Files\Ftp\FtpUsers;
use App\Modules\Packages\Packages;
use App\Modules\Panel\Ui\PanelPage;
use App\Modules\Subusers\Subusers;
use App\Modules\Web\AutoSsl;

/**
 * "FTP" in both panels.
 *
 * User panel: the account's FTP logins (each shut in a folder of the
 * home), how to connect, and the sessions open now.
 *
 * Admin panel: Pure-FTPd's state and settings (TLS, passive ports,
 * clients), every session on the server, and every account's logins.
 */
final class FtpPages
{
    public function __construct(
        private readonly FtpUsers $users,
        private readonly FtpActions $actions,
        private readonly FtpSync $sync,
        private readonly FtpSettings $settings,
        private readonly Packages $packages,
        private readonly Subusers $subusers,
        private readonly AutoSsl $ssl,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    // -------------------------------------------------------------------------
    // User panel

    public function index(Request $request, Session $session, Identity $identity): Response
    {
        return $this->user($request, $session, $identity);
    }

    public function store(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->account($identity);
        $old = [
            'name' => (string) $request->input('name', ''),
            'domain' => (string) $request->input('domain', ''),
            'folder' => (string) $request->input('folder', ''),
        ];
        $outcome = $this->actions->create($identity, $request, $account, $old['name'], $old['domain'], (string) $request->input('password', ''), $old['folder']);

        return $this->after($request, $session, $identity, $outcome, 'Files.ftp_created', $old, 'new');
    }

    public function password(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $account = $this->account($identity);
        $outcome = $this->actions->setPassword($identity, $request, $account, $this->find($account, $id), (string) $request->input('password', ''));

        return $this->after($request, $session, $identity, $outcome, 'Files.ftp_password_changed');
    }

    public function folder(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $account = $this->account($identity);
        $outcome = $this->actions->setFolder($identity, $request, $account, $this->find($account, $id), (string) $request->input('folder', ''));

        return $this->after($request, $session, $identity, $outcome, 'Files.ftp_folder_changed');
    }

    public function delete(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $account = $this->account($identity);
        $outcome = $this->actions->delete($identity, $request, $account, $this->find($account, $id));

        return $this->after($request, $session, $identity, $outcome, 'Files.ftp_deleted');
    }

    public function kill(Request $request, Session $session, Identity $identity, string $pid): Response
    {
        $outcome = $this->actions->kill($identity, $request, $this->account($identity), (int) $pid);

        return $this->after($request, $session, $identity, $outcome, 'Files.ftp_session_ended');
    }

    /**
     * Back to the page with a notice; a refused form shows again with its
     * error beside the field and what was typed.
     *
     * @param array<string, string> $old
     */
    private function after(Request $request, Session $session, Identity $identity, FtpOutcome $outcome, string $done, array $old = [], string $form = ''): Response
    {
        if ($outcome->status === FtpOutcome::REFUSED && $form !== '' && $outcome->code !== '') {
            return $this->user($request, $session, $identity, [
                'errors' => [$form . '_' . $outcome->code => (string) $outcome->message],
                'old' => $old,
            ], 422);
        }

        match ($outcome->status) {
            FtpOutcome::REFUSED => PanelPage::notice($session, 'error', (string) $outcome->message),
            FtpOutcome::FAILED => PanelPage::notice($session, 'error', 'Files.ftp_refused'),
            FtpOutcome::PENDING => PanelPage::notice($session, 'warning', 'Files.ftp_pending'),
            default => PanelPage::notice($session, 'success', $done),
        };

        return new RedirectResponse($this->router->url('user.ftp'), 303);
    }

    /** @param array<string, mixed> $extra */
    private function user(Request $request, Session $session, Identity $identity, array $extra = [], int $status = 200): Response
    {
        $account = $this->account($identity);
        $id = (int) $account['id'];
        $package = $this->packages->byId((int) $account['package_id']) ?? [];
        $limit = (int) ($package['ftp_accounts'] ?? 0);
        $users = \array_map(fn(array $u): array => $u + [
            'password_url' => $this->router->url('user.ftp.password', ['id' => $u['id']]),
            'folder_url' => $this->router->url('user.ftp.folder', ['id' => $u['id']]),
            'delete_url' => $this->router->url('user.ftp.delete', ['id' => $u['id']]),
        ], $this->users->users($id));
        $info = $this->actions->info($account);
        $sessions = \array_map(fn(array $s): array => $s + [
            'kill_url' => $this->router->url('user.ftp.kill', ['pid' => (int) ($s['pid'] ?? 0)]),
        ], $info['sessions'] ?? []);

        return $this->pages->render($request, $session, $identity, 'user', '@Files/ftp', $extra + [
            'account' => $account,
            'users' => $users,
            'domains' => $this->actions->domainsOf($account),
            'limit' => $limit,
            'at_limit' => $limit > 0 && \count($users) >= $limit,
            'password_min' => FtpUsers::PASSWORD_MIN,
            'host' => $this->ssl->hostname(),
            'settings' => $this->settings->get(),
            'info' => $info,
            'sessions' => $sessions,
            'state' => $this->sync->stateOf($id),
            'errors' => [],
            'old' => ['folder' => 'public_html'],
            'store_url' => $this->router->url('user.ftp.store'),
            'files_url' => $this->router->url('user.files'),
        ], title: 'Files.ftp_title', active: 'ftp', status: $status);
    }

    /** @return array<string, mixed> */
    private function account(Identity $identity): array
    {
        return $this->subusers->accountOf($identity) ?? throw HttpException::notFound();
    }

    /**
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>
     */
    private function find(array $account, string $id): array
    {
        return (\ctype_digit($id) ? $this->users->user((int) $account['id'], (int) $id) : null) ?? throw HttpException::notFound();
    }

    // -------------------------------------------------------------------------
    // Admin panel

    public function admin(Request $request, Session $session, Identity $identity): Response
    {
        return $this->adminPage($request, $session, $identity);
    }

    public function saveSettings(Request $request, Session $session, Identity $identity): Response
    {
        $input = [];

        foreach (\array_keys(FtpSettings::DEFAULTS) as $name) {
            $input[$name] = $request->input($name);
        }

        [$ok, $errors, $why] = $this->settings->save($identity, $request, $input);

        if ($errors !== []) {
            return $this->adminPage($request, $session, $identity, ['errors' => $errors, 'form' => $input], 422);
        }

        if ($ok) {
            PanelPage::notice($session, 'success', 'Files.ftp_settings_saved');
        } else {
            PanelPage::notice($session, 'error', \str_starts_with((string) $why, 'Files.') ? (string) $why : 'Files.ftp_settings_refused');
        }

        return new RedirectResponse($this->router->url('admin.ftp'), 303);
    }

    public function adminKill(Request $request, Session $session, Identity $identity, string $pid): Response
    {
        $outcome = $this->actions->kill($identity, $request, null, (int) $pid);

        match ($outcome->status) {
            FtpOutcome::DONE => PanelPage::notice($session, 'success', 'Files.ftp_session_ended'),
            FtpOutcome::REFUSED => PanelPage::notice($session, 'error', (string) $outcome->message),
            default => PanelPage::notice($session, 'error', 'Files.ftp_refused'),
        };

        return new RedirectResponse($this->router->url('admin.ftp'), 303);
    }

    /** @param array<string, mixed> $extra */
    private function adminPage(Request $request, Session $session, Identity $identity, array $extra = [], int $status = 200): Response
    {
        $info = $this->actions->info();
        $sessions = \array_map(fn(array $s): array => $s + [
            'kill_url' => $this->router->url('admin.ftp.kill', ['pid' => (int) ($s['pid'] ?? 0)]),
        ], $info['sessions'] ?? []);

        return $this->pages->render($request, $session, $identity, 'admin', '@Files/ftp-admin', $extra + [
            'info' => $info,
            'sessions' => $sessions,
            'settings' => $this->settings->get(),
            'form' => $this->settings->get(),
            'errors' => [],
            'tls_modes' => FtpSettings::TLS_MODES,
            'logins' => $this->users->all(),
            'host' => $this->ssl->hostname(),
            'settings_url' => $this->router->url('admin.ftp.settings'),
        ], title: 'Files.ftp_title', active: 'ftp', status: $status);
    }
}
