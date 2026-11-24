<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Session\Session;
use App\Modules\Operations\Updates\Updates;
use App\Modules\Operations\Updates\UpdateSettings;
use App\Modules\Panel\Ui\PanelPage;

/** The Admin panel's Updates page (server.updates, root only): what is waiting, installing it, LPanel's releases, rebooting. */
final class UpdatePages
{
    public function __construct(
        private readonly Updates $updates,
        private readonly UpdateSettings $settings,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    public function index(Request $request, Session $session, Identity $identity): Response
    {
        $this->updates->settle();
        $latest = $this->updates->latest();
        $packages = $latest['packages'] ?? [];
        \usort($packages, static fn(array $a, array $b): int => [($b['kind'] ?? '') === 'security', (string) $a['name']] <=> [($a['kind'] ?? '') === 'security', (string) $b['name']]);
        $busy = $this->updates->busy();

        return $this->pages->render($request, $session, $identity, 'admin', 'Operations/updates', [
            'latest' => $latest,
            'packages' => $packages,
            'installed' => $this->updates->installed(),
            'busy' => $busy,
            'runs' => $this->updates->runs(15),
            'settings' => $this->settings->get(),
            'oses' => UpdateSettings::OS,
            'panels' => UpdateSettings::PANEL,
            'urls' => [
                'check' => $this->router->url('admin.updates.check'),
                'apply' => $this->router->url('admin.updates.apply'),
                'panel' => $this->router->url('admin.updates.panel'),
                'reboot' => $this->router->url('admin.updates.reboot'),
                'settings' => $this->router->url('admin.updates.settings'),
            ],
            // While something runs, the page looks again by itself.
            'refresh' => $busy !== null,
        ], title: 'Operations.updates_title', active: 'updates');
    }

    public function check(Request $request, Session $session, Identity $identity): Response
    {
        [, $why] = $this->updates->check($identity, $request);

        return $this->back($session, $why, 'Operations.updates_checking');
    }

    public function apply(Request $request, Session $session, Identity $identity): Response
    {
        $scope = (string) $request->input('scope', 'all');
        $packages = $request->input('packages', []);
        [, $why] = $this->updates->apply($identity, $request, $scope, \is_array($packages) ? \array_values(\array_filter($packages, 'is_string')) : []);

        return $this->back($session, $why, 'Operations.updates_applying');
    }

    public function panel(Request $request, Session $session, Identity $identity): Response
    {
        [, $why] = $this->updates->upgrade($identity, $request, (string) $request->input('version', ''));

        return $this->back($session, $why, 'Operations.updates_upgrading');
    }

    public function reboot(Request $request, Session $session, Identity $identity): Response
    {
        $cancel = $request->input('cancel') === '1';

        if (!$cancel && $request->input('confirm') !== 'reboot') {
            return $this->back($session, 'Operations.updates_reboot_confirm', '');
        }

        [$ok, $why] = $this->updates->reboot($identity, $request, $cancel);

        return $this->back($session, $ok ? null : $why, $cancel ? 'Operations.updates_reboot_cancelled' : 'Operations.updates_rebooting');
    }

    public function saveSettings(Request $request, Session $session, Identity $identity): Response
    {
        [$s, $errors] = $this->settings->validate([
            'os' => $request->input('os'),
            'php' => $request->input('php') === '1',
            'hour' => $request->input('hour'),
            'exclude' => (string) $request->input('exclude', ''),
            'panel' => $request->input('panel'),
            'reboot' => $request->input('reboot') === '1',
        ]);

        if ($errors !== []) {
            return $this->back($session, (string) \reset($errors), '');
        }

        $this->settings->save($s, $identity, $request);

        return $this->back($session, null, 'Operations.updates_settings_saved');
    }

    private function back(Session $session, ?string $why, string $done): Response
    {
        PanelPage::notice($session, $why === null ? 'success' : 'error', $why ?? $done);

        return new RedirectResponse($this->router->url('admin.updates'), 303);
    }
}
