<?php

declare(strict_types=1);

namespace App\Modules\Web\Waf;

use App\Engine\Auth\Identity;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Session\Session;
use App\Modules\Panel\Ui\PanelPage;

/** The Admin panel's web application firewall page: the server's default mode, how strict the rules are, and what they said. */
final class WafPages
{
    public function __construct(
        private readonly Waf $waf,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    public function index(Request $request, Session $session, Identity $identity): Response
    {
        return $this->pages->render($request, $session, $identity, 'admin', 'Web/waf', [
            'info' => $this->waf->info(),
            'settings' => $this->waf->settings(),
            'modes' => Waf::MODES,
            'events' => $this->waf->recent(limit: 25),
            'counts' => $this->waf->counts(),
            'save_url' => $this->router->url('admin.waf.save'),
        ], title: 'Web.waf_title', active: 'waf');
    }

    public function save(Request $request, Session $session, Identity $identity): Response
    {
        $why = $this->waf->setDefault($identity, $request, (string) $request->input('mode'));
        $ok = $why === null;

        if ($ok) {
            [$ok, $why] = $this->waf->setParanoia($identity, $request, (int) $request->input('paranoia'));
        }

        PanelPage::notice($session, $ok ? 'success' : 'error', $ok ? 'Web.waf_saved' : (string) $why);

        return new RedirectResponse($this->router->url('admin.waf'), 303);
    }
}
