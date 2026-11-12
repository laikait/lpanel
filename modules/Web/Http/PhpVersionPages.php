<?php

declare(strict_types=1);

namespace App\Modules\Web\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Session\Session;
use App\Modules\Panel\Ui\PanelPage;
use App\Modules\Web\PhpVersions;
use App\Modules\Web\WebSync;

/**
 * PHP versions in the Admin panel: what is installed, its extensions, the
 * version new sites get, and installing or removing a version.
 */
final class PhpVersionPages
{
    public function __construct(
        private readonly PhpVersions $php,
        private readonly PanelPage $pages,
        private readonly Router $router,
        private readonly WebSync $sync,
    ) {}

    public function index(Request $request, Session $session, Identity $identity): Response
    {
        // Every look asks the agent about a job still running.
        $this->php->settleAll();
        $info = $this->php->info();
        $sites = $this->php->sitesByVersion();
        $default = $this->php->defaultVersion();
        $installed = [];
        $available = [];

        foreach ($info['versions'] ?? [] as $v) {
            $version = (string) $v['version'];
            $row = $v + [
                'label' => WebSync::phpLabel($version),
                'sites' => $sites[$version] ?? 0,
                'default' => $version === $default,
                'eol' => \in_array($version, PhpVersions::END_OF_LIFE, true),
                'install_url' => $this->router->url('admin.php.install', ['version' => $version]),
                'remove_url' => $this->router->url('admin.php.remove', ['version' => $version]),
                'extensions_url' => $this->router->url('admin.php.extensions', ['version' => $version]),
            ];

            if ($v['installed']) {
                $row['keeps'] = match (true) {
                    (bool) $v['panel'] => 'Web.php_keeps_panel',
                    $row['sites'] > 0 || (int) $v['pools'] > 0 => 'Web.php_keeps_sites',
                    $row['default'] => 'Web.php_keeps_default',
                    default => null,
                };
                $installed[] = $row;
            } else {
                $available[] = $row;
            }
        }

        $jobs = \array_map(static fn(array $j): array => $j + ['label' => WebSync::phpLabel((string) $j['version'])], $this->php->jobs());

        return $this->pages->render($request, $session, $identity, 'admin', '@Web/php-versions', [
            'reachable' => $info !== null,
            'installed' => \array_reverse($installed),
            'available' => \array_reverse($available),
            'extensions' => $info['extensions'] ?? [],
            'defaults' => $info['defaults'] ?? [],
            'default' => $default,
            'running' => $this->php->running(),
            'jobs' => $jobs,
            'default_url' => $this->router->url('admin.php.default'),
            'stack' => (string) ($this->sync->info()['server'] ?? ''),
        ], title: 'Web.php_versions', active: 'php_versions');
    }

    public function install(Request $request, Session $session, Identity $identity, string $version): Response
    {
        [$job, $why] = $this->php->install($identity, $request, $version);

        return $this->started($session, $job, $why);
    }

    public function remove(Request $request, Session $session, Identity $identity, string $version): Response
    {
        [$job, $why] = $this->php->remove($identity, $request, $version);

        return $this->started($session, $job, $why);
    }

    public function extensions(Request $request, Session $session, Identity $identity, string $version): Response
    {
        $wanted = $request->input('extensions', []);
        $wanted = \is_array($wanted) ? \array_values(\array_filter($wanted, 'is_string')) : [];
        // Locked ones are disabled checkboxes, so the browser does not send them.
        $locked = $this->php->version($version)['locked'] ?? [];
        $wanted = [...$wanted, ...\array_values(\array_filter(\is_array($locked) ? $locked : [], 'is_string'))];
        [$job, $why] = $this->php->extensions($identity, $request, $version, $wanted);

        return $this->started($session, $job, $why);
    }

    public function setDefault(Request $request, Session $session, Identity $identity): Response
    {
        $why = $this->php->setDefault($identity, $request, (string) $request->input('version', ''));

        return $this->back($session, $why, 'Web.php_default_saved');
    }

    /**
     * After starting a job: while it runs, the page's own progress banner
     * says so; a job the agent finished at once gets a notice; one that
     * failed shows its reason on the page.
     *
     * @param array<string, mixed>|null $job
     */
    private function started(Session $session, ?array $job, ?string $why): Response
    {
        if ($why !== null) {
            PanelPage::notice($session, 'error', $why);
        } elseif ($job !== null && $job['state'] === PhpVersions::DONE) {
            PanelPage::notice($session, 'success', 'Web.php_job_done');
        }

        return new RedirectResponse($this->router->url('admin.php'), 303);
    }

    private function back(Session $session, ?string $why, string $done): Response
    {
        PanelPage::notice($session, $why === null ? 'success' : 'error', $why ?? $done);

        return new RedirectResponse($this->router->url('admin.php'), 303);
    }
}
