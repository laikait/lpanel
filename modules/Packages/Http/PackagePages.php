<?php

declare(strict_types=1);

namespace App\Modules\Packages\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Session\Session;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\PanelUsers;
use App\Modules\Packages\Packages;
use App\Modules\Panel\Ui\PanelPage;

/**
 * Packages in the Admin panel: the server's (root and administrators) and
 * each reseller's own.
 */
final class PackagePages
{
    public function __construct(
        private readonly Packages $packages,
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
        $owner = $this->packages->scopeOf($identity);
        [$clean, $errors] = $this->packages->validate(self::input($request), $owner);

        if ($errors !== []) {
            return $this->list($request, $session, $identity, ['errors' => $errors, 'old' => $clean], 422);
        }

        $id = $this->packages->create($clean, $owner);
        $this->audit->record('package.create', $identity, $request, $clean['name'], AuditLog::OK, ['id' => $id] + $clean);
        PanelPage::notice($session, 'success', 'Packages.created');

        return new RedirectResponse($this->router->url('admin.packages'), 303);
    }

    public function edit(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $row = $this->packages->find($identity, $id) ?? throw HttpException::notFound();

        return $this->editPage($request, $session, $identity, $row, $row, []);
    }

    public function update(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $row = $this->packages->find($identity, $id) ?? throw HttpException::notFound();
        [$clean, $errors] = $this->packages->validate(self::input($request), $row['owner_id'], (int) $row['id']);

        if ($errors !== []) {
            return $this->editPage($request, $session, $identity, $row, $clean, $errors, 422);
        }

        $this->packages->update((int) $row['id'], $clean);
        $this->audit->record('package.update', $identity, $request, $clean['name'], AuditLog::OK, ['id' => (int) $row['id']] + $clean);
        PanelPage::notice($session, 'success', 'Packages.updated');

        return new RedirectResponse($this->router->url('admin.packages'), 303);
    }

    public function delete(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $row = $this->packages->find($identity, $id) ?? throw HttpException::notFound();

        if ($this->packages->inUse((int) $row['id']) > 0) {
            PanelPage::notice($session, 'error', 'Packages.in_use');

            return new RedirectResponse($this->router->url('admin.packages'), 303);
        }

        $this->packages->delete((int) $row['id']);
        $this->audit->record('package.delete', $identity, $request, (string) $row['name'], AuditLog::OK, ['id' => (int) $row['id']]);
        PanelPage::notice($session, 'success', 'Packages.deleted');

        return new RedirectResponse($this->router->url('admin.packages'), 303);
    }

    /** @return array<string, mixed> */
    public static function input(Request $request): array
    {
        $input = ['name' => $request->input('name', ''), 'features' => $request->input('features', []), 'bandwidth_action' => $request->input('bandwidth_action', 'block')];

        foreach (Packages::LIMITS as $limit) {
            $input[$limit] = $request->input($limit, 0);
        }

        return $input;
    }

    /** @param array<string, mixed> $extra */
    private function list(Request $request, Session $session, Identity $identity, array $extra = [], int $status = 200): Response
    {
        $rows = [];
        $showOwner = $this->packages->scopeOf($identity) === null;

        foreach ($this->packages->visibleTo($identity) as $row) {
            $row['accounts'] = $this->packages->inUse((int) $row['id']);
            $row['edit_url'] = $this->router->url('admin.packages.edit', ['id' => $row['id']]);
            $row['owner_name'] = $row['owner_id'] === null ? null : (string) ($this->users->row((string) $row['owner_id'])['username'] ?? '?');
            $rows[] = $row;
        }

        return $this->pages->render($request, $session, $identity, 'admin', '@Packages/index', $extra + [
            'packages' => $rows,
            'show_owner' => $showOwner,
            'action' => $this->router->url('admin.packages.create'),
            'errors' => [],
            'old' => ['features' => Packages::DEFAULT_FEATURES],
        ] + self::formData(), title: 'Packages.packages', active: 'packages', status: $status);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function editPage(Request $request, Session $session, Identity $identity, array $row, array $values, array $errors, int $status = 200): Response
    {
        $accounts = $this->packages->inUse((int) $row['id']);

        return $this->pages->render($request, $session, $identity, 'admin', '@Packages/edit', [
            'package' => $row,
            'old' => $values,
            'errors' => $errors,
            'accounts' => $accounts,
            'action' => $this->router->url('admin.packages.update', ['id' => $row['id']]),
            'delete_url' => $this->router->url('admin.packages.delete', ['id' => $row['id']]),
            'back' => $this->router->url('admin.packages'),
        ] + self::formData(), title: 'Packages.edit', active: 'packages', status: $status);
    }

    /** @return array<string, mixed> */
    private static function formData(): array
    {
        return ['limits' => Packages::LIMITS, 'features' => Packages::FEATURES];
    }
}
