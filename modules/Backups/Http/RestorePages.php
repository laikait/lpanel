<?php

declare(strict_types=1);

namespace App\Modules\Backups\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Session\Session;
use App\Modules\Accounts\Accounts;
use App\Modules\Backups\BackupRestores;
use App\Modules\Backups\BackupRuns;
use App\Modules\Backups\Destinations;
use App\Modules\Packages\Packages;
use App\Modules\Panel\Ui\PanelPage;
use App\Modules\Subusers\Subusers;

/**
 * Restoring from backups.
 *
 * "Backups" in the User panel: the account's snapshots at the scheduled
 * destination, what each holds (folder by folder, its databases, the
 * account's mailboxes), putting files back in place or into a new folder,
 * a mailbox or a database back, all of it back, and archives to download.
 *
 * In the Admin panel, the same for any account and destination, and
 * "Restore from a backup": every account a destination has snapshots of,
 * and making one that is not on this server again.
 */
final class RestorePages
{
    public function __construct(
        private readonly BackupRestores $restores,
        private readonly BackupRuns $runs,
        private readonly Destinations $destinations,
        private readonly Accounts $accounts,
        private readonly Packages $packages,
        private readonly Subusers $subusers,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    // -----------------------------------------------------------------------
    // User panel

    public function user(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->owned($identity);
        $destination = $this->restores->defaultDestination();

        return $this->screen($request, $session, $identity, 'user', $account, $destination, [
            'restore_url' => $this->router->url('user.backups.restore'),
            'download_url' => $this->router->url('user.backups.download'),
            'page_route' => 'user.backups',
            'page_params' => [],
        ]);
    }

    public function userRestore(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->owned($identity);
        $destination = $this->restores->defaultDestination();

        return $this->doRestore($request, $session, $identity, $account, $destination === null ? 0 : (int) $destination['id'], true, 'user.backups', []);
    }

    public function userDownload(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->owned($identity);
        $destination = $this->restores->defaultDestination();

        return $this->doDownload($request, $session, $identity, $account, $destination === null ? 0 : (int) $destination['id'], true, 'user.backups', []);
    }

    // -----------------------------------------------------------------------
    // Admin panel: one account

    public function account(Request $request, Session $session, Identity $identity, string $username): Response
    {
        $account = $this->accountNamed($identity, $username);
        $destination = $this->chosenDestination($request);

        return $this->screen($request, $session, $identity, 'admin', $account, $destination, [
            'restore_url' => $this->router->url('admin.backups.account.restore', ['username' => $username]),
            'download_url' => $this->router->url('admin.backups.account.download', ['username' => $username]),
            'page_route' => 'admin.backups.account',
            'page_params' => ['username' => $username],
            'destinations' => $this->destinations->all(),
            'account_url' => $this->router->url('admin.accounts.show', ['id' => $account['id']]),
        ]);
    }

    public function accountRestore(Request $request, Session $session, Identity $identity, string $username): Response
    {
        $account = $this->accountNamed($identity, $username);

        return $this->doRestore($request, $session, $identity, $account, (int) $request->input('destination', 0), false, 'admin.backups.account', ['username' => $username]);
    }

    public function accountDownload(Request $request, Session $session, Identity $identity, string $username): Response
    {
        $account = $this->accountNamed($identity, $username);

        return $this->doDownload($request, $session, $identity, $account, (int) $request->input('destination', 0), false, 'admin.backups.account', ['username' => $username]);
    }

    // -----------------------------------------------------------------------
    // Admin panel: every account a destination has

    public function overview(Request $request, Session $session, Identity $identity): Response
    {
        $this->restores->settle();
        $destination = $this->chosenDestination($request);
        $snapshots = $destination === null ? [] : $this->runs->snapshots((int) $destination['id']);
        $byAccount = [];

        foreach ($snapshots ?? [] as $s) {
            $name = (string) ($s['username'] ?? '');

            if ($name === '') {
                continue;
            }

            if (!isset($byAccount[$name])) {
                $here = $this->accounts->findByUsername($identity, $name);
                $byAccount[$name] = [
                    'username' => $name, 'latest' => $s, 'count' => 0, 'bytes' => (int) ($s['bytes'] ?? 0),
                    'here' => $here !== null,
                    // Another reseller's account, or one being made: not the viewer's to restore.
                    'taken' => $here === null && $this->accounts->usernameTaken($name),
                    'restore_url' => $here !== null ? $this->router->url('admin.backups.account', ['username' => $name, 'destination' => $destination['id'] ?? 0]) : null,
                    'plan_url' => $this->router->url('admin.backups.plan', ['destination' => $destination['id'] ?? 0, 'snapshot' => $s['id']]),
                ];
            }

            $byAccount[$name]['count']++;
        }

        \ksort($byAccount);
        $names = [];

        foreach ($this->destinations->all() as $d) {
            $names[(int) $d['id']] = (string) $d['name'];
        }

        return $this->pages->render($request, $session, $identity, 'admin', '@Backups/restore-overview', [
            'destination' => $destination,
            'destinations' => $this->destinations->all(),
            'destination_names' => $names,
            'accounts' => \array_values($byAccount),
            'snapshots_failed' => $destination !== null && $snapshots === null,
            'restores' => $this->decorate($this->restores->recent(null, 20)),
            'page_url' => $this->router->url('admin.backups.overview'),
            'backups_url' => $this->router->url('admin.backups'),
        ], title: 'Backups.restore_title', active: 'backups');
    }

    public function plan(Request $request, Session $session, Identity $identity): Response
    {
        return $this->planPage($request, $session, $identity);
    }

    public function recreate(Request $request, Session $session, Identity $identity): Response
    {
        $destinationId = (int) $request->input('destination', 0);
        $snapshot = (string) $request->input('snapshot', '');
        $package = (string) $request->input('package_id', '');
        $packageId = \ctype_digit($package) && $package !== '0' ? (int) $package : null;

        if ($packageId !== null && $this->packages->find($identity, $packageId) === null) {
            throw HttpException::notFound();
        }

        [$row, $why, $problems] = $this->restores->recreate($identity, $request, $destinationId, $snapshot, $packageId);

        if ($row === null) {
            return $this->planPage($request, $session, $identity, ['why' => $why, 'problems' => $problems], 422);
        }

        PanelPage::notice($session, $why === null ? 'success' : 'error', $why ?? 'Backups.recreate_started');

        return new RedirectResponse($this->router->url('admin.backups.overview', ['destination' => $destinationId]), 303);
    }

    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed>      $account
     * @param array<string, mixed>|null $destination
     * @param array<string, mixed>      $extra
     */
    private function screen(Request $request, Session $session, Identity $identity, string $area, array $account, ?array $destination, array $extra): Response
    {
        $this->restores->settle();
        $accountId = (int) $account['id'];
        $username = (string) $account['username'];
        $snapshots = $destination === null ? [] : $this->runs->snapshots((int) $destination['id'], $username);
        $wanted = \trim((string) $request->query('snapshot', ''));
        $selected = null;

        foreach ($snapshots ?? [] as $s) {
            if ($wanted !== '' && ($s['id'] === $wanted || $s['short_id'] === $wanted)) {
                $selected = $s;
            }
        }

        $path = BackupRestores::path((string) $request->query('path', '')) ?? '';
        $listing = null;
        $listingWhy = null;

        if ($selected !== null && $destination !== null) {
            [$listing, $listingWhy] = $this->restores->files((int) $destination['id'], (string) $selected['id'], $username, $path);
        }

        $base = $extra['page_params'] + ($area === 'admin' && $destination !== null ? ['destination' => $destination['id']] : []);
        $url = fn(array $q = []): string => $this->router->url((string) $extra['page_route'], $base + $q);
        $crumbs = [];

        if ($selected !== null) {
            $acc = '';

            foreach ($path === '' ? [] : \explode('/', $path) as $part) {
                $acc = $acc === '' ? $part : $acc . '/' . $part;
                $crumbs[] = ['name' => $part, 'url' => $url(['snapshot' => $selected['short_id'], 'path' => $acc])];
            }
        }

        $entries = [];

        foreach (\is_array($listing['entries'] ?? null) ? $listing['entries'] : [] as $e) {
            $p = $path === '' ? (string) $e['name'] : $path . '/' . $e['name'];
            $entries[] = $e + ['path' => $p, 'url' => $e['type'] === 'dir' && $selected !== null ? $url(['snapshot' => $selected['short_id'], 'path' => $p]) : null];
        }

        $panelDbs = $this->restores->databases($accountId);
        $active = $this->restores->active($accountId);

        return $this->pages->render($request, $session, $identity, $area, '@Backups/restore', $extra + [
            'area' => $area,
            'account' => $account,
            'destination' => $destination,
            'snapshots' => $snapshots,
            'snapshots_failed' => $destination !== null && $snapshots === null,
            'selected' => $selected,
            'path' => $path,
            'crumbs' => $crumbs,
            'top_url' => $selected !== null ? $url(['snapshot' => $selected['short_id']]) : null,
            'up_url' => $selected !== null && $path !== '' ? $url(['snapshot' => $selected['short_id'], 'path' => \dirname($path) === '.' ? '' : \dirname($path)]) : null,
            'listing' => $listing,
            'listing_why' => $listingWhy,
            'entries' => $entries,
            'snapshot_databases' => \is_array($listing['databases'] ?? null) ? $listing['databases'] : [],
            'panel_databases' => $panelDbs,
            'mailboxes' => $this->restores->mailboxes($accountId),
            'active' => $active,
            'restores' => $this->decorate($this->restores->recent($accountId, 10)),
            'open_urls' => \array_combine(
                \array_map(static fn(array $s): string => (string) $s['id'], $snapshots ?? []),
                \array_map(static fn(array $s): string => $url(['snapshot' => $s['short_id']]), $snapshots ?? []),
            ),
            'files_url' => $area === 'user' ? $this->router->url('user.files', ['path' => 'lpanel-backups']) : null,
        ], title: $area === 'user' ? 'Backups.title' : 'Backups.restore_title', active: 'backups');
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $routeParams
     */
    private function doRestore(Request $request, Session $session, Identity $identity, array $account, int $destinationId, bool $byOwner, string $route, array $routeParams): Response
    {
        $snapshot = (string) $request->input('snapshot', '');
        $kind = (string) $request->input('kind', 'files');
        $paths = self::strings($request->input('paths'));
        $databases = self::strings($request->input('databases'));
        $folder = (string) $request->input('mode', '') === 'folder';

        [$row, $why] = $this->restores->restore($identity, $request, $account, $destinationId, $snapshot, $kind, $paths, $databases, $folder, $byOwner);
        $this->notice($session, $row, $why, 'Backups.restore_started', 'Backups.restore_done');

        return $this->back($request, $route, $routeParams, $destinationId, $byOwner);
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $routeParams
     */
    private function doDownload(Request $request, Session $session, Identity $identity, array $account, int $destinationId, bool $byOwner, string $route, array $routeParams): Response
    {
        [$row, $why] = $this->restores->download(
            $identity,
            $request,
            $account,
            $destinationId,
            (string) $request->input('snapshot', ''),
            (string) $request->input('path', ''),
            (string) $request->input('database', ''),
            $byOwner,
        );
        $this->notice($session, $row, $why, 'Backups.download_started', 'Backups.download_done');

        return $this->back($request, $route, $routeParams, $destinationId, $byOwner);
    }

    /** @param array<string, mixed>|null $row */
    private function notice(Session $session, ?array $row, ?string $why, string $started, string $done): void
    {
        if ($why !== null) {
            PanelPage::notice($session, 'error', $why);
        } elseif ($row !== null && $row['state'] === BackupRestores::DONE) {
            PanelPage::notice($session, 'success', $done);
        } else {
            PanelPage::notice($session, 'success', $started);
        }
    }

    /** @param array<string, mixed> $routeParams */
    private function back(Request $request, string $route, array $routeParams, int $destinationId, bool $byOwner): RedirectResponse
    {
        $q = \array_filter([
            'destination' => $byOwner ? null : $destinationId,
            'snapshot' => (string) $request->input('back_snapshot', ''),
            'path' => (string) $request->input('back_path', ''),
        ], static fn(mixed $v): bool => $v !== null && $v !== '' && $v !== 0);

        return new RedirectResponse($this->router->url($route, $routeParams + $q) . '#restores', 303);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function planPage(Request $request, Session $session, Identity $identity, array $extra = [], int $status = 200): Response
    {
        $destinationId = (int) ($request->input('destination') ?? $request->query('destination', 0));
        $snapshot = (string) ($request->input('snapshot') ?? $request->query('snapshot', ''));
        $destination = $this->destinations->find($destinationId) ?? throw HttpException::notFound();
        [$plan, $why, $problems] = $this->restores->planAccount($destinationId, $snapshot);
        $username = (string) ($plan['username'] ?? '');
        $others = $username === '' ? [] : ($this->runs->snapshots($destinationId, $username) ?? []);
        $meta = \is_array($plan['meta'] ?? null) ? $plan['meta'] : [];
        $tables = \is_array($meta['tables'] ?? null) ? $meta['tables'] : [];
        $count = static fn(string $t): int => \is_array($tables[$t] ?? null) ? \count($tables[$t]) : 0;

        return $this->pages->render($request, $session, $identity, 'admin', '@Backups/restore-plan', $extra + [
            'destination' => $destination,
            'snapshot' => $snapshot,
            'plan' => $plan,
            'why' => $why,
            'problems' => $problems,
            'others' => \array_map(fn(array $s): array => $s + [
                'url' => $this->router->url('admin.backups.plan', ['destination' => $destinationId, 'snapshot' => $s['id']]),
            ], $others),
            'counts' => [
                'domains' => $count('domains'), 'databases' => $count('db_databases'), 'mailboxes' => $count('mail_boxes'),
                'zones' => $count('dns_zones'), 'ftp' => $count('ftp_users'), 'keys' => $count('ssh_keys'),
            ],
            'packages' => $this->packages->visibleTo($identity),
            'recreate_url' => $this->router->url('admin.backups.recreate'),
            'overview_url' => $this->router->url('admin.backups.overview', ['destination' => $destinationId]),
        ], title: 'Backups.plan_title', active: 'backups', status: $status);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function decorate(array $rows): array
    {
        return \array_map(static function (array $r): array {
            $what = \is_array($r['what'] ?? null) ? $r['what'] : [];
            $paths = \is_array($what['paths'] ?? null) ? $what['paths'] : [];
            $dbs = \is_array($what['databases'] ?? null) ? $what['databases'] : [];
            $r['summary'] = match ($r['kind']) {
                'download' => ($what['database'] ?? '') !== '' ? (string) $what['database'] : (($what['path'] ?? '') === '' ? '' : (string) $what['path']),
                'database' => \implode(', ', $dbs),
                default => \implode(', ', \array_filter(\array_map('strval', $paths), static fn(string $p): bool => $p !== '')),
            };

            return $r;
        }, $rows);
    }

    /** @return array<string, mixed> */
    private function owned(Identity $identity): array
    {
        return $this->subusers->accountOf($identity) ?? throw HttpException::notFound();
    }

    /** @return array<string, mixed> */
    private function accountNamed(Identity $identity, string $username): array
    {
        return $this->accounts->findByUsername($identity, $username) ?? throw HttpException::notFound();
    }

    /** @return array<string, mixed>|null */
    private function chosenDestination(Request $request): ?array
    {
        $id = (string) $request->query('destination', '');

        return \ctype_digit($id) && $id !== '0' ? ($this->destinations->find((int) $id) ?? $this->restores->defaultDestination()) : $this->restores->defaultDestination();
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (\is_string($value)) {
            return $value === '' ? [] : [$value];
        }

        return \is_array($value) ? \array_values(\array_filter(\array_map(static fn(mixed $v): string => \is_scalar($v) ? (string) $v : '', $value), static fn(string $v): bool => $v !== '')) : [];
    }
}
