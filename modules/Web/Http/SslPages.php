<?php

declare(strict_types=1);

namespace App\Modules\Web\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Session\Session;
use App\Modules\Panel\Ui\PanelPage;
use App\Modules\Subusers\Subusers;
use App\Modules\Web\AutoSsl;
use App\Modules\Web\Domains;
use App\Modules\Web\SslSites;

/**
 * "SSL/TLS" in both panels.
 *
 * User panel: each site's certificate, AutoSSL and forcing HTTPS per site,
 * ordering now, bringing a certificate, and making a request (CSR) for one.
 *
 * Admin panel: AutoSSL's settings (authorities, ZeroSSL's credentials), the
 * hostname's certificate, and every site's certificate on the server.
 */
final class SslPages
{
    /** Shown as expiring this close to the end. */
    private const WARN_DAYS = 14;

    public function __construct(
        private readonly AutoSsl $ssl,
        private readonly SslSites $sites,
        private readonly Domains $domains,
        private readonly Subusers $subusers,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    // -------------------------------------------------------------------------
    // User panel

    public function index(Request $request, Session $session, Identity $identity): Response
    {
        return $this->user($request, $session, $identity);
    }

    public function issue(Request $request, Session $session, Identity $identity, string $id): Response
    {
        [$account, $domain] = $this->site($identity, $id);
        [$order, $why] = $this->ssl->issueNow($identity, $request, $account, $domain);

        if ($why !== null) {
            PanelPage::notice($session, 'error', $why);
        } elseif ($order !== null && $order['state'] === AutoSsl::DONE) {
            PanelPage::notice($session, 'success', 'Web.ssl_issued');
        } elseif ($order !== null && $order['state'] === AutoSsl::FAILED) {
            PanelPage::notice($session, 'error', 'Web.ssl_issue_failed');
        }

        return $this->toUser();
    }

    public function options(Request $request, Session $session, Identity $identity, string $id): Response
    {
        [$account, $domain] = $this->site($identity, $id);
        $field = (string) $request->input('field', '');
        $on = $request->input('value') === '1';
        $outcome = $this->ssl->setOptions($identity, $request, $account, $domain, $field === 'autossl' ? $on : null, $field === 'force' ? $on : null);
        DomainPages::notify($session, $outcome, 'Web.saved');

        return $this->toUser();
    }

    public function remove(Request $request, Session $session, Identity $identity, string $id): Response
    {
        [$account, $domain] = $this->site($identity, $id);
        DomainPages::notify($session, $this->ssl->remove($identity, $request, $account, $domain), 'Web.ssl_removed');

        return $this->toUser();
    }

    public function install(Request $request, Session $session, Identity $identity): Response
    {
        [$account, $domain] = $this->site($identity, (string) $request->input('domain', ''));
        $chain = (string) $request->input('certificate', '');
        [$ok, $why, $detail] = $this->ssl->install($identity, $request, $account, $domain, (string) $request->input('key', ''), $chain);

        if (!$ok) {
            // The key is never sent back to the browser.
            return $this->user($request, $session, $identity, [
                'install_error' => $why, 'install_detail' => $detail,
                'old' => ['domain' => (int) $domain['id'], 'certificate' => $chain],
            ], 422);
        }

        PanelPage::notice($session, 'success', 'Web.ssl_installed');

        return $this->toUser();
    }

    public function csr(Request $request, Session $session, Identity $identity): Response
    {
        [$account, $domain] = $this->site($identity, (string) $request->input('domain', ''));
        $subject = [];

        foreach (['key_type', 'country', 'state', 'locality', 'organization'] as $field) {
            $subject[$field] = (string) $request->input($field, '');
        }

        [$csr, $why, $detail] = $this->ssl->csr($identity, $request, $account, $domain, $subject);

        return $this->user($request, $session, $identity, [
            'csr' => $csr, 'csr_for' => $domain['name'], 'csr_error' => $why, 'csr_detail' => $detail,
            'old' => ['csr_domain' => (int) $domain['id']] + $subject,
        ], $csr === null ? 422 : 200);
    }

    /** @param array<string, mixed> $extra */
    private function user(Request $request, Session $session, Identity $identity, array $extra = [], int $status = 200): Response
    {
        $account = $this->subusers->accountOf($identity) ?? throw HttpException::notFound();
        $this->ssl->settleAll();
        $ssl = $this->sites->forAccount((int) $account['id']);
        $rows = [];
        $busy = false;

        foreach ($this->ssl->namesOf($account) as $domainId => $names) {
            $domain = $this->domains->find((int) $account['id'], $domainId);

            if ($domain === null) {
                continue;
            }

            $s = $ssl[$domainId] ?? null;
            $running = $this->ssl->runningFor($domainId) !== null;
            $busy = $busy || $running;
            $rows[] = $this->present($domain, $s, $names) + [
                'running' => $running,
                'issue_url' => $this->router->url('user.ssl.issue', ['id' => $domainId]),
                'options_url' => $this->router->url('user.ssl.options', ['id' => $domainId]),
                'remove_url' => $this->router->url('user.ssl.remove', ['id' => $domainId]),
            ];
        }

        return $this->pages->render($request, $session, $identity, 'user', '@Web/ssl', $extra + [
            'account' => $account,
            'sites' => $rows,
            'busy' => $busy,
            'autossl_on' => $this->ssl->settings()['enabled'] && $this->ssl->authorities() !== [],
            'csr' => null, 'csr_for' => null, 'csr_error' => null, 'csr_detail' => null,
            'install_error' => null, 'install_detail' => null,
            'install_url' => $this->router->url('user.ssl.install'),
            'csr_url' => $this->router->url('user.ssl.csr'),
            'old' => [],
        ], title: 'Web.ssl', active: 'ssl', status: $status);
    }

    /**
     * A site's certificate as both panels show it.
     *
     * @param array<string, mixed> $domain
     * @param array<string, mixed>|null $s
     * @param list<string> $names
     *
     * @return array<string, mixed>
     */
    private function present(array $domain, ?array $s, array $names): array
    {
        $now = $this->ssl->now();
        $after = $s !== null && $s['not_after'] !== null ? (int) \strtotime($s['not_after'] . ' UTC') : null;
        $days = $after === null ? null : (int) \floor(($after - $now) / 86400);
        $state = match (true) {
            $s === null || $s['cert_id'] === null => 'none',
            $after <= $now => 'expired',
            (bool) $s['self_signed'] => 'self_signed',
            $days < self::WARN_DAYS => 'expiring',
            default => 'ok',
        };

        return [
            'id' => (int) $domain['id'],
            'name' => (string) $domain['name'],
            'kind' => (string) $domain['kind'],
            'names' => $names,
            'state' => $state,
            'days' => $days,
            'uncovered' => $s !== null && $s['cert_id'] !== null ? \array_values(\array_filter($names, static fn(string $n): bool => !AutoSsl::covers((array) $s['names'], $n))) : [],
            'ssl' => $s,
            'autossl' => $s === null || (bool) $s['autossl'],
            'force' => $s === null || (bool) $s['force_https'],
        ];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function site(Identity $identity, string $id): array
    {
        $account = $this->subusers->accountOf($identity) ?? throw HttpException::notFound();
        $domain = \ctype_digit($id) ? $this->domains->find((int) $account['id'], (int) $id) : null;

        if ($domain === null || $domain['kind'] === Domains::ALIAS) {
            throw HttpException::notFound();
        }

        return [$account, $domain];
    }

    private function toUser(): Response
    {
        return new RedirectResponse($this->router->url('user.ssl'), 303);
    }

    // -------------------------------------------------------------------------
    // Admin panel

    public function admin(Request $request, Session $session, Identity $identity): Response
    {
        return $this->adminPage($request, $session, $identity);
    }

    public function saveSettings(Request $request, Session $session, Identity $identity): Response
    {
        $input = [
            'enabled' => $request->input('enabled') === '1',
            'fallback' => $request->input('fallback') === '1',
            'host_autossl' => $request->input('host_autossl') === '1',
            'primary' => (string) $request->input('primary', AutoSsl::LETSENCRYPT),
            'eab_kid' => (string) $request->input('eab_kid', ''),
            'eab_hmac' => (string) $request->input('eab_hmac', ''),
            'hostname' => (string) $request->input('hostname', ''),
        ];
        $errors = $this->ssl->saveSettings($identity, $request, $input);

        if ($errors !== []) {
            unset($input['eab_hmac']);

            return $this->adminPage($request, $session, $identity, ['errors' => $errors, 'old' => $input], 422);
        }

        PanelPage::notice($session, 'success', 'Web.saved');

        return $this->toAdmin();
    }

    public function run(Request $request, Session $session, Identity $identity): Response
    {
        $r = $this->ssl->run();
        PanelPage::notice($session, 'success', $r['started'] > 0 ? 'Web.ssl_run_started' : 'Web.ssl_run_nothing');

        return $this->toAdmin();
    }

    public function hostIssue(Request $request, Session $session, Identity $identity): Response
    {
        [$order, $why] = $this->ssl->hostIssueNow($identity, $request);

        if ($why !== null) {
            PanelPage::notice($session, 'error', $why);
        } elseif ($order !== null && $order['state'] === AutoSsl::DONE) {
            PanelPage::notice($session, 'success', 'Web.ssl_host_issued');
        }

        return $this->toAdmin();
    }

    public function hostInstall(Request $request, Session $session, Identity $identity): Response
    {
        $chain = (string) $request->input('certificate', '');
        [$ok, $why, $detail] = $this->ssl->hostInstall($identity, $request, (string) $request->input('key', ''), $chain);

        if (!$ok) {
            return $this->adminPage($request, $session, $identity, ['host_error' => $why, 'host_detail' => $detail, 'old_chain' => $chain], 422);
        }

        PanelPage::notice($session, 'success', 'Web.ssl_host_installed');

        return $this->toAdmin();
    }

    /** @param array<string, mixed> $extra */
    private function adminPage(Request $request, Session $session, Identity $identity, array $extra = [], int $status = 200): Response
    {
        $this->ssl->settleAll();
        $settings = $this->ssl->settings();
        $host = $this->ssl->hostInfo();
        $now = $this->ssl->now();
        $hostCert = $host['cert'] ?? null;
        $hostDays = $hostCert !== null ? (int) \floor(((int) $hostCert['not_after'] - $now) / 86400) : null;
        $certs = [];

        foreach ($this->sites->all() as $s) {
            $after = $s['not_after'] !== null ? (int) \strtotime($s['not_after'] . ' UTC') : null;
            $certs[] = $s + [
                'days' => $after === null ? null : (int) \floor(($after - $now) / 86400),
                'state' => match (true) {
                    $s['cert_id'] === null => $s['last_error'] !== null ? 'failed' : 'none',
                    $after <= $now => 'expired',
                    (bool) $s['self_signed'] => 'self_signed',
                    ($after - $now) < self::WARN_DAYS * 86400 => 'expiring',
                    default => 'ok',
                },
            ];
        }

        $orders = \array_map(static function (array $o): array {
            $args = \json_decode((string) $o['op_args'], true);

            return $o + ['target' => (string) (\is_array($args) ? ($args['names'][0] ?? '') : '')];
        }, $this->ssl->recentOrders(12));
        $running = \array_filter($orders, static fn(array $o): bool => $o['state'] === AutoSsl::RUNNING);

        return $this->pages->render($request, $session, $identity, 'admin', '@Web/ssl-admin', $extra + [
            'settings' => $settings,
            'authorities' => $this->ssl->authorities(),
            'reachable' => $host !== null,
            'host' => $hostCert,
            'host_days' => $hostDays,
            'host_running' => \array_filter($running, static fn(array $o): bool => $o['kind'] === 'host') !== [],
            'host_due' => $this->ssl->hostDue(),
            'host_error' => $extra['host_error'] ?? null,
            'host_detail' => $extra['host_detail'] ?? null,
            'host_last_error' => $this->ssl->settings()['host_autossl'] ? (string) ($this->hostLastError() ?? '') : '',
            'certs' => $certs,
            'orders' => $orders,
            'busy' => $running !== [],
            'errors' => [],
            'old' => [],
            'settings_url' => $this->router->url('admin.ssl.settings'),
            'run_url' => $this->router->url('admin.ssl.run'),
            'host_issue_url' => $this->router->url('admin.ssl.host_issue'),
            'host_install_url' => $this->router->url('admin.ssl.host_install'),
        ], title: 'Web.ssl', active: 'ssl', status: $status);
    }

    private function hostLastError(): ?string
    {
        foreach ($this->ssl->recentOrders(20) as $o) {
            if ($o['kind'] === 'host') {
                return $o['state'] === AutoSsl::FAILED ? (string) $o['error'] : null;
            }
        }

        return null;
    }

    private function toAdmin(): Response
    {
        return new RedirectResponse($this->router->url('admin.ssl'), 303);
    }
}
