<?php

declare(strict_types=1);

namespace App\Modules\Security\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Session\Session;
use App\Modules\Panel\Ui\PanelPage;
use App\Modules\Security\Firewall\Cidr;
use App\Modules\Security\Firewall\Countries;
use App\Modules\Security\Firewall\Entries;
use App\Modules\Security\Firewall\Firewall;

/** The Admin panel's firewall page: ports, the allow and deny lists, blocked countries. */
final class FirewallPages
{
    public function __construct(
        private readonly Firewall $firewall,
        private readonly Entries $entries,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    public function index(Request $request, Session $session, Identity $identity): Response
    {
        $info = $this->firewall->info();
        $mine = $request->ip();
        $blocked = $this->entries->countries();
        $cached = [];

        foreach ($info['cached'] ?? [] as $c) {
            $cached[(string) $c['code']] = $c;
        }

        $url = fn(string $name, array $params = []): string => $this->router->url('admin.firewall' . $name, $params);
        $rows = fn(string $list): array => \array_map(
            static fn(array $r): array => $r + ['delete_url' => $url('.entries.delete', ['id' => (int) $r['id']])],
            $this->entries->all($list),
        );

        return $this->pages->render($request, $session, $identity, 'admin', 'Security/firewall', [
            'info' => $info,
            'allow' => $rows(Entries::ALLOW),
            'deny' => $rows(Entries::DENY),
            'blocked' => $blocked,
            'cached' => $cached,
            'fetched_at' => $this->entries->setting('countries_fetched_at'),
            'names' => Countries::NAMES,
            'durations' => \array_keys(Firewall::DURATIONS),
            'mine' => $mine,
            'urls' => [
                'ports' => $url('.ports'), 'close' => $url('.ports.close'), 'entries' => $url('.entries'), 'countries' => $url('.countries'),
            ],
        ], title: 'Security.fw_title', active: 'firewall');
    }

    public function openPort(Request $request, Session $session, Identity $identity): Response
    {
        $only = $request->input('scope') === 'only';
        $sources = $only ? Cidr::split((string) $request->input('sources')) : [];

        if ($only && $sources === []) {
            return $this->done($session, false, 'Security.fw_error_no_sources');
        }

        [$ok, $why] = $this->firewall->openPort($identity, $request, \trim((string) $request->input('port')), (string) $request->input('proto'), $sources);

        return $this->done($session, $ok, $why, 'Security.fw_port_saved');
    }

    public function closePort(Request $request, Session $session, Identity $identity): Response
    {
        [$ok, $why] = $this->firewall->closePort($identity, $request, \trim((string) $request->input('port')), (string) $request->input('proto'));

        return $this->done($session, $ok, $why, 'Security.fw_port_closed');
    }

    public function addEntry(Request $request, Session $session, Identity $identity): Response
    {
        $list = (string) $request->input('list');
        $added = 0;

        foreach (Cidr::split((string) $request->input('address')) as $address) {
            [$ok, $why] = $this->firewall->addEntry($identity, $request, $list, $address, (string) $request->input('note'), (string) ($request->input('duration') ?: 'never'));

            if (!$ok) {
                return $this->done($session, false, $why);
            }

            $added++;
        }

        return $added === 0 ? $this->done($session, false, 'Security.fw_error_address') : $this->done($session, true, null, $list === Entries::ALLOW ? 'Security.fw_allowed' : 'Security.fw_denied');
    }

    public function removeEntry(Request $request, Session $session, Identity $identity, string $id): Response
    {
        [$ok, $why] = $this->firewall->removeEntry($identity, $request, (int) $id);

        return $this->done($session, $ok, $why, 'Security.fw_removed');
    }

    public function countries(Request $request, Session $session, Identity $identity): Response
    {
        $codes = $request->input('codes');
        [$ok, $why] = $this->firewall->setCountries($identity, $request, \is_array($codes) ? \array_values($codes) : [], $request->input('refresh') === '1');

        return $this->done($session, $ok, $why, 'Security.fw_countries_saved');
    }

    private function done(Session $session, bool $ok, ?string $why, string $success = ''): Response
    {
        PanelPage::notice($session, $ok ? 'success' : 'error', $ok ? $success : (string) $why);

        return new RedirectResponse($this->router->url('admin.firewall'), 303);
    }
}
