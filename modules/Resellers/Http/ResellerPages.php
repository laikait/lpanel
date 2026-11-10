<?php

declare(strict_types=1);

namespace App\Modules\Resellers\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Security\Secret;
use App\Engine\Session\Session;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\Http\SecurityPage;
use App\Modules\Identity\LoginAs;
use App\Modules\Identity\PanelUsers;
use App\Modules\Panel\Ui\PanelPage;
use App\Modules\Resellers\Resellers;

/**
 * Resellers in the Admin panel, for root and administrators (resellers.manage).
 */
final class ResellerPages
{
    public function __construct(
        private readonly Resellers $resellers,
        private readonly PanelUsers $users,
        private readonly LoginAs $loginAs,
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
        $input = self::input($request);
        [$clean, $errors] = $this->validateNew($input);

        if ($errors !== []) {
            unset($input['password']);

            return $this->list($request, $session, $identity, ['errors' => $errors, 'old' => $input], 422);
        }

        $id = $this->resellers->create($clean['username'], $clean['name'], $clean['email'], $clean['password'], $clean['limits']);
        $this->audit->record('reseller.create', $identity, $request, $clean['username'], AuditLog::OK, ['id' => $id] + $clean['limits']);
        PanelPage::notice($session, 'success', 'Resellers.created');

        return new RedirectResponse($this->router->url('admin.resellers.show', ['id' => $id]), 303);
    }

    public function show(Request $request, Session $session, Identity $identity, string $id): Response
    {
        return $this->detail($request, $session, $identity, $this->reseller($id));
    }

    public function limits(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $reseller = $this->reseller($id);
        [$clean, $errors] = $this->resellers->validateLimits(self::input($request));

        if ($errors !== []) {
            return $this->detail($request, $session, $identity, $reseller, ['errors' => $errors], 422);
        }

        $this->resellers->saveLimits((int) $reseller['id'], $clean);
        $this->audit->record('reseller.limits', $identity, $request, (string) $reseller['username'], AuditLog::OK, $clean);
        PanelPage::notice($session, 'success', 'Resellers.saved');

        return $this->back($reseller);
    }

    public function setActive(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $reseller = $this->reseller($id);
        $active = $request->input('active') === '1';
        $this->users->setActive((string) $reseller['id'], $active);
        $this->audit->record($active ? 'reseller.enable' : 'reseller.disable', $identity, $request, (string) $reseller['username']);
        PanelPage::notice($session, 'success', $active ? 'Resellers.enabled' : 'Resellers.disabled_notice');

        return $this->back($reseller);
    }

    public function password(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $reseller = $this->reseller($id);
        $password = (string) $request->input('password', '');

        if (\mb_strlen($password) < SecurityPage::MIN_PASSWORD) {
            PanelPage::notice($session, 'error', 'Resellers.error_password');
        } else {
            $this->users->setPassword((string) $reseller['id'], new Secret($password));
            $this->audit->record('reseller.password', $identity, $request, (string) $reseller['username']);
            PanelPage::notice($session, 'success', 'Resellers.password_changed');
        }

        return $this->back($reseller);
    }

    public function delete(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $reseller = $this->reseller($id);

        if (!$this->resellers->removable((int) $reseller['id'])) {
            PanelPage::notice($session, 'error', 'Resellers.not_removable');

            return $this->back($reseller);
        }

        $this->resellers->delete((int) $reseller['id']);
        $this->audit->record('reseller.delete', $identity, $request, (string) $reseller['username']);
        PanelPage::notice($session, 'success', 'Resellers.deleted');

        return new RedirectResponse($this->router->url('admin.resellers'), 303);
    }

    /**
     * The checks for a new reseller, shared with the API and the CLI.
     *
     * @param array<string, mixed> $input
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public function validateNew(array $input): array
    {
        $errors = [];
        $username = PanelUsers::normalise((string) ($input['username'] ?? ''));
        $name = \trim((string) ($input['name'] ?? ''));
        $email = \trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if (\preg_match(PanelUsers::STAFF_USERNAME, $username) !== 1 || $username === 'root') {
            $errors['username'] = 'Resellers.error_username';
        } elseif ($this->users->find($username) !== null) {
            $errors['username'] = 'Resellers.error_username_taken';
        }

        if (\mb_strlen($name) > 120) {
            $errors['name'] = 'Resellers.error_name';
        }

        if ($email !== '' && \filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Resellers.error_email';
        }

        if (\mb_strlen($password) < SecurityPage::MIN_PASSWORD) {
            $errors['password'] = 'Resellers.error_password';
        }

        [$limits, $limitErrors] = $this->resellers->validateLimits($input);

        return [[
            'username' => $username, 'name' => $name, 'email' => $email !== '' ? $email : null,
            'password' => new Secret($password), 'limits' => $limits,
        ], $errors + $limitErrors];
    }

    /** @return array<string, mixed> */
    private static function input(Request $request): array
    {
        $input = [];

        foreach (['username', 'name', 'email', 'password', 'max_accounts', 'disk_mb', 'bandwidth_mb'] as $field) {
            $input[$field] = (string) $request->input($field, '');
        }

        $input['oversell'] = $request->input('oversell') === '1';
        $acl = $request->input('acl', []);
        $input['acl'] = \is_array($acl) ? $acl : [];

        return $input;
    }

    /** @param array<string, mixed> $extra */
    private function list(Request $request, Session $session, Identity $identity, array $extra = [], int $status = 200): Response
    {
        $rows = [];

        foreach ($this->resellers->all() as $row) {
            $row['url'] = $this->router->url('admin.resellers.show', ['id' => $row['id']]);
            $rows[] = $row;
        }

        return $this->pages->render($request, $session, $identity, 'admin', '@Resellers/index', $extra + [
            'resellers' => $rows,
            'action' => $this->router->url('admin.resellers.create'),
            'acl' => Resellers::ACL,
            'min_password' => SecurityPage::MIN_PASSWORD,
            'errors' => [],
            'old' => ['acl' => Resellers::ACL],
        ], title: 'Resellers.resellers', active: 'resellers', status: $status);
    }

    /**
     * @param array<string, mixed> $reseller
     * @param array<string, mixed> $extra
     */
    private function detail(Request $request, Session $session, Identity $identity, array $reseller, array $extra = [], int $status = 200): Response
    {
        $urls = ['back' => $this->router->url('admin.resellers'), 'login_as' => $this->router->url('admin.login_as', ['id' => $reseller['id']])];

        foreach (['limits', 'active', 'password', 'delete'] as $action) {
            $urls[$action] = $this->router->url('admin.resellers.' . $action, ['id' => $reseller['id']]);
        }

        return $this->pages->render($request, $session, $identity, 'admin', '@Resellers/show', $extra + [
            'reseller' => $reseller,
            'urls' => $urls,
            'acl' => Resellers::ACL,
            'can_login_as' => $this->loginAs->may($identity, $reseller),
            'removable' => $this->resellers->removable((int) $reseller['id']),
            'min_password' => SecurityPage::MIN_PASSWORD,
            'errors' => [],
        ], title: (string) $reseller['username'], active: 'resellers', status: $status);
    }

    /** @return array<string, mixed> */
    private function reseller(string $id): array
    {
        return $this->resellers->find($id) ?? throw HttpException::notFound();
    }

    /** @param array<string, mixed> $reseller */
    private function back(array $reseller): Response
    {
        return new RedirectResponse($this->router->url('admin.resellers.show', ['id' => $reseller['id']]), 303);
    }
}
