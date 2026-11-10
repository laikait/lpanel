<?php

declare(strict_types=1);

namespace App\Modules\Subusers\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Security\Secret;
use App\Engine\Session\Session;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\PanelUsers;
use App\Modules\Panel\Ui\PanelPage;
use App\Modules\Subusers\Subusers;

/**
 * "Users" in the User panel: the owner's sub-users and what each may use.
 */
final class SubuserPages
{
    public function __construct(
        private readonly Subusers $subusers,
        private readonly PanelUsers $users,
        private readonly AuditLog $audit,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    public function index(Request $request, Session $session, Identity $identity): Response
    {
        return $this->list($request, $session, $identity);
    }

    public function create(Request $request, Session $session, Identity $identity): Response
    {
        $offered = $this->offered($identity);
        $owner = $this->users->row($identity->id) ?? throw HttpException::notFound();
        $input = [
            'suffix' => (string) $request->input('suffix', ''),
            'name' => (string) $request->input('name', ''),
            'email' => (string) $request->input('email', ''),
            'password' => (string) $request->input('password', ''),
            'grants' => $request->input('grants', []),
        ];
        [$clean, $errors] = $this->subusers->validateNew($input, $owner, $offered);

        if ($errors !== []) {
            unset($input['password']);

            return $this->list($request, $session, $identity, ['errors' => $errors, 'old' => $input], 422);
        }

        $id = $this->subusers->create($identity->id, $clean);
        $this->audit->record('subuser.create', $identity, $request, $clean['username'], AuditLog::OK, ['id' => $id, 'grants' => \implode(',', $clean['grants'])]);
        PanelPage::notice($session, 'success', 'Subusers.created');

        return new RedirectResponse($this->router->url('user.subusers'), 303);
    }

    public function grants(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $sub = $this->subuser($identity, $id);
        $grants = Subusers::onlyOffered($request->input('grants', []), $this->offered($identity));
        $this->subusers->setGrants((int) $sub['id'], $grants);
        $this->audit->record('subuser.grants', $identity, $request, (string) $sub['username'], AuditLog::OK, ['grants' => \implode(',', $grants)]);
        PanelPage::notice($session, 'success', 'Subusers.saved');

        return new RedirectResponse($this->router->url('user.subusers'), 303);
    }

    public function password(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $sub = $this->subuser($identity, $id);
        $password = (string) $request->input('password', '');

        if (\mb_strlen($password) < 12) {
            PanelPage::notice($session, 'error', 'Subusers.error_password');
        } else {
            $this->users->setPassword((string) $sub['id'], new Secret($password));
            $this->audit->record('subuser.password', $identity, $request, (string) $sub['username']);
            PanelPage::notice($session, 'success', 'Subusers.password_changed');
        }

        return new RedirectResponse($this->router->url('user.subusers'), 303);
    }

    public function setActive(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $sub = $this->subuser($identity, $id);
        $active = $request->input('active') === '1';
        $this->users->setActive((string) $sub['id'], $active);
        $this->audit->record($active ? 'subuser.enable' : 'subuser.disable', $identity, $request, (string) $sub['username']);
        PanelPage::notice($session, 'success', $active ? 'Subusers.enabled' : 'Subusers.disabled_notice');

        return new RedirectResponse($this->router->url('user.subusers'), 303);
    }

    public function delete(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $sub = $this->subuser($identity, $id);
        $this->users->delete((string) $sub['id']);
        $this->audit->record('subuser.delete', $identity, $request, (string) $sub['username']);
        PanelPage::notice($session, 'success', 'Subusers.deleted');

        return new RedirectResponse($this->router->url('user.subusers'), 303);
    }

    /** @return list<string> the features this owner's package includes */
    private function offered(Identity $identity): array
    {
        $account = $this->subusers->accountOf($identity) ?? throw HttpException::notFound();

        return $this->subusers->packageFeatures($account);
    }

    /** @param array<string, mixed> $extra */
    private function list(Request $request, Session $session, Identity $identity, array $extra = [], int $status = 200): Response
    {
        $offered = $this->offered($identity);
        $rows = [];

        foreach ($this->subusers->of($identity->id) as $row) {
            foreach (['grants', 'password', 'active', 'delete'] as $action) {
                $row[$action . '_url'] = $this->router->url('user.subusers.' . $action, ['id' => $row['id']]);
            }

            $rows[] = $row;
        }

        return $this->pages->render($request, $session, $identity, 'user', '@Subusers/index', $extra + [
            'subusers' => $rows,
            'offered' => $offered,
            'prefix' => (string) $identity->attribute('username') . '.',
            'action' => $this->router->url('user.subusers.create'),
            'errors' => [],
            'old' => ['grants' => []],
        ], title: 'Subusers.users', active: 'subusers', status: $status);
    }

    /** @return array<string, mixed> */
    private function subuser(Identity $identity, string $id): array
    {
        return $this->subusers->find($identity->id, $id) ?? throw HttpException::notFound();
    }
}
