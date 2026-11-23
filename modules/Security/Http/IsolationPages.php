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
use App\Modules\Security\Isolation\Isolation;

/** The Admin panel's account isolation page. */
final class IsolationPages
{
    public function __construct(
        private readonly Isolation $isolation,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    public function index(Request $request, Session $session, Identity $identity): Response
    {
        return $this->pages->render($request, $session, $identity, 'admin', 'Security/isolation', [
            'info' => $this->isolation->info(),
            'settings' => $this->isolation->settings(),
            'save_url' => $this->router->url('admin.isolation.save'),
        ], title: 'Security.iso_title', active: 'isolation');
    }

    public function save(Request $request, Session $session, Identity $identity): Response
    {
        [$ok, $why] = $this->isolation->configure($identity, $request, [
            'hidepid' => $request->input('hidepid') === '1',
            'links' => $request->input('links') === '1',
        ]);
        PanelPage::notice($session, $ok ? 'success' : 'error', $ok ? 'Security.iso_saved' : (string) $why);

        return new RedirectResponse($this->router->url('admin.isolation'), 303);
    }
}
