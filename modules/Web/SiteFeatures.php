<?php

declare(strict_types=1);

namespace App\Modules\Web;

use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Modules\Accounts\Accounts;

/**
 * What an owner sets on each site besides its folder and PHP version:
 * redirects, the www. choice, custom error pages, password-protected folders
 * and hotlink protection.
 *
 * The rules match the agent's (internal/web/features.go): each value goes
 * into the site's Apache configuration, so none may hold a quote, a space or
 * a line break.
 */
final class SiteFeatures
{
    public const ERROR_CODES = ['400', '401', '403', '404', '500', '503'];

    public const CANONICAL = ['', 'www', 'bare'];

    public const HOTLINK_DEFAULT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'mp4'];

    public const MAX_REDIRECTS = 100;

    public const MAX_PROTECTED = 50;

    public const MAX_LOGINS = 100;

    public const MIN_PASSWORD = 8;

    private const URL_PATH = '#^/[A-Za-z0-9._~/%+@!$&()*,;=:-]{0,500}$#';
    private const TARGET = '#^https?://[A-Za-z0-9.-]{1,253}(:[0-9]{1,5})?(/[A-Za-z0-9._~/%?\#&=+@!$()*,;:-]*)?$#';
    private const ERROR_PAGE = '#^/[A-Za-z0-9._/-]{1,200}$#';
    private const REALM = '/^[A-Za-z0-9 ._-]{1,60}$/';
    private const LOGIN = '/^[A-Za-z0-9._-]{1,32}$/';
    private const EXTENSION = '/^[a-z0-9]{1,8}$/';
    private const PART = '/^[A-Za-z0-9_][A-Za-z0-9._-]{0,63}$/';

    public function __construct(private readonly ConnectionManager $connections) {}

    // ---- Redirects ---------------------------------------------------------

    /** @return list<array<string, mixed>> a domain's redirects, by path */
    public function redirects(int $domainId): array
    {
        return \array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'code' => (int) $r['code']] + $r, $this->table('web_redirects')->where('domain_id', $domainId)->orderBy('path')->get());
    }

    /**
     * @param array<string, mixed> $input path, to, code
     *
     * @return array{0: array{path: string, to: string, code: int}, 1: array<string, string>}
     */
    public function validateRedirect(int $domainId, array $input): array
    {
        $path = '/' . \ltrim(\trim((string) ($input['path'] ?? '')), '/');
        $to = \trim((string) ($input['to'] ?? ''));
        $code = (int) ($input['code'] ?? 301);
        $errors = [];

        if (\preg_match(self::URL_PATH, $path) !== 1 || \str_contains($path, '/../') || \str_ends_with($path, '/..')) {
            $errors['path'] = 'Web.error_redirect_path';
        } elseif ($this->table('web_redirects')->where('domain_id', $domainId)->where('path', $path)->first() !== null) {
            $errors['path'] = 'Web.error_redirect_twice';
        }

        if (\strlen($to) > 2048 || \preg_match(self::TARGET, $to) !== 1) {
            $errors['to'] = 'Web.error_redirect_to';
        }

        if ($code !== 301 && $code !== 302) {
            $errors['code'] = 'Web.error_redirect_code';
        }

        if ($errors === [] && \count($this->redirects($domainId)) >= self::MAX_REDIRECTS) {
            $errors['path'] = 'Web.error_too_many';
        }

        return [['path' => $path, 'to' => $to, 'code' => $code], $errors];
    }

    /** @param array{path: string, to: string, code: int} $clean */
    public function addRedirect(int $domainId, array $clean): int
    {
        return (int) $this->table('web_redirects')->insert([
            'domain_id' => $domainId, 'path' => $clean['path'], 'target' => $clean['to'], 'code' => $clean['code'], 'created_at' => Accounts::now(),
        ], 'id');
    }

    /** @return array<string, mixed>|null */
    public function redirect(int $domainId, string|int $id): ?array
    {
        return $this->table('web_redirects')->where('domain_id', $domainId)->where('id', (int) $id)->first();
    }

    /** @param array<string, mixed> $row */
    public function restoreRedirect(array $row): void
    {
        $this->table('web_redirects')->insert($row);
    }

    public function deleteRedirect(int $id): void
    {
        $this->table('web_redirects')->where('id', $id)->delete();
    }

    // ---- Error pages, www. and hotlink protection ---------------------------

    /**
     * @param array<int|string, mixed> $input code => path; an empty path removes it
     *
     * @return array{0: array<int, string>, 1: array<string, string>} codes are numbers, so PHP keys them as ints
     */
    public static function validateErrorPages(array $input): array
    {
        $clean = [];
        $errors = [];

        foreach ($input as $code => $path) {
            $path = \trim((string) $path);

            if (!\in_array((string) $code, self::ERROR_CODES, true)) {
                $errors['page_' . $code] = 'Web.error_error_code';
            } elseif ($path !== '') {
                $path = '/' . \ltrim($path, '/');

                if (\preg_match(self::ERROR_PAGE, $path) !== 1 || \str_contains($path, '..')) {
                    $errors['page_' . $code] = 'Web.error_error_page';
                } else {
                    $clean[(int) $code] = $path;
                }
            }
        }

        \ksort($clean);

        return [$clean, $errors];
    }

    /**
     * Hotlink protection: on with these file kinds and other allowed sites,
     * or off (null).
     *
     * @return array{0: ?array{extensions: list<string>, allow: list<string>}, 1: array<string, string>}
     */
    public static function validateHotlink(bool $on, string $extensions, string $allow): array
    {
        if (!$on) {
            return [null, []];
        }

        $errors = [];
        $kinds = self::words($extensions);
        $hosts = self::words($allow);

        if ($kinds === [] || \count($kinds) > 20 || \array_filter($kinds, static fn(string $e): bool => \preg_match(self::EXTENSION, $e) !== 1) !== []) {
            $errors['extensions'] = 'Web.error_hotlink_kinds';
        }

        if (\count($hosts) > 50 || \array_filter($hosts, static fn(string $h): bool => \preg_match(\App\Modules\Accounts\AccountRules::DOMAIN, $h) !== 1) !== []) {
            $errors['allow'] = 'Web.error_hotlink_allow';
        }

        return [['extensions' => $kinds, 'allow' => $hosts], $errors];
    }

    // ---- Protected folders ---------------------------------------------------

    /** @return list<array<string, mixed>> a domain's protected folders, with their users' names */
    public function protected(int $domainId): array
    {
        return \array_map(self::decodeProtected(...), $this->table('web_protected')->where('domain_id', $domainId)->orderBy('path')->get());
    }

    /** @return array<string, mixed>|null */
    public function protectedFolder(int $domainId, string|int $id): ?array
    {
        $row = $this->table('web_protected')->where('domain_id', $domainId)->where('id', (int) $id)->first();

        return $row === null ? null : self::decodeProtected($row);
    }

    /**
     * @param array<string, mixed> $input path, realm, and optionally login and password for the first user
     *
     * @return array{0: array{path: string, realm: string, users: list<array{name: string, hash: string}>}, 1: array<string, string>}
     */
    public function validateProtected(int $domainId, array $input): array
    {
        $path = \trim(\str_replace('\\', '/', (string) ($input['path'] ?? '')), '/');
        $realm = \trim((string) ($input['realm'] ?? '')) ?: 'Private';
        $errors = [];

        if ($path !== '' && !self::relativeOk($path)) {
            $errors['path'] = 'Web.error_protect_path';
        } elseif ($this->table('web_protected')->where('domain_id', $domainId)->where('path', $path)->first() !== null) {
            $errors['path'] = 'Web.error_protect_twice';
        }

        if (\preg_match(self::REALM, $realm) !== 1) {
            $errors['realm'] = 'Web.error_realm';
        }

        $users = [];

        if (($input['login'] ?? '') !== '' || ($input['password'] ?? '') !== '') {
            [$user, $userErrors] = self::validateLogin((string) ($input['login'] ?? ''), (string) ($input['password'] ?? ''));
            $errors += $userErrors;
            $users[] = $user;
        }

        if ($errors === [] && \count($this->protected($domainId)) >= self::MAX_PROTECTED) {
            $errors['path'] = 'Web.error_too_many';
        }

        return [['path' => $path, 'realm' => $realm, 'users' => $users], $errors];
    }

    /**
     * A login for a protected folder: the password is hashed here (bcrypt,
     * which Apache checks) and never kept in clear.
     *
     * @return array{0: array{name: string, hash: string}, 1: array<string, string>}
     */
    public static function validateLogin(string $login, string $password): array
    {
        $login = \trim($login);
        $errors = [];

        if (\preg_match(self::LOGIN, $login) !== 1) {
            $errors['login'] = 'Web.error_login';
        }

        if (\mb_strlen($password) < self::MIN_PASSWORD) {
            $errors['password'] = 'Web.error_login_password';
        }

        return [['name' => $login, 'hash' => $errors === [] ? \password_hash($password, \PASSWORD_BCRYPT) : ''], $errors];
    }

    /** @param array{path: string, realm: string, users: list<array{name: string, hash: string}>} $clean */
    public function addProtected(int $domainId, array $clean): int
    {
        return (int) $this->table('web_protected')->insert([
            'domain_id' => $domainId, 'path' => $clean['path'], 'realm' => $clean['realm'],
            'users' => \json_encode($clean['users'], \JSON_THROW_ON_ERROR), 'created_at' => Accounts::now(),
        ], 'id');
    }

    /** @param list<array{name: string, hash: string}> $users */
    public function setUsers(int $id, array $users): void
    {
        $this->table('web_protected')->where('id', $id)->update(['users' => \json_encode(\array_values($users), \JSON_THROW_ON_ERROR)]);
    }

    /** @param array<string, mixed> $row as protectedFolder() returns it */
    public function restoreProtected(array $row): void
    {
        $row['users'] = \json_encode($row['users'], \JSON_THROW_ON_ERROR);
        unset($row['logins']);
        $this->table('web_protected')->insert($row);
    }

    public function deleteProtected(int $id): void
    {
        $this->table('web_protected')->where('id', $id)->delete();
    }

    /** Everything of a removed domain. */
    public function forgetDomain(int $domainId): void
    {
        $this->table('web_redirects')->where('domain_id', $domainId)->delete();
        $this->table('web_protected')->where('domain_id', $domainId)->delete();
    }

    /**
     * A site's features, as web.apply takes them.
     *
     * @param array<string, mixed> $domain
     *
     * @return array<string, mixed>
     */
    public function stateOf(array $domain): array
    {
        $id = (int) $domain['id'];

        return [
            'canonical' => (string) ($domain['canonical'] ?? ''),
            'redirects' => \array_map(static fn(array $r): array => ['path' => (string) $r['path'], 'to' => (string) $r['target'], 'code' => (int) $r['code']], $this->redirects($id)),
            'error_pages' => (object) $domain['error_pages'],
            'protected' => \array_map(static fn(array $p): array => ['path' => (string) $p['path'], 'realm' => (string) $p['realm'], 'users' => $p['users']], $this->protected($id)),
            'hotlink' => $domain['hotlink'],
        ];
    }

    public static function relativeOk(string $path): bool
    {
        $parts = \explode('/', $path);

        if (\count($parts) > 8) {
            return false;
        }

        foreach ($parts as $part) {
            if (\preg_match(self::PART, $part) !== 1 || $part === '.' || $part === '..') {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> comma- or space-separated words, lowercased, without a leading dot */
    private static function words(string $text): array
    {
        $words = \preg_split('/[\s,]+/', \trim($text), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $words = \array_map(static fn(string $w): string => \ltrim(\strtolower($w), '.'), $words);

        return \array_values(\array_unique($words));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function decodeProtected(array $row): array
    {
        $users = \json_decode((string) $row['users'], true);
        $row['id'] = (int) $row['id'];
        $row['users'] = \is_array($users) ? \array_values($users) : [];
        $row['logins'] = \array_column($row['users'], 'name');

        return $row;
    }

    private function table(string $name): QueryBuilder
    {
        return $this->connections->connection()->table($name);
    }
}
