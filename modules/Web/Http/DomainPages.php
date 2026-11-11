<?php

declare(strict_types=1);

namespace App\Modules\Web\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Session\Session;
use App\Modules\Packages\Packages;
use App\Modules\Panel\Ui\PanelPage;
use App\Modules\Subusers\Subusers;
use App\Modules\Web\DomainActions;
use App\Modules\Web\Domains;
use App\Modules\Web\WebOutcome;
use App\Modules\Web\WebSync;

/**
 * "Domains" in the User panel: the account's sites, their folders and PHP
 * versions, and the names that point at them.
 */
final class DomainPages
{
    public function __construct(
        private readonly Domains $domains,
        private readonly DomainActions $actions,
        private readonly WebSync $sync,
        private readonly Subusers $subusers,
        private readonly Packages $packages,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    public function index(Request $request, Session $session, Identity $identity): Response
    {
        return $this->list($request, $session, $identity);
    }

    public function store(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->account($identity);
        $input = [
            'kind' => (string) $request->input('kind', ''),
            'name' => (string) $request->input('name', ''),
            'target' => (string) $request->input('target', ''),
            'docroot' => (string) $request->input('docroot', ''),
            'php' => (string) $request->input('php', ''),
            'wildcard' => $request->input('wildcard') === '1',
        ];
        [$outcome, $errors] = $this->actions->add($identity, $request, $account, $input);

        if ($outcome === null) {
            return $this->list($request, $session, $identity, ['errors' => $errors, 'old' => $input], 422);
        }

        self::notify($session, $outcome, 'Web.added');

        return new RedirectResponse($this->router->url('user.domains'), 303);
    }


    /** Called from the list and from the domain's own page. */
    public function delete(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $account = $this->account($identity);
        $domain = $this->domains->find((int) $account['id'], $id) ?? throw HttpException::notFound();
        [$outcome, $blocker] = $this->actions->remove($identity, $request, $account, $domain);

        if ($outcome === null) {
            PanelPage::notice($session, 'error', (string) $blocker);
        } else {
            self::notify($session, $outcome, 'Web.removed');
        }

        return new RedirectResponse($this->router->url('user.domains'), 303);
    }

    public static function notify(Session $session, WebOutcome $outcome, string $done): void
    {
        match ($outcome->status) {
            WebOutcome::FAILED => PanelPage::notice($session, 'error', 'Web.refused'),
            WebOutcome::PENDING => PanelPage::notice($session, 'warning', 'Web.pending'),
            default => PanelPage::notice($session, 'success', $done),
        };
    }

    /** @param array<string, mixed> $extra */
    private function list(Request $request, Session $session, Identity $identity, array $extra = [], int $status = 200): Response
    {
        $account = $this->account($identity);
        $rows = $this->domains->forAccount((int) $account['id']);
        $byId = [];

        foreach ($rows as $row) {
            $byId[$row['id']] = $row['name'];
        }

        $inUse = [];

        foreach ($rows as &$row) {
            $row['parent_name'] = $row['parent_id'] === null ? null : ($byId[$row['parent_id']] ?? null);
            $row['show_url'] = $this->router->url('user.domains.show', ['id' => $row['id']]);
            $row['delete_url'] = $this->router->url('user.domains.delete', ['id' => $row['id']]);

            if ($row['php'] !== null) {
                $inUse[] = (string) $row['php'];
            }
        }
        unset($row);

        $package = $this->packages->byId((int) $account['package_id']) ?? [];
        $web = $this->sync->stateOf((int) $account['id']);

        return $this->pages->render($request, $session, $identity, 'user', '@Web/domains', $extra + [
            'account' => $account,
            'domains' => $rows,
            'sites' => \array_values(\array_filter($rows, static fn(array $d): bool => \in_array($d['kind'], [Domains::MAIN, Domains::ADDON], true))),
            'usage' => $this->domains->usage((int) $account['id'], $package),
            'php' => $this->sync->phpVersions($inUse),
            'default_php' => $this->sync->defaultPhp(),
            'web' => $web,
            'action' => $this->router->url('user.domains.store'),
            'errors' => [],
            'old' => ['kind' => 'addon'],
        ], title: 'Web.domains', active: 'domains', status: $status);
    }

    /** @return array<string, mixed> */
    private function account(Identity $identity): array
    {
        return $this->subusers->accountOf($identity) ?? throw HttpException::notFound();
    }
}
