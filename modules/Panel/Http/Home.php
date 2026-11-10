<?php

declare(strict_types=1);

namespace App\Modules\Panel\Http;

use App\Engine\Auth\Identity;
use App\Engine\Filter\FilterEngine;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Session\Session;
use App\Modules\Panel\Ui\PanelPage;

/**
 * The front door and the first page of each interface.
 *
 * Both dashboards are a list of cards other modules contribute through the
 * `panel.dashboard` filter, so a module adds its summary without Panel knowing
 * it exists.
 */
final class Home
{
    public function __construct(
        private readonly PanelPage $pages,
        private readonly Router $router,
        private readonly FilterEngine $filters,
    ) {}

    /** "/": the Admin panel. On a server the User panel's port rewrites "/" to /panel. */
    public function root(): RedirectResponse
    {
        return new RedirectResponse($this->router->url('admin.dashboard'), 302);
    }

    public function admin(Request $request, Session $session, Identity $identity): Response
    {
        return $this->dashboard($request, $session, $identity, 'admin');
    }

    public function user(Request $request, Session $session, Identity $identity): Response
    {
        return $this->dashboard($request, $session, $identity, 'user');
    }

    /** @param 'admin'|'user' $area */
    private function dashboard(Request $request, Session $session, Identity $identity, string $area): Response
    {
        $cards = $this->filters->apply('panel.dashboard', [], $area, $identity);
        $cards = \array_values(\array_filter(
            \is_array($cards) ? $cards : [],
            static fn(mixed $card): bool => \is_array($card) && \is_string($card['template'] ?? null),
        ));
        \usort($cards, static fn(array $a, array $b): int => ($a['order'] ?? 100) <=> ($b['order'] ?? 100));

        return $this->pages->render($request, $session, $identity, $area, '@Panel/dashboard', [
            'cards' => $cards,
        ], title: 'Panel.dashboard', active: 'dashboard');
    }
}
