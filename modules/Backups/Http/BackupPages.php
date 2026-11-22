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
use App\Modules\Backups\BackupRuns;
use App\Modules\Backups\BackupSettings;
use App\Modules\Backups\Destinations;
use App\Modules\Panel\Ui\PanelPage;

/**
 * "Backups" in the Admin panel: the schedule and retention, the
 * destinations (each checked by the agent before it is kept), runs past and
 * present, backing up now, and one account's snapshots.
 */
final class BackupPages
{
    private const FIELDS = ['name', 'type', 'path', 'host', 'port', 'user', 'endpoint', 'scheme', 'region', 'bucket', 'prefix', 'access_key', 'secret_key', 'password'];

    public function __construct(
        private readonly Destinations $destinations,
        private readonly BackupSettings $settings,
        private readonly BackupRuns $runs,
        private readonly Accounts $accounts,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    public function index(Request $request, Session $session, Identity $identity): Response
    {
        return $this->page($request, $session, $identity);
    }

    public function addDestination(Request $request, Session $session, Identity $identity): Response
    {
        $input = $this->input($request);
        [$d, $errors, $why] = $this->destinations->save($identity, $request, $input);

        if ($errors !== [] || $d === null) {
            return $this->page($request, $session, $identity, [
                'add_errors' => $errors,
                'add_why' => $why,
                'add_old' => \array_diff_key($input, ['access_key' => 0, 'secret_key' => 0, 'password' => 0]),
            ], 422);
        }

        PanelPage::notice($session, 'success', 'Backups.destination_added');

        return $this->back();
    }

    public function changeDestination(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $current = $this->find($id);
        [$d, $errors, $why] = $this->destinations->save($identity, $request, $this->input($request), $current);

        if ($errors !== [] || $d === null) {
            PanelPage::notice($session, 'error', $why ?? (string) \reset($errors));

            return $this->back();
        }

        PanelPage::notice($session, 'success', 'Backups.destination_changed');

        return $this->back();
    }

    public function removeDestination(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $d = $this->find($id);

        if ($this->settings->get()['destination_id'] === (int) $d['id']) {
            PanelPage::notice($session, 'error', 'Backups.error_in_use');

            return $this->back();
        }

        $why = $this->destinations->remove($identity, $request, $d);
        PanelPage::notice($session, $why === null ? 'success' : 'error', $why ?? 'Backups.destination_removed');

        return $this->back();
    }

    public function saveSettings(Request $request, Session $session, Identity $identity): Response
    {
        $input = [];

        foreach (\array_keys(BackupSettings::DEFAULTS) as $name) {
            $input[$name] = $request->input($name);
        }

        // The form sends enabled=0, then 1 when the box is ticked; the last wins.
        $input['enabled'] = (string) $request->input('enabled', '0') === '1' ? '1' : '0';
        $input = \array_filter($input, static fn(mixed $v): bool => $v !== null);
        $errors = $this->settings->save($identity, $request, $input, $this->destinations->ids());

        if ($errors !== []) {
            return $this->page($request, $session, $identity, ['settings_errors' => $errors, 'settings_form' => $input], 422);
        }

        PanelPage::notice($session, 'success', 'Backups.settings_saved');

        return $this->back();
    }

    public function run(Request $request, Session $session, Identity $identity): Response
    {
        $username = \trim((string) $request->input('username', ''));
        [$run, $why] = $this->runs->start($identity, $request, 'manual', null, $username === '' ? null : [$username]);
        PanelPage::notice($session, $why === null ? 'success' : 'error', $why ?? 'Backups.run_started');

        return $username !== '' && ($account = $this->accounts->findByUsername($identity, $username)) !== null
            ? new RedirectResponse($this->router->url('admin.accounts.show', ['id' => $account['id']]), 303)
            : $this->back();
    }

    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $extra */
    private function page(Request $request, Session $session, Identity $identity, array $extra = [], int $status = 200): Response
    {
        $this->runs->settle();
        $settings = $this->settings->get();
        $destinations = \array_map(fn(array $d): array => $d + [
            'where' => Destinations::describe($d),
            'change_url' => $this->router->url('admin.backups.change', ['id' => $d['id']]),
            'remove_url' => $this->router->url('admin.backups.remove', ['id' => $d['id']]),
            'in_use' => (int) $d['id'] === $settings['destination_id'],
        ], $this->destinations->all());
        $running = $this->runs->running();
        $recent = $this->runs->recent(15);
        $names = [];

        foreach ($destinations as $d) {
            $names[(int) $d['id']] = (string) $d['name'];
        }

        // One account's snapshots, when asked for (?account=…).
        $account = \trim((string) $request->query('account', ''));
        $snapshots = null;

        if ($account !== '' && $settings['destination_id'] !== 0 && $this->accounts->findByUsername($identity, $account) !== null) {
            $snapshots = $this->runs->snapshots($settings['destination_id'], $account) ?? false;
        }

        $last = null;

        foreach ($recent as $r) {
            if ($r['state'] !== BackupRuns::RUNNING) {
                $last = $r;
                break;
            }
        }

        return $this->pages->render($request, $session, $identity, 'admin', 'Backups/backups', $extra + [
            'settings' => $settings,
            'settings_form' => $settings,
            'settings_errors' => [],
            'destinations' => $destinations,
            'destination_names' => $names,
            'types' => Destinations::TYPES,
            'running' => $running,
            'progress' => $running === null ? null : $this->runs->progress(),
            'runs' => $recent,
            'last' => $last,
            'accounts' => \count($this->accounts->withSites()),
            'snapshot_account' => $account,
            'snapshots' => $snapshots,
            'next' => $settings['enabled'] ? $this->next($settings['time']) : null,
            'add_errors' => [],
            'add_why' => null,
            'add_old' => ['type' => 'local', 'scheme' => 'https', 'port' => '22'],
            'add_url' => $this->router->url('admin.backups.add'),
            'settings_url' => $this->router->url('admin.backups.settings'),
            'run_url' => $this->router->url('admin.backups.run'),
            'page_url' => $this->router->url('admin.backups'),
            'overview_url' => $this->router->url('admin.backups.overview'),
            'snapshot_restore_url' => $account !== '' && \is_array($snapshots) ? $this->router->url('admin.backups.account', ['username' => $account]) : '',
        ], title: 'Backups.title', active: 'backups', status: $status);
    }

    /** When the schedule next starts, in the server's time zone. */
    private function next(string $time): string
    {
        $now = new \DateTimeImmutable();
        [$h, $m] = \array_map('intval', \explode(':', $time));
        $at = $now->setTime($h, $m);

        if ($at <= $now) {
            $at = $at->modify('+1 day');
        }

        return $at->format('Y-m-d H:i T');
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        $input = [];

        foreach (self::FIELDS as $field) {
            $v = $request->input($field);

            if (\is_string($v)) {
                $input[$field] = $v;
            }
        }

        return $input;
    }

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        return (\ctype_digit($id) ? $this->destinations->find((int) $id) : null) ?? throw HttpException::notFound();
    }

    private function back(): RedirectResponse
    {
        return new RedirectResponse($this->router->url('admin.backups'), 303);
    }
}
