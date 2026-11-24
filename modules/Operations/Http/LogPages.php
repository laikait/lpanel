<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Session\Session;
use App\Modules\Accounts\Accounts;
use App\Modules\Operations\Logs\LogViewer;
use App\Modules\Panel\Ui\PanelPage;
use App\Modules\Subusers\Subusers;

/**
 * Logs and statistics pages. Admin (server.logs): the system's logs and, by
 * choosing an account, any account's; statistics of all sites or one. User:
 * the account's own logs and statistics, never another's: the account is the
 * signed-in login's, not anything in the request.
 */
final class LogPages
{
    public function __construct(
        private readonly LogViewer $logs,
        private readonly Accounts $accounts,
        private readonly Subusers $subusers,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    public function adminLogs(Request $request, Session $session, Identity $identity): Response
    {
        $user = $this->accountParam($request);

        return $this->logsPage($request, $session, $identity, 'admin', $user, 'admin.logs');
    }

    public function userLogs(Request $request, Session $session, Identity $identity): Response
    {
        return $this->logsPage($request, $session, $identity, 'user', $this->ownUser($identity), 'user.logs');
    }

    public function adminStats(Request $request, Session $session, Identity $identity): Response
    {
        return $this->statsPage($request, $session, $identity, 'admin', $this->accountParam($request), 'admin.statistics', false);
    }

    public function userStats(Request $request, Session $session, Identity $identity): Response
    {
        return $this->statsPage($request, $session, $identity, 'user', $this->ownUser($identity), 'user.statistics', false);
    }

    public function adminRefresh(Request $request, Session $session, Identity $identity): Response
    {
        return $this->refresh($request, $session, 'admin.statistics', $this->accountParam($request, true));
    }

    public function userRefresh(Request $request, Session $session, Identity $identity): Response
    {
        return $this->refresh($request, $session, 'user.statistics', $this->ownUser($identity));
    }

    private function logsPage(Request $request, Session $session, Identity $identity, string $area, ?string $user, string $route): Response
    {
        [$sources, $why] = $this->logs->sources($user);
        $sources ??= [];
        $names = \array_column($sources, 'name');
        $source = (string) $request->query('source', '');

        if (!\in_array($source, $names, true)) {
            $source = $names[0] ?? '';
        }

        $lines = (int) $request->query('lines', 200);
        $filter = \trim((string) $request->query('filter', ''));
        $out = null;

        if ($source !== '' && $why === null) {
            [$out, $why] = $this->logs->read($user, $source, $lines, $filter);
        }

        $groups = [];

        foreach ($sources as $s) {
            $groups[(string) $s['group']][] = $s;
        }

        return $this->pages->render($request, $session, $identity, $area, 'Operations/logs', [
            'groups' => $groups,
            'source' => $source,
            'label' => (string) (\array_column($sources, 'label', 'name')[$source] ?? ''),
            'lines' => \in_array($lines, LogViewer::LINES, true) ? $lines : 200,
            'line_options' => LogViewer::LINES,
            'filter' => $filter,
            'log' => $out,
            'problem' => $why,
            'user' => $user,
            'accounts' => $area === 'admin' ? $this->usernames() : [],
            'is_admin' => $area === 'admin',
            'action' => $this->router->url($route),
        ], title: 'Operations.logs_title', active: 'logs');
    }

    private function statsPage(Request $request, Session $session, Identity $identity, string $area, ?string $user, string $route, bool $refresh): Response
    {
        $days = (int) $request->query('days', 30);
        $days = \in_array($days, LogViewer::DAYS, true) ? $days : 30;
        [$report, $why] = $this->logs->report($user, $days, $refresh);
        $max = 0;

        foreach ((array) ($report['daily'] ?? []) as $d) {
            $max = \max($max, (int) $d['hits']);
        }

        return $this->pages->render($request, $session, $identity, $area, 'Operations/statistics', [
            'report' => $report,
            'problem' => $why,
            'days' => $days,
            'day_options' => LogViewer::DAYS,
            'max_hits' => $max,
            'user' => $user,
            'accounts' => $area === 'admin' ? $this->usernames() : [],
            'is_admin' => $area === 'admin',
            'action' => $this->router->url($route),
            'refresh_url' => $this->router->url($route . '.refresh') . '?' . \http_build_query(\array_filter(['days' => $days, 'account' => $user])),
        ], title: 'Operations.stats_title', active: 'statistics');
    }

    private function refresh(Request $request, Session $session, string $route, ?string $user): Response
    {
        $days = (int) $request->query('days', 30);
        [, $why] = $this->logs->report($user, $days, true);
        PanelPage::notice($session, $why === null ? 'success' : 'error', $why ?? 'Operations.stats_refreshed');

        return new RedirectResponse($this->router->url($route) . '?' . \http_build_query(\array_filter(['days' => $days, 'account' => $user])), 303);
    }

    /** The account an Admin page looks at (none: the server as a whole). */
    private function accountParam(Request $request, bool $query = true): ?string
    {
        $u = (string) $request->query('account', '');

        return $u !== '' && \in_array($u, $this->usernames(), true) ? $u : null;
    }

    /** The signed-in login's own account. */
    private function ownUser(Identity $identity): string
    {
        $account = $this->subusers->accountOf($identity) ?? throw HttpException::notFound();

        return (string) $account['username'];
    }

    /** @return list<string> */
    private function usernames(): array
    {
        return \array_values(\array_map(static fn(array $a): string => (string) $a['username'], $this->accounts->withSites()));
    }
}
