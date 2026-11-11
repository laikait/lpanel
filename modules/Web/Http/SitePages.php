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
use App\Modules\Web\DomainActions;
use App\Modules\Web\Domains;
use App\Modules\Web\PhpSettings;
use App\Modules\Web\SiteFeatures;
use App\Modules\Web\WebOutcome;
use App\Modules\Web\WebSync;

/**
 * One domain in the User panel: its folder, PHP version and www. choice,
 * redirects, error pages, protected folders and hotlink protection. Also the
 * account's php.ini settings (the "PHP settings" page).
 *
 * A form that is refused renders the page again with its errors (422);
 * anything else redirects back to the page with a notice.
 */
final class SitePages
{
    public function __construct(
        private readonly Domains $domains,
        private readonly SiteFeatures $features,
        private readonly PhpSettings $php,
        private readonly DomainActions $actions,
        private readonly WebSync $sync,
        private readonly Subusers $subusers,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    public function show(Request $request, Session $session, Identity $identity, string $id): Response
    {
        [$account, $domain] = $this->domain($identity, $id);

        return $this->page($request, $session, $identity, $account, $domain);
    }

    public function general(Request $request, Session $session, Identity $identity, string $id): Response
    {
        [$account, $domain] = $this->domain($identity, $id);
        $input = [
            'docroot' => $request->input('docroot'),
            'php' => $request->input('php'),
            'wildcard' => $domain['kind'] === Domains::SUB ? null : $request->input('wildcard') === '1',
            'canonical' => $request->input('canonical'),
        ];
        [$outcome, $errors] = $this->actions->change($identity, $request, $account, $domain, $input);

        return $this->after($request, $session, $identity, $account, $domain, $outcome, $errors, 'general', $input);
    }

    public function addRedirect(Request $request, Session $session, Identity $identity, string $id): Response
    {
        [$account, $domain] = $this->domain($identity, $id);
        $input = ['path' => (string) $request->input('path', ''), 'to' => (string) $request->input('to', ''), 'code' => (string) $request->input('code', '301')];
        [$outcome, $errors] = $this->actions->addRedirect($identity, $request, $account, $domain, $input);

        return $this->after($request, $session, $identity, $account, $domain, $outcome, $errors, 'redirect', $input);
    }

    public function removeRedirect(Request $request, Session $session, Identity $identity, string $id, string $redirect): Response
    {
        [$account, $domain] = $this->domain($identity, $id);
        $row = $this->features->redirect((int) $domain['id'], $redirect) ?? throw HttpException::notFound();

        return $this->after($request, $session, $identity, $account, $domain, $this->actions->removeRedirect($identity, $request, $account, $domain, $row), [], 'redirect', []);
    }

    public function errorPages(Request $request, Session $session, Identity $identity, string $id): Response
    {
        [$account, $domain] = $this->domain($identity, $id);
        $input = [];

        foreach (SiteFeatures::ERROR_CODES as $code) {
            $input[$code] = (string) $request->input('page_' . $code, '');
        }

        [$outcome, $errors] = $this->actions->errorPages($identity, $request, $account, $domain, $input);

        return $this->after($request, $session, $identity, $account, $domain, $outcome, $errors, 'errors', $input);
    }

    public function hotlink(Request $request, Session $session, Identity $identity, string $id): Response
    {
        [$account, $domain] = $this->domain($identity, $id);
        $input = ['on' => $request->input('on') === '1', 'extensions' => (string) $request->input('extensions', ''), 'allow' => (string) $request->input('allow', '')];
        [$outcome, $errors] = $this->actions->hotlink($identity, $request, $account, $domain, $input['on'], $input['extensions'], $input['allow']);

        return $this->after($request, $session, $identity, $account, $domain, $outcome, $errors, 'hotlink', $input);
    }

    public function protect(Request $request, Session $session, Identity $identity, string $id): Response
    {
        [$account, $domain] = $this->domain($identity, $id);
        $input = [
            'path' => (string) $request->input('path', ''), 'realm' => (string) $request->input('realm', ''),
            'login' => (string) $request->input('login', ''), 'password' => (string) $request->input('password', ''),
        ];
        [$outcome, $errors] = $this->actions->protect($identity, $request, $account, $domain, $input);
        unset($input['password']);

        return $this->after($request, $session, $identity, $account, $domain, $outcome, $errors, 'protect', $input);
    }

    public function unprotect(Request $request, Session $session, Identity $identity, string $id, string $folder): Response
    {
        [$account, $domain] = $this->domain($identity, $id);
        $row = $this->features->protectedFolder((int) $domain['id'], $folder) ?? throw HttpException::notFound();

        return $this->after($request, $session, $identity, $account, $domain, $this->actions->unprotect($identity, $request, $account, $domain, $row), [], 'protect', []);
    }

    public function setLogin(Request $request, Session $session, Identity $identity, string $id, string $folder): Response
    {
        [$account, $domain] = $this->domain($identity, $id);
        $row = $this->features->protectedFolder((int) $domain['id'], $folder) ?? throw HttpException::notFound();
        [$outcome, $errors] = $this->actions->setLogin($identity, $request, $account, $domain, $row, (string) $request->input('login', ''), (string) $request->input('password', ''));

        return $this->after($request, $session, $identity, $account, $domain, $outcome, $errors, 'login_' . $row['id'], ['login' => (string) $request->input('login', '')]);
    }

    public function removeLogin(Request $request, Session $session, Identity $identity, string $id, string $folder, string $login): Response
    {
        [$account, $domain] = $this->domain($identity, $id);
        $row = $this->features->protectedFolder((int) $domain['id'], $folder) ?? throw HttpException::notFound();

        if (!\in_array($login, $row['logins'], true)) {
            throw HttpException::notFound();
        }

        return $this->after($request, $session, $identity, $account, $domain, $this->actions->removeLogin($identity, $request, $account, $domain, $row, $login), [], 'protect', []);
    }

    // ---- PHP settings ---------------------------------------------------------

    public function php(Request $request, Session $session, Identity $identity): Response
    {
        return $this->phpPage($request, $session, $identity);
    }

    public function savePhp(Request $request, Session $session, Identity $identity, string $version): Response
    {
        $account = $this->subusers->accountOf($identity) ?? throw HttpException::notFound();
        $input = [];

        foreach (\array_keys(PhpSettings::DIRECTIVES) as $directive) {
            $input[$directive] = (string) $request->input(self::field($directive), '');
        }

        [$outcome, $errors] = $this->actions->phpSettings($identity, $request, $account, $version, $input);

        if ($outcome === null) {
            return $this->phpPage($request, $session, $identity, ['errors' => [$version => $errors], 'old' => [$version => $input]], 422);
        }

        DomainPages::notify($session, $outcome, 'Web.php_saved');

        return new RedirectResponse($this->router->url('user.php'), 303);
    }

    /** Form field names cannot hold dots: date.timezone is date_timezone. */
    public static function field(string $directive): string
    {
        return \str_replace('.', '_', $directive);
    }

    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $domain
     * @param array<string, string> $errors
     * @param array<int|string, mixed> $input
     */
    private function after(Request $request, Session $session, Identity $identity, array $account, array $domain, ?WebOutcome $outcome, array $errors, string $form, array $input): Response
    {
        if ($outcome === null) {
            return $this->page($request, $session, $identity, $account, $domain, ['form' => $form, 'errors' => $errors, 'old' => $input], 422);
        }

        DomainPages::notify($session, $outcome, 'Web.saved');

        return new RedirectResponse($this->router->url('user.domains.show', ['id' => $domain['id']]) . '#' . \explode('_', $form)[0], 303);
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $domain
     * @param array<string, mixed> $extra
     */
    private function page(Request $request, Session $session, Identity $identity, array $account, array $domain, array $extra = [], int $status = 200): Response
    {
        $id = (int) $domain['id'];
        $url = fn(string $name, array $more = []): string => $this->router->url('user.domains.' . $name, ['id' => $id] + $more);
        $redirects = \array_map(static fn(array $r): array => $r + ['delete_url' => $url('redirects.delete', ['redirect' => $r['id']])], $this->features->redirects($id));
        $protected = [];

        foreach ($this->features->protected($id) as $p) {
            $p['delete_url'] = $url('protected.delete', ['folder' => $p['id']]);
            $p['login_url'] = $url('protected.logins', ['folder' => $p['id']]);
            $p['logout_urls'] = [];

            foreach ($p['logins'] as $login) {
                $p['logout_urls'][$login] = $url('protected.logins.delete', ['folder' => $p['id'], 'login' => $login]);
            }

            unset($p['users']);
            $protected[] = $p;
        }

        $parent = $domain['parent_id'] === null ? null : $this->domains->find((int) $account['id'], (int) $domain['parent_id']);
        $inUse = \array_values(\array_filter(\array_map(static fn(array $d): string => (string) ($d['php'] ?? ''), $this->domains->forAccount((int) $account['id']))));

        return $this->pages->render($request, $session, $identity, 'user', '@Web/site', $extra + [
            'account' => $account,
            'domain' => $domain,
            'parent' => $parent,
            'root' => \rtrim((string) ($account['home'] ?? '/home/' . $account['username']), '/') . '/' . $domain['docroot'],
            'redirects' => $redirects,
            'protected' => $protected,
            'codes' => SiteFeatures::ERROR_CODES,
            'hotlink_default' => \implode(', ', SiteFeatures::HOTLINK_DEFAULT),
            'php' => $this->sync->phpVersions($inUse),
            'web' => $this->sync->stateOf((int) $account['id']),
            'urls' => [
                'back' => $this->router->url('user.domains'),
                'general' => $url('update'),
                'redirect' => $url('redirects'),
                'errors' => $url('errors'),
                'hotlink' => $url('hotlink'),
                'protect' => $url('protected'),
                'delete' => $url('delete'),
            ],
            'form' => null,
            'errors' => [],
            'old' => [],
        ], title: (string) $domain['name'], active: 'domains', status: $status);
    }

    /** @param array<string, mixed> $extra */
    private function phpPage(Request $request, Session $session, Identity $identity, array $extra = [], int $status = 200): Response
    {
        $account = $this->subusers->accountOf($identity) ?? throw HttpException::notFound();
        $versions = [];

        foreach ($this->domains->forAccount((int) $account['id']) as $d) {
            if ($d['php'] !== null) {
                $versions[(string) $d['php']][] = (string) $d['name'];
            }
        }

        \ksort($versions);
        $cards = [];

        foreach ($versions as $version => $sites) {
            $version = (string) $version;
            $cards[] = [
                'version' => $version,
                'label' => WebSync::phpLabel($version),
                'sites' => $sites,
                'values' => $this->php->of((int) $account['id'], $version),
                'action' => $this->router->url('user.php.save', ['version' => $version]),
            ];
        }

        $directives = [];

        foreach (PhpSettings::DIRECTIVES as $name => $d) {
            $directives[] = ['name' => $name, 'field' => self::field($name), 'kind' => $d['kind'], 'choices' => $d['choices'] ?? []];
        }

        return $this->pages->render($request, $session, $identity, 'user', '@Web/php', $extra + [
            'cards' => $cards,
            'directives' => $directives,
            'zones' => \timezone_identifiers_list(),
            'errors' => [],
            'old' => [],
        ], title: 'Web.php_settings', active: 'php', status: $status);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} [account, domain] */
    private function domain(Identity $identity, string $id): array
    {
        $account = $this->subusers->accountOf($identity) ?? throw HttpException::notFound();
        $domain = $this->domains->find((int) $account['id'], $id) ?? throw HttpException::notFound();

        return [$account, $domain];
    }
}
