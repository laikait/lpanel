<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Session\Session;
use App\Modules\Operations\Services\Services;
use App\Modules\Panel\Ui\PanelPage;

/** The Admin panel's services page: how each service is doing, and starting and stopping them. */
final class ServicePages
{
    public function __construct(
        private readonly Services $services,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    public function index(Request $request, Session $session, Identity $identity): Response
    {
        $list = $this->services->list();
        $groups = [];

        foreach ($list ?? [] as $s) {
            $groups[(string) $s['group']][] = $s + [
                'act_urls' => \array_combine((array) $s['allowed'], \array_map(fn(string $action): string => $this->router->url('admin.services.act', ['unit' => $s['unit'], 'action' => $action]), (array) $s['allowed'])),
                'watch_url' => $this->router->url('admin.services.watch', ['unit' => $s['unit']]),
            ];
        }

        $running = \count(\array_filter($list ?? [], static fn(array $s): bool => $s['running']));
        $down = \count(\array_filter($list ?? [], static fn(array $s): bool => !$s['running'] && $s['expected'] && $s['watch']));

        return $this->pages->render($request, $session, $identity, 'admin', 'Operations/services', [
            'available' => $list !== null,
            'groups' => $groups,
            'total' => \count($list ?? []),
            'running' => $running,
            'down' => $down,
            'events' => $this->services->events(25),
            'restarts_day' => $this->restartsToday(),
        ], title: 'Operations.services_title', active: 'services');
    }

    public function act(Request $request, Session $session, Identity $identity, string $unit, string $action): Response
    {
        [$ok, $why] = $this->services->act($identity, $request, $unit, $action);
        PanelPage::notice($session, $ok ? 'success' : 'error', $ok ? 'Operations.done_' . $action : (string) $why);

        return new RedirectResponse($this->router->url('admin.services'), 303);
    }

    public function watch(Request $request, Session $session, Identity $identity, string $unit): Response
    {
        $this->services->setWatch($identity, $request, $unit, $request->input('watch') === '1');
        PanelPage::notice($session, 'success', 'Operations.watch_saved');

        return new RedirectResponse($this->router->url('admin.services'), 303);
    }

    private function restartsToday(): int
    {
        $n = 0;

        foreach ($this->services->events(200) as $e) {
            if ($e['kind'] === 'restarted' && (string) $e['at'] >= \gmdate('Y-m-d H:i:s', \time() - 86400)) {
                $n++;
            }
        }

        return $n;
    }
}
