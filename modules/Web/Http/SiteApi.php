<?php

declare(strict_types=1);

namespace App\Modules\Web\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Packages\Http\PackageApi;
use App\Modules\Web\DomainActions;
use App\Modules\Web\Domains;
use App\Modules\Web\PhpSettings;
use App\Modules\Web\SiteFeatures;
use App\Modules\Web\WebOutcome;
use App\Modules\Web\WebSync;

/**
 * A site's features and the account's php.ini settings, in the API, under
 * /admin/api/v1/accounts/{username}/…, with the same rules as the User panel.
 *
 *   PUT    domains/{domain}/error-pages        {"404": "/errors/404.html", …}  (the whole set)
 *   PUT    domains/{domain}/hotlink            {extensions: [...], allow: [...]}
 *   DELETE domains/{domain}/hotlink
 *   POST   domains/{domain}/redirects          {path, to, code}
 *   DELETE domains/{domain}/redirects/{id}
 *   POST   domains/{domain}/protected          {path, realm, login?, password?}
 *   DELETE domains/{domain}/protected/{id}
 *   PUT    domains/{domain}/protected/{id}/logins/{login}  {password}
 *   DELETE domains/{domain}/protected/{id}/logins/{login}
 *   GET    php                                 every version's settings
 *   PUT    php/{version}                       {directive: value, …}  (the whole set)
 *
 *   200 done   202 recorded, still being carried out   422 invalid   502 the server refused it
 */
final class SiteApi
{
    public function __construct(
        private readonly Accounts $accounts,
        private readonly Domains $domains,
        private readonly SiteFeatures $features,
        private readonly PhpSettings $php,
        private readonly DomainActions $actions,
        private readonly Localization $localization,
    ) {}

    public function errorPages(Request $request, Identity $identity, string $username, string $domain): JsonResponse
    {
        [$account, $row] = $this->domain($identity, $username, $domain);
        $pages = $request->input();

        return $this->respond($this->actions->errorPages($identity, $request, $account, $row, \is_array($pages) ? $pages : []), $account, $row);
    }

    public function hotlink(Request $request, Identity $identity, string $username, string $domain): JsonResponse
    {
        [$account, $row] = $this->domain($identity, $username, $domain);
        $list = static fn(mixed $v): string => \is_array($v) ? \implode(',', \array_map('strval', $v)) : (string) $v;

        return $this->respond($this->actions->hotlink($identity, $request, $account, $row, true, $list($request->input('extensions', SiteFeatures::HOTLINK_DEFAULT)), $list($request->input('allow', []))), $account, $row);
    }

    public function hotlinkOff(Request $request, Identity $identity, string $username, string $domain): JsonResponse
    {
        [$account, $row] = $this->domain($identity, $username, $domain);

        return $this->respond($this->actions->hotlink($identity, $request, $account, $row, false, '', ''), $account, $row);
    }

    public function addRedirect(Request $request, Identity $identity, string $username, string $domain): JsonResponse
    {
        [$account, $row] = $this->domain($identity, $username, $domain);
        $input = ['path' => (string) $request->input('path', ''), 'to' => (string) $request->input('to', ''), 'code' => (string) $request->input('code', '301')];

        return $this->respond($this->actions->addRedirect($identity, $request, $account, $row, $input), $account, $row, 201);
    }

    public function removeRedirect(Request $request, Identity $identity, string $username, string $domain, string $id): JsonResponse
    {
        [$account, $row] = $this->domain($identity, $username, $domain);
        $redirect = $this->features->redirect((int) $row['id'], $id) ?? throw HttpException::notFound();

        return $this->respond([$this->actions->removeRedirect($identity, $request, $account, $row, $redirect), []], $account, $row);
    }

    public function protect(Request $request, Identity $identity, string $username, string $domain): JsonResponse
    {
        [$account, $row] = $this->domain($identity, $username, $domain);
        $input = [
            'path' => (string) $request->input('path', ''), 'realm' => (string) $request->input('realm', ''),
            'login' => (string) $request->input('login', ''), 'password' => (string) $request->input('password', ''),
        ];

        return $this->respond($this->actions->protect($identity, $request, $account, $row, $input), $account, $row, 201);
    }

    public function unprotect(Request $request, Identity $identity, string $username, string $domain, string $id): JsonResponse
    {
        [$account, $row] = $this->domain($identity, $username, $domain);
        $folder = $this->features->protectedFolder((int) $row['id'], $id) ?? throw HttpException::notFound();

        return $this->respond([$this->actions->unprotect($identity, $request, $account, $row, $folder), []], $account, $row);
    }

