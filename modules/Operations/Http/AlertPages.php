<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Session\Session;
use App\Modules\Operations\Alerts\AlertChannels;
use App\Modules\Operations\Alerts\Alerts;
use App\Modules\Operations\Alerts\AlertSettings;
use App\Modules\Panel\Ui\PanelPage;

/** The Admin panel's Alerts page (server.alerts, root only): where alerts go, when the server speaks up, what was said. */
final class AlertPages
{
    public function __construct(
        private readonly AlertChannels $channels,
        private readonly Alerts $alerts,
        private readonly AlertSettings $settings,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    public function index(Request $request, Session $session, Identity $identity): Response
    {
        $history = $this->alerts->history(40);

        return $this->pages->render($request, $session, $identity, 'admin', 'Operations/alerts', [
            'channels' => \array_map(fn(array $c): array => $c + [
                'change_url' => $this->router->url('admin.alerts.change', ['id' => $c['id']]),
                'delete_url' => $this->router->url('admin.alerts.delete', ['id' => $c['id']]),
                'test_url' => $this->router->url('admin.alerts.test', ['id' => $c['id']]),
                'cats' => $c['categories'] === '*' ? [] : explode(' ', $c['categories']),
            ], $this->channels->all()),
            'history' => $history,
            'settings' => $this->settings->get(),
            'categories' => AlertChannels::CATEGORIES,
            'severities' => AlertChannels::SEVERITIES,
            'urls' => [
                'add' => $this->router->url('admin.alerts.add'),
                'settings' => $this->router->url('admin.alerts.settings'),
            ],
        ], title: 'Operations.alerts_title', active: 'alerts');
    }

    public function add(Request $request, Session $session, Identity $identity): Response
    {
        [$c, $errors] = $this->channels->validate(self::input($request, ['kind', 'name', 'to', 'token', 'chat', 'url', 'signing_key']) + ['categories' => $request->input('categories', [])]);

        if ($errors !== []) {
            return $this->back($session, (string) \reset($errors), '');
        }

        $this->channels->create($c, $identity, $request);

        return $this->back($session, null, 'Operations.alerts_added');
    }

    public function change(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $existing = $this->channels->open((int) $id);

        if ($existing === null) {
            return $this->back($session, 'Operations.alerts_error_missing', '');
        }

        [$c, $errors] = $this->channels->validate(['categories' => $request->input('categories', []), 'min_severity' => $request->input('min_severity'), 'enabled' => $request->input('enabled') === '1'], $existing);

        if ($errors !== []) {
            return $this->back($session, (string) \reset($errors), '');
        }

        $c['name'] = (string) $existing['name'];
        $this->channels->update((int) $id, $c, $identity, $request);

        return $this->back($session, null, 'Operations.alerts_changed');
    }

    public function delete(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $ok = $this->channels->remove((int) $id, $identity, $request);

        return $this->back($session, $ok ? null : 'Operations.alerts_error_missing', 'Operations.alerts_removed');
    }

    public function test(Request $request, Session $session, Identity $identity, string $id): Response
    {
        [$ok, $why] = $this->alerts->test((int) $id, $identity, $request);

        if ($ok) {
            return $this->back($session, null, 'Operations.alerts_test_sent');
        }

        PanelPage::notice($session, 'error', \str_starts_with($why, 'Operations.') ? $why : 'The test did not arrive: ' . $why);

        return new RedirectResponse($this->router->url('admin.alerts'), 303);
    }

    public function saveSettings(Request $request, Session $session, Identity $identity): Response
    {
        [$s, $errors] = $this->settings->validate(self::input($request, ['disk', 'memory', 'load', 'ssl', 'quiet']));

        if ($errors !== []) {
            return $this->back($session, (string) \reset($errors), '');
        }

        $this->settings->save($s, $identity, $request);

        return $this->back($session, null, 'Operations.alerts_settings_saved');
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, mixed>
     */
    private static function input(Request $request, array $names): array
    {
        $out = [];

        foreach ($names as $n) {
            $v = $request->input($n);

            if ($v !== null) {
                $out[$n] = $v;
            }
        }

        return $out;
    }

    private function back(Session $session, ?string $why, string $done): Response
    {
        PanelPage::notice($session, $why === null ? 'success' : 'error', $why ?? $done);

        return new RedirectResponse($this->router->url('admin.alerts'), 303);
    }
}
