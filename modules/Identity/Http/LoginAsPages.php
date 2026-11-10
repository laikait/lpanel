<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Engine\Auth\AuthManager;
use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Session\Session;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\LoginAs;
use App\Modules\Identity\PanelUsers;
use App\Modules\Panel\Ui\PanelPage;

/**
 * The routes of "log in as". Who may become whom is LoginAs::may().
 */
final class LoginAsPages
{
    public function __construct(
        private readonly LoginAs $loginAs,
        private readonly PanelUsers $users,
        private readonly AuthManager $auth,
        private readonly AuditLog $audit,
        private readonly Router $router,
    ) {}

    /** POST /admin/login-as/{id} */
    public function start(Request $request, Identity $identity, string $id): Response
    {
        $target = $this->users->row($id);

        // Anything this login may not become is "not found", like a page it may not see.
        if ($target === null || !$this->loginAs->may($identity, $target)) {
            throw HttpException::notFound();
        }

        return new RedirectResponse($this->loginAs->start($identity, $request, $target), 303);
    }

    /** POST /admin/login-as/return */
    public function stop(Request $request, Session $session, Identity $identity): Response
    {
        if ($identity->attribute('impersonator_id') === null) {
            return new RedirectResponse($this->router->url('admin.dashboard'), 303);
        }

        if (!$this->loginAs->stop($identity, $request)) {
            return new RedirectResponse($this->router->url('admin.login'), 303);
        }

        PanelPage::notice($session, 'success', 'Identity.login_as_ended');

        return new RedirectResponse($this->router->url('admin.dashboard'), 303);
    }

    /** GET /panel/login-as?ticket=… */
    public function redeem(Request $request, Session $session): Response
    {
        if (!$this->loginAs->redeem((string) $request->query('ticket', ''))) {
            $this->audit->record('auth.login_as_refused', null, $request, '', AuditLog::DENIED);
            PanelPage::notice($session, 'error', 'Identity.login_as_expired');

            return new RedirectResponse($this->router->url('user.login'), 303);
        }

        return new RedirectResponse($this->router->url('user.dashboard'), 303);
    }

    /** POST /panel/login-as/end: sign out here and go back to the Admin panel. */
    public function end(Request $request, Identity $identity): Response
    {
        $this->audit->record('auth.login_as_end', $identity, $request, (string) $identity->attribute('username'));
        $this->auth->logout();

        return new RedirectResponse(LoginAs::otherInterface($request, '/admin'), 303);
    }
}