    public function setLogin(Request $request, Identity $identity, string $username, string $domain, string $id, string $login): JsonResponse
    {
        [$account, $row] = $this->domain($identity, $username, $domain);
        $folder = $this->features->protectedFolder((int) $row['id'], $id) ?? throw HttpException::notFound();

        return $this->respond($this->actions->setLogin($identity, $request, $account, $row, $folder, $login, (string) $request->input('password', '')), $account, $row);
    }

    public function removeLogin(Request $request, Identity $identity, string $username, string $domain, string $id, string $login): JsonResponse
    {
        [$account, $row] = $this->domain($identity, $username, $domain);
        $folder = $this->features->protectedFolder((int) $row['id'], $id) ?? throw HttpException::notFound();

        if (!\in_array($login, $folder['logins'], true)) {
            throw HttpException::notFound();
        }

        return $this->respond([$this->actions->removeLogin($identity, $request, $account, $row, $folder, $login), []], $account, $row);
    }

    public function show(Identity $identity, string $username, string $domain): JsonResponse
    {
        [, $row] = $this->domain($identity, $username, $domain);

        return ApiResponse::item($this->present($row));
    }

    public function php(Identity $identity, string $username): JsonResponse
    {
        $account = $this->account($identity, $username);

        return ApiResponse::item($this->presentPhp($account));
    }

    public function savePhp(Request $request, Identity $identity, string $username, string $version): JsonResponse
    {
        $account = $this->account($identity, $username);
        $input = $request->input();
        [$outcome, $errors] = $this->actions->phpSettings($identity, $request, $account, $version, \is_array($input) ? $input : []);

        if ($outcome === null) {
            return PackageApi::invalid($errors, $this->localization);
        }

        return self::byOutcome($outcome, $this->presentPhp($account), $this->localization);
    }

    /**
     * A site with its features, as the API shows it.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function present(array $row): array
    {
        $id = (int) $row['id'];

        return DomainApi::present($row) + [
            'canonical' => $row['canonical'] ?? null,
            'error_pages' => (object) $row['error_pages'],
            'hotlink' => $row['hotlink'],
            'redirects' => \array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'path' => $r['path'], 'to' => $r['target'], 'code' => (int) $r['code']], $this->features->redirects($id)),
            'protected' => \array_map(static fn(array $p): array => ['id' => $p['id'], 'path' => '/' . $p['path'], 'realm' => $p['realm'], 'logins' => $p['logins']], $this->features->protected($id)),
        ];
    }

    /**
     * @param array{0: ?WebOutcome, 1: array<string, string>} $result
     * @param array<string, mixed>                           $account
     * @param array<string, mixed>                           $row
     */
    private function respond(array $result, array $account, array $row, int $created = 200): JsonResponse
    {
        [$outcome, $errors] = $result;

        if ($outcome === null) {
            return PackageApi::invalid($errors, $this->localization);
        }

        $now = $this->domains->find((int) $account['id'], (int) $row['id']);

        return self::byOutcome($outcome, $now === null ? null : $this->present($now), $this->localization, $created);
    }

    public static function byOutcome(WebOutcome $outcome, mixed $data, Localization $localization, int $created = 200): JsonResponse
    {
        return match ($outcome->status) {
            WebOutcome::PENDING => ApiResponse::accepted($data),
            WebOutcome::FAILED => new JsonResponse(['error' => $outcome->code, 'message' => $localization->get('Web.refused'), 'detail' => $outcome->message], 502),
            default => ApiResponse::item($data, $created),
        };
    }

    /**
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>
     */
    private function presentPhp(array $account): array
    {
        $out = [];

        foreach ($this->php->all((int) $account['id']) as $version => $settings) {
            $out[WebSync::phpLabel((string) $version)] = $settings;
        }

        return ['settings' => (object) $out, 'directives' => \array_keys(PhpSettings::DIRECTIVES)];
    }

    /** @return array<string, mixed> */
    private function account(Identity $identity, string $username): array
    {
        return $this->accounts->findByUsername($identity, $username) ?? throw HttpException::notFound();
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function domain(Identity $identity, string $username, string $domain): array
    {
        $account = $this->account($identity, $username);

        return [$account, $this->domains->findByName((int) $account['id'], $domain) ?? throw HttpException::notFound()];
    }
}
