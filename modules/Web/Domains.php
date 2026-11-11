<?php

declare(strict_types=1);

namespace App\Modules\Web;

use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Modules\Accounts\AccountRules;
use App\Modules\Accounts\Accounts;

/**
 * The domains table: every name an account's sites answer to.
 *
 *  - main:  the account's own domain, made with the account; served from
 *           public_html. It cannot be removed.
 *  - addon: another website, with its own folder.
 *  - sub:   a subdomain of one of the account's domains, with its own folder.
 *  - alias: another name for a main or addon domain (a parked domain).
 *
 * Every main and addon domain also answers to www.<name>, and to *.<name>
 * when it is a wildcard. Names are unique across the whole server.
 */
final class Domains
{
    public const MAIN = 'main';
    public const ADDON = 'addon';
    public const SUB = 'sub';
    public const ALIAS = 'alias';

    /** What an owner can add; the main domain comes with the account. */
    public const KINDS = [self::ADDON, self::SUB, self::ALIAS];


    private const PART = '/^[A-Za-z0-9_][A-Za-z0-9._-]{0,63}$/';

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly SiteFeatures $features,
        private readonly PhpSettings $php,
    ) {}

    /** @return list<array<string, mixed>> an account's domains: main first, then by name */
    public function forAccount(int $accountId): array
    {
        $rows = $this->table()->where('account_id', $accountId)->orderBy('name')->get();
        \usort($rows, static fn(array $a, array $b): int => ($b['kind'] === self::MAIN) <=> ($a['kind'] === self::MAIN));

        return \array_map(self::decode(...), $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(int $accountId, string|int $id): ?array
    {
        $row = $this->table()->where('id', (int) $id)->where('account_id', $accountId)->first();

        return $row === null ? null : self::decode($row);
    }

    /** @return array<string, mixed>|null */
    public function findByName(int $accountId, string $name): ?array
    {
        $row = $this->table()->where('name', self::normalise($name))->where('account_id', $accountId)->first();

        return $row === null ? null : self::decode($row);
    }

    /** Is the name, or the domain it would be www. of, already someone's? */
    public function taken(string $name): bool
    {
        $name = self::normalise($name);
        $bare = \str_starts_with($name, 'www.') ? \substr($name, 4) : $name;

        return $this->table()->where('name', $name)->first() !== null
            || $this->table()->where('name', $bare)->first() !== null;
    }

    /**
     * Make sure the account's main domain has its row (accounts made before
     * the Web module, or whose row was lost).
     *
     * @param array<string, mixed> $account
     */
    public function ensureMain(array $account, string $php): void
    {
        $id = (int) $account['id'];

        if ($this->table()->where('account_id', $id)->where('kind', self::MAIN)->first() !== null) {
            return;
        }

        $this->table()->insert([
            'account_id' => $id,
            'name' => (string) $account['domain'],
            'kind' => self::MAIN,
            'docroot' => 'public_html',
            'php' => $php,
            'wildcard' => false,
            'created_at' => Accounts::now(),
        ]);
    }

    /**
     * Check a new domain for an account.
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $package the account's package, decoded
     * @param array<string, mixed> $input   name, kind, target, docroot, php, wildcard
     * @param list<string>         $php     the PHP versions installed
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>} [clean, errors]
     */
    public function validateNew(array $account, array $package, array $input, array $php): array
    {
        $errors = [];
        $kind = (string) ($input['kind'] ?? '');
        $name = self::normalise((string) ($input['name'] ?? ''));
        $mine = $this->forAccount((int) $account['id']);
        $sites = \array_values(\array_filter($mine, static fn(array $d): bool => $d['kind'] === self::MAIN || $d['kind'] === self::ADDON));

        if (!\in_array($kind, self::KINDS, true)) {
            $errors['kind'] = 'Web.error_kind';
        }

        if (\preg_match(AccountRules::DOMAIN, $name) !== 1) {
            $errors['name'] = 'Web.error_name';
        } elseif (\str_starts_with($name, 'www.')) {
            $errors['name'] = 'Web.error_www';
        } elseif ($this->taken($name)) {
            $errors['name'] = 'Web.error_taken';
        }

        $parent = null;

        if ($kind === self::SUB && !isset($errors['name'])) {
            foreach ($sites as $site) {
                if (\str_ends_with($name, '.' . $site['name']) && ($parent === null || \strlen($site['name']) > \strlen((string) $parent['name']))) {
                    $parent = $site;
                }
            }

            if ($parent === null) {
                $errors['name'] = 'Web.error_sub_parent';
            }
        } elseif ($kind === self::ALIAS) {
            foreach ($sites as $site) {
                if ((string) $site['id'] === (string) ($input['target'] ?? '')) {
                    $parent = $site;
                }
            }

            if ($parent === null) {
                $errors['target'] = 'Web.error_target';
            }
        }

        $clean = ['name' => $name, 'kind' => $kind, 'parent_id' => $parent['id'] ?? null, 'docroot' => null, 'php' => null, 'wildcard' => false];

        if ($kind === self::ADDON || $kind === self::SUB) {
            $default = $kind === self::SUB && $parent !== null
                ? 'public_html/' . \substr($name, 0, -\strlen('.' . $parent['name']))
                : 'public_html/' . $name;
            $docroot = self::normaliseDocroot((string) ($input['docroot'] ?? ''));
            $clean['docroot'] = $docroot === '' ? $default : $docroot;

            if (!self::docrootOk($clean['docroot'])) {
                $errors['docroot'] = 'Web.error_docroot';
            }

            $clean['php'] = (string) ($input['php'] ?? '');

            if (!\in_array($clean['php'], $php, true)) {
                $errors['php'] = 'Web.error_php';
            }

            $clean['wildcard'] = $kind === self::ADDON && ($input['wildcard'] ?? false) === true;
        }

        if ($errors === [] && ($limit = $this->overLimit($mine, $package, $kind)) !== null) {
            $errors['name'] = $limit;
        }

        return [$clean, $errors];
    }

    /**
     * Check a change of folder or PHP version on one of an account's
     * domains (not an alias).
     *
     * @param array<string, mixed> $domain
     * @param array<string, mixed> $input  docroot?, php?, wildcard?
     * @param list<string>         $php
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public function validateChange(array $domain, array $input, array $php): array
    {
        $errors = [];
        $clean = [];

        if ($domain['kind'] === self::ALIAS) {
            return [[], ['kind' => 'Web.error_alias_settings']];
        }

        if (\array_key_exists('docroot', $input) && $input['docroot'] !== null) {
            $clean['docroot'] = self::normaliseDocroot((string) $input['docroot']);

            if (!self::docrootOk($clean['docroot'])) {
                $errors['docroot'] = 'Web.error_docroot';
            }
        }

        if (\array_key_exists('php', $input) && $input['php'] !== null) {
            $clean['php'] = (string) $input['php'];

            if (!\in_array($clean['php'], $php, true)) {
                $errors['php'] = 'Web.error_php';
            }
        }

        if (\array_key_exists('wildcard', $input) && $input['wildcard'] !== null && $domain['kind'] !== self::SUB) {
            $clean['wildcard'] = $input['wildcard'] === true;
        }

        if (\array_key_exists('canonical', $input) && $input['canonical'] !== null) {
            $canonical = (string) $input['canonical'];

            if (!\in_array($canonical, SiteFeatures::CANONICAL, true) || ($canonical === 'www' && $domain['kind'] === self::SUB)) {
                $errors['canonical'] = 'Web.error_canonical';
            } else {
                $clean['canonical'] = $canonical === '' ? null : $canonical;
            }
        }

        return [$clean, $errors];
    }

    /**
     * @param array<string, mixed> $clean from validateNew()
     */
    public function add(int $accountId, array $clean): int
    {
        return (int) $this->table()->insert([
            'account_id' => $accountId,
            'name' => $clean['name'],
            'kind' => $clean['kind'],
            'parent_id' => $clean['parent_id'],
            'docroot' => $clean['docroot'],
            'php' => $clean['php'],
            'wildcard' => (bool) $clean['wildcard'],
            'created_at' => Accounts::now(),
        ], 'id');
    }

    /** @param array<string, mixed> $changes decoded values (error_pages and hotlink as arrays) */
    public function update(int $id, array $changes): void
    {
        if ($changes !== []) {
            $this->table()->where('id', $id)->update(self::encode($changes));
        }
    }

    /**
     * Why a domain cannot be removed, or null if it can.
     *
     * @param array<string, mixed> $domain
     */
    public function blocker(array $domain): ?string
    {
        if ($domain['kind'] === self::MAIN) {
            return 'Web.error_remove_main';
        }

        if ($this->table()->where('parent_id', (int) $domain['id'])->first() !== null) {
            return 'Web.error_remove_children';
        }

        return null;
    }

    /** Remove a domain row; its redirects and protected folders stay for undo(). */
    public function delete(int $id): void
    {
        $this->table()->where('id', $id)->delete();
    }

    public function deleteAccount(int $accountId): void
    {
        foreach ($this->forAccount($accountId) as $domain) {
            $this->features->forgetDomain((int) $domain['id']);
        }

        $this->table()->where('account_id', $accountId)->delete();
        $this->php->forget($accountId);
    }

    /**
     * Put a removed row back as it was, id and all (when the server refused
     * the removal).
     *
     * @param array<string, mixed> $row as find() returned it
     */
    public function restore(array $row): void
    {
        $row = self::encode($row);
        unset($row['parent_name'], $row['update_url'], $row['delete_url']);
        $this->table()->insert($row);
    }

    /**
     * The account's whole web state, as web.apply takes it.
     *
     * @param array<string, mixed> $account
     *
     * @return array{username: string, suspended: bool, sites: list<array{name: string, aliases: list<string>, docroot: string, php: string}>, pool: array{max_children: int}}
     */
    public function state(array $account): array
    {
        $rows = $this->forAccount((int) $account['id']);
        $aliases = [];

        foreach ($rows as $row) {
            if ($row['kind'] === self::ALIAS && $row['parent_id'] !== null) {
                $aliases[(int) $row['parent_id']][] = $row['name'];
                $aliases[(int) $row['parent_id']][] = 'www.' . $row['name'];
            }
        }

        $sites = [];

        foreach ($rows as $row) {
            if ($row['kind'] === self::ALIAS) {
                continue;
            }

            $names = $row['kind'] === self::SUB ? [] : ['www.' . $row['name']];

            if ($row['wildcard']) {
                $names[] = '*.' . $row['name'];
            }

            $sites[] = [
                'name' => (string) $row['name'],
                'aliases' => \array_merge($names, $aliases[(int) $row['id']] ?? []),
                'docroot' => (string) $row['docroot'],
                'php' => (string) $row['php'],
            ] + $this->features->stateOf($row);
        }

        return [
            'username' => (string) $account['username'],
            'suspended' => $account['status'] === Accounts::SUSPENDED,
            'sites' => $sites,
            'pool' => ['max_children' => 5],
            'php_settings' => (object) $this->php->all((int) $account['id']),
        ];
    }

    /**
     * How many of each the account has and may have.
     *
     * @param array<string, mixed> $package decoded
     *
     * @return array{domains: array{used: int, limit: int}, subdomains: array{used: int, limit: int}}
     */
    public function usage(int $accountId, array $package): array
    {
        $rows = $this->forAccount($accountId);
        $subs = \count(\array_filter($rows, static fn(array $d): bool => $d['kind'] === self::SUB));

        return [
            'domains' => ['used' => \count($rows) - $subs, 'limit' => (int) ($package['domains'] ?? 0)],
            'subdomains' => ['used' => $subs, 'limit' => (int) ($package['subdomains'] ?? 0)],
        ];
    }

    public static function normalise(string $name): string
    {
        return AccountRules::normaliseDomain($name);
    }

    public static function normaliseDocroot(string $docroot): string
    {
        return \trim(\str_replace('\\', '/', \trim($docroot)), '/');
    }

    /**
     * The same rule the agent applies: public_html or a folder under it,
     * plain names only. Everything served lives in public_html, which has
     * the web server's group and the SELinux label for web content.
     */
    public static function docrootOk(string $docroot): bool
    {
        $parts = \explode('/', $docroot);

        if ($parts[0] !== 'public_html' || \count($parts) > 6) {
            return false;
        }

        foreach ($parts as $part) {
            if (\preg_match(self::PART, $part) !== 1 || $part === '.' || $part === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $mine
     * @param array<string, mixed>       $package
     */
    private function overLimit(array $mine, array $package, string $kind): ?string
    {
        $subs = \count(\array_filter($mine, static fn(array $d): bool => $d['kind'] === self::SUB));

        if ($kind === self::SUB) {
            $limit = (int) ($package['subdomains'] ?? 0);

            return $limit > 0 && $subs >= $limit ? 'Web.error_subdomain_limit' : null;
        }

        $limit = (int) ($package['domains'] ?? 0);

        return $limit > 0 && \count($mine) - $subs >= $limit ? 'Web.error_domain_limit' : null;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['account_id'] = (int) $row['account_id'];
        $row['parent_id'] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        $row['wildcard'] = (bool) $row['wildcard'];
        $row['canonical'] = $row['canonical'] ?? null;
        $pages = \json_decode((string) ($row['error_pages'] ?? ''), true);
        $row['error_pages'] = \is_array($pages) ? $pages : [];
        $hotlink = \json_decode((string) ($row['hotlink'] ?? ''), true);
        $row['hotlink'] = \is_array($hotlink) ? $hotlink : null;

        return $row;
    }

    /**
     * @param array<string, mixed> $row decoded
     *
     * @return array<string, mixed> as stored
     */
    public static function encode(array $row): array
    {
        if (\array_key_exists('error_pages', $row)) {
            $row['error_pages'] = $row['error_pages'] === [] || $row['error_pages'] === null ? null : \json_encode($row['error_pages'], \JSON_THROW_ON_ERROR);
        }

        if (\array_key_exists('hotlink', $row)) {
            $row['hotlink'] = $row['hotlink'] === null ? null : \json_encode($row['hotlink'], \JSON_THROW_ON_ERROR);
        }

        return $row;
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('domains');
    }
}
