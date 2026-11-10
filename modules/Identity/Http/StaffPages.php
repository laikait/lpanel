<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Security\Secret;
use App\Engine\Session\Session;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Panel\Ui\PanelPage;

/**
 * Panel administrators: staff who run the server without knowing the root
 * password. Only root (staff.manage) creates, disables or resets them.
 */
final class StaffPages
{
    public function __construct(
        private readonly PanelUsers $users,
        private readonly AuditLog $audit,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    public function index(Request $request, Session $session, Identity $identity): Response
    {
        return $this->page($request, $session, $identity);
    }

    public function create(Request $request, Session $session, Identity $identity): Response
    {
        $input = [
            'username' => PanelUsers::normalise((string) $request->input('username', '')),
            'name' => \trim((string) $request->input('name', '')),
            'email' => \trim((string) $request->input('email', '')),
        ];
        $password = (string) $request->input('password', '');
        $errors = [];

        if (\preg_match(PanelUsers::STAFF_USERNAME, $input['username']) !== 1 || $input['username'] === 'root') {
            $errors['username'] = 'Identity.error_username';
        } elseif ($this->users->find($input['username']) !== null) {
            $errors['username'] = 'Identity.error_username_taken';
        }

        if (\mb_strlen($input['name']) > 120) {
            $errors['name'] = 'Identity.error_name';
        }

        if ($input['email'] !== '' && \filter_var($input['email'], \FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Identity.error_email';
        }

        if (\mb_strlen($password) < SecurityPage::MIN_PASSWORD) {
            $errors['password'] = 'Identity.error_short_password';
        }

        if ($errors !== []) {
            return $this->page($request, $session, $identity, ['errors' => $errors, 'old' => $input], 422);
        }

        $id = $this->users->create(Kind::Admin, $input['username'], $input['name'], $input['email'], new Secret($password));
        $this->audit->record('staff.create', $identity, $request, $input['username'], AuditLog::OK, ['id' => $id]);
        PanelPage::notice($session, 'success', 'Identity.staff_created');

        return new RedirectResponse($this->router->url('admin.staff'), 303);
    }

    public function setActive(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $row = $this->staff($id);
        $active = $request->input('active') === '1';
        $this->users->setActive($id, $active);
        $this->audit->record($active ? 'staff.enable' : 'staff.disable', $identity, $request, (string) $row['username']);
        PanelPage::notice($session, 'success', $active ? 'Identity.staff_enabled' : 'Identity.staff_disabled');

        return new RedirectResponse($this->router->url('admin.staff'), 303);
    }

    public function resetPassword(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $row = $this->staff($id);
        $password = (string) $request->input('password', '');

        if (\mb_strlen($password) < SecurityPage::MIN_PASSWORD) {
            PanelPage::notice($session, 'error', 'Identity.error_short_password');

            return new RedirectResponse($this->router->url('admin.staff'), 303);
        }

        $this->users->setPassword($id, new Secret($password));
        $this->audit->record('staff.password_reset', $identity, $request, (string) $row['username']);
        PanelPage::notice($session, 'success', 'Identity.staff_password_reset');

        return new RedirectResponse($this->router->url('admin.staff'), 303);
    }

    /** @param array<string, mixed> $extra */
    private function page(Request $request, Session $session, Identity $identity, array $extra = [], int $status = 200): Response
    {
        $staff = [];

        foreach ($this->users->ofKinds(Kind::Admin) as $row) {
            $row['active_url'] = $this->router->url('admin.staff.active', ['id' => $row['id']]);
            $row['password_url'] = $this->router->url('admin.staff.password', ['id' => $row['id']]);
            $staff[] = $row;
        }

        return $this->pages->render($request, $session, $identity, 'admin', '@Identity/staff', $extra + [
            'staff' => $staff,
            'action' => $this->router->url('admin.staff.create'),
            'min_password' => SecurityPage::MIN_PASSWORD,
            'errors' => [],
            'old' => [],
        ], title: 'Identity.staff', active: 'staff', status: $status);
    }

    /** @return array<string, mixed> an admin login, or 404 for anything else */
    private function staff(string $id): array
    {
        $row = $this->users->row($id);

        if ($row === null || $row['kind'] !== Kind::Admin->value) {
            throw HttpException::notFound();
        }

        return $row;
    }
}
