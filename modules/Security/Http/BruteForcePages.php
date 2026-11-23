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
use App\Modules\Security\BruteForce\BruteForce;

/** The Admin panel's brute-force page: the settings, the busiest addresses, the latest blocks. */
final class BruteForcePages
{
    public function __construct(
        private readonly BruteForce $bruteForce,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    public function index(Request $request, Session $session, Identity $identity): Response
    {
        return $this->pages->render($request, $session, $identity, 'admin', 'Security/bruteforce', [
            'settings' => $this->bruteForce->settings(),
            'overview' => $this->bruteForce->overview(),
            'ladder' => BruteForce::LADDER,
            'services' => BruteForce::SERVICES,
            'save_url' => $this->router->url('admin.bruteforce.save'),
            'deny_url' => $this->router->url('admin.firewall'),
        ], title: 'Security.bf_title', active: 'bruteforce');
    }

    public function save(Request $request, Session $session, Identity $identity): Response
    {
        $why = $this->bruteForce->configure($identity, $request, [
            'enabled' => $request->input('enabled') === '1',
            'threshold' => $request->input('threshold'),
            'window' => $request->input('window'),
            'duration' => $request->input('duration'),
        ]);
        PanelPage::notice($session, $why === null ? 'success' : 'error', $why ?? 'Security.bf_saved');

        return new RedirectResponse($this->router->url('admin.bruteforce'), 303);
    }
}
