<?php

declare(strict_types=1);

namespace App\Modules\Web;

use App\Engine\Auth\Identity;
use App\Engine\Http\Request;
use App\Modules\Audit\AuditLog;
use App\Modules\Packages\Packages;

/**
 * Changing an account's domains and sites: the one place the User panel,
 * the API and the CLI all go through.
 *
 * Each change is saved, then the account's whole web state is sent to the
 * server (WebSync). If the server refuses it (a test failed, or the probe
 * did and the agent put the old files back), the change is undone here too
 * and the server is told the state it still has. If the agent cannot be
 * reached, the change stays recorded and web:settle finishes it.
 *
 * Every method returns [?WebOutcome, errors]: no outcome and some errors
 * when the input was refused before anything changed.
 */
final class DomainActions
{
    public function __construct(
        private readonly Domains $domains,
        private readonly SiteFeatures $features,
        private readonly PhpSettings $php,
        private readonly WebSync $sync,
        private readonly Packages $packages,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $input   name, kind, target, docroot, php, wildcard
     *
     * @return array{0: ?WebOutcome, 1: array<string, string>, 2: array<string, mixed>} [outcome, errors, clean]
     */
    public function add(?Identity $actor, ?Request $request, array $account, array $input): array
    {
        $package = $this->packages->byId((int) $account['package_id']) ?? [];
        $input['php'] = ($input['php'] ?? '') === '' ? $this->sync->defaultPhp() : $input['php'];
        [$clean, $errors] = $this->domains->validateNew($account, $package, $input, $this->sync->phpVersions($this->inUse($account)));

        if ($errors !== []) {
            return [null, $errors, $clean];
        }

        $id = 0;
        $outcome = $this->attempt(
            $actor,
            $request,
            $account,
            'domain.add',
            $clean['name'],
            ['kind' => $clean['kind'], 'docroot' => $clean['docroot'], 'php' => $clean['php']],
            function () use (&$id, $account, $clean): void {
                $id = $this->domains->add((int) $account['id'], $clean);
            },
            function () use (&$id): void {
                $this->domains->delete($id);
            },
        );

        return [$outcome, [], $clean];
    }

    /**
     * Folder, PHP version, wildcard and the www. choice.
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $domain
     * @param array<string, mixed> $input   docroot?, php?, wildcard?, canonical?
     *
     * @return array{0: ?WebOutcome, 1: array<string, string>}
     */
    public function change(?Identity $actor, ?Request $request, array $account, array $domain, array $input): array
    {
        [$clean, $errors] = $this->domains->validateChange($domain, $input, $this->sync->phpVersions($this->inUse($account)));

        if ($errors !== []) {
            return [null, $errors];
        }

        return [$this->changeColumns($actor, $request, $account, $domain, 'domain.change', $clean, $clean), []];
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $domain
     * @param array<int|string, mixed> $pages code => path; empty removes
     *
     * @return array{0: ?WebOutcome, 1: array<string, string>}
     */
    public function errorPages(?Identity $actor, ?Request $request, array $account, array $domain, array $pages): array
    {
        if ($domain['kind'] === Domains::ALIAS) {
            return [null, ['kind' => 'Web.error_alias_settings']];
        }

        [$clean, $errors] = SiteFeatures::validateErrorPages($pages);

        if ($errors !== []) {
            return [null, $errors];
        }

        return [$this->changeColumns($actor, $request, $account, $domain, 'domain.error_pages', ['error_pages' => $clean], ['pages' => \json_encode($clean, JSON_THROW_ON_ERROR)]), []];
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $domain
     *
     * @return array{0: ?WebOutcome, 1: array<string, string>}
     */
    public function hotlink(?Identity $actor, ?Request $request, array $account, array $domain, bool $on, string $extensions, string $allow): array
    {
        if ($domain['kind'] === Domains::ALIAS) {
            return [null, ['kind' => 'Web.error_alias_settings']];
        }

        [$clean, $errors] = SiteFeatures::validateHotlink($on, $extensions, $allow);

        if ($errors !== []) {
            return [null, $errors];
        }

        return [$this->changeColumns($actor, $request, $account, $domain, 'domain.hotlink', ['hotlink' => $clean], ['on' => $on] + ($clean ?? [])), []];
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $domain
     * @param array<string, mixed> $input   path, to, code
     *
     * @return array{0: ?WebOutcome, 1: array<string, string>}
     */
    public function addRedirect(?Identity $actor, ?Request $request, array $account, array $domain, array $input): array
    {
        if ($domain['kind'] === Domains::ALIAS) {
            return [null, ['kind' => 'Web.error_alias_settings']];
        }

        [$clean, $errors] = $this->features->validateRedirect((int) $domain['id'], $input);

        if ($errors !== []) {
            return [null, $errors];
        }

        $id = 0;

        return [$this->attempt(
            $actor,
            $request,
            $account,
            'domain.redirect',
            (string) $domain['name'],
            $clean,
            function () use (&$id, $domain, $clean): void {
                $id = $this->features->addRedirect((int) $domain['id'], $clean);
            },
            function () use (&$id): void {
                $this->features->deleteRedirect($id);
            },
        ), []];
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $domain
     * @param array<string, mixed> $redirect
     */
    public function removeRedirect(?Identity $actor, ?Request $request, array $account, array $domain, array $redirect): WebOutcome
    {
        return $this->attempt(
            $actor,
            $request,
            $account,
            'domain.unredirect',
            (string) $domain['name'],
            ['path' => $redirect['path']],
            fn() => $this->features->deleteRedirect((int) $redirect['id']),
            fn() => $this->features->restoreRedirect($redirect),
        );
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $domain
     * @param array<string, mixed> $input   path, realm, login?, password?
     *
     * @return array{0: ?WebOutcome, 1: array<string, string>}
     */
    public function protect(?Identity $actor, ?Request $request, array $account, array $domain, array $input): array
    {
        if ($domain['kind'] === Domains::ALIAS) {
            return [null, ['kind' => 'Web.error_alias_settings']];
        }

        [$clean, $errors] = $this->features->validateProtected((int) $domain['id'], $input);

        if ($errors !== []) {
            return [null, $errors];
        }

        $id = 0;

        return [$this->attempt(
            $actor,
            $request,
            $account,
            'domain.protect',
            (string) $domain['name'],
            ['path' => '/' . $clean['path'], 'realm' => $clean['realm'], 'logins' => \implode(',', \array_column($clean['users'], 'name'))],
            function () use (&$id, $domain, $clean): void {
                $id = $this->features->addProtected((int) $domain['id'], $clean);
            },
            function () use (&$id): void {
                $this->features->deleteProtected($id);
            },
        ), []];
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $domain
     * @param array<string, mixed> $folder
     */
    public function unprotect(?Identity $actor, ?Request $request, array $account, array $domain, array $folder): WebOutcome
    {
        return $this->attempt(
            $actor,
            $request,
            $account,
            'domain.unprotect',
            (string) $domain['name'],
            ['path' => '/' . $folder['path']],
            fn() => $this->features->deleteProtected((int) $folder['id']),
            fn() => $this->features->restoreProtected($folder),
        );
    }

    /**
     * Add a login to a protected folder, or give an existing one a new password.
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $domain
     * @param array<string, mixed> $folder
     *
     * @return array{0: ?WebOutcome, 1: array<string, string>}
     */
    public function setLogin(?Identity $actor, ?Request $request, array $account, array $domain, array $folder, string $login, string $password): array
    {
        [$user, $errors] = SiteFeatures::validateLogin($login, $password);
        $users = \array_values(\array_filter($folder['users'], static fn(array $u): bool => $u['name'] !== $user['name']));

        if ($errors === [] && \count($users) >= SiteFeatures::MAX_LOGINS) {
            $errors['login'] = 'Web.error_too_many';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        $users[] = $user;

        return [$this->setUsers($actor, $request, $account, $domain, $folder, $users, 'domain.protect_login', $user['name']), []];
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $domain
     * @param array<string, mixed> $folder
     */
    public function removeLogin(?Identity $actor, ?Request $request, array $account, array $domain, array $folder, string $login): WebOutcome
    {
        $users = \array_values(\array_filter($folder['users'], static fn(array $u): bool => $u['name'] !== $login));

        return $this->setUsers($actor, $request, $account, $domain, $folder, $users, 'domain.protect_logout', $login);
    }

    /**
     * Replace the owner's php.ini settings for one PHP version.
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $input   directive => value; empty is PHP's default
     *
     * @return array{0: ?WebOutcome, 1: array<string, string>}
     */
    public function phpSettings(?Identity $actor, ?Request $request, array $account, string $version, array $input): array
    {
        [$clean, $errors] = PhpSettings::validate($input);

        if (!\in_array($version, $this->sync->phpVersions($this->inUse($account)), true)) {
            $errors['version'] = 'Web.error_php';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        $id = (int) $account['id'];
        $before = $this->php->of($id, $version);

        return [$this->attempt(
            $actor,
            $request,
            $account,
            'php.settings',
            WebSync::phpLabel($version),
            $clean,
            fn() => $this->php->replace($id, $version, $clean),
            fn() => $this->php->replace($id, $version, $before),
        ), []];
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $domain
     *
     * @return array{0: ?WebOutcome, 1: ?string} [outcome, why it cannot be removed]
     */
    public function remove(?Identity $actor, ?Request $request, array $account, array $domain): array
    {
        $blocker = $this->domains->blocker($domain);

        if ($blocker !== null) {
            return [null, $blocker];
        }

        $outcome = $this->attempt(
            $actor,
            $request,
            $account,
            'domain.remove',
            (string) $domain['name'],
            ['kind' => $domain['kind']],
            fn() => $this->domains->delete((int) $domain['id']),
            fn() => $this->domains->restore($domain),
        );

        if ($outcome->status !== WebOutcome::FAILED) {
            $this->features->forgetDomain((int) $domain['id']);
        }

        return [$outcome, null];
    }

    // -------------------------------------------------------------------------

    /**
     * Save, send, and undo both if the server refuses; audit either way.
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $details
     */
    private function attempt(?Identity $actor, ?Request $request, array $account, string $action, string $subject, array $details, \Closure $do, \Closure $undo): WebOutcome
    {
        $do();
        $outcome = $this->sync->touch((int) $account['id']);

        if ($outcome->status === WebOutcome::FAILED) {
            $undo();
            $this->sync->touch((int) $account['id']);
        }

        $details = ['account' => $account['username']] + $details;

        if ($outcome->status === WebOutcome::PENDING) {
            $details['pending'] = true;
        } elseif ($outcome->status === WebOutcome::FAILED) {
            $details += ['reason' => $outcome->code, 'error' => $outcome->message];
        }

        $this->audit->record($action, $actor, $request, $subject, $outcome->status === WebOutcome::FAILED ? AuditLog::FAILED : AuditLog::OK, $details);

        return $outcome;
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $domain
     * @param array<string, mixed> $changes column => decoded value
     * @param array<string, mixed> $details for the audit log
     */
    private function changeColumns(?Identity $actor, ?Request $request, array $account, array $domain, string $action, array $changes, array $details): WebOutcome
    {
        $before = \array_intersect_key($domain, $changes);

        return $this->attempt(
            $actor,
            $request,
            $account,
            $action,
            (string) $domain['name'],
            self::flat($details),
            fn() => $this->domains->update((int) $domain['id'], $changes),
            fn() => $this->domains->update((int) $domain['id'], $before),
        );
    }

    /**
     * @param array<string, mixed>                  $account
     * @param array<string, mixed>                  $domain
     * @param array<string, mixed>                  $folder
     * @param list<array{name: string, hash: string}> $users
     */
    private function setUsers(?Identity $actor, ?Request $request, array $account, array $domain, array $folder, array $users, string $action, string $login): WebOutcome
    {
        $before = $folder['users'];

        return $this->attempt(
            $actor,
            $request,
            $account,
            $action,
            (string) $domain['name'],
            ['path' => '/' . $folder['path'], 'login' => $login],
            fn() => $this->features->setUsers((int) $folder['id'], $users),
            fn() => $this->features->setUsers((int) $folder['id'], $before),
        );
    }


    /**
     * @param array<string, mixed> $account
     *
     * @return list<string>
     */
    private function inUse(array $account): array
    {
        return \array_values(\array_filter(\array_map(
            static fn(array $d): string => (string) ($d['php'] ?? ''),
            $this->domains->forAccount((int) $account['id']),
        )));
    }

    /**
     * Audit details are flat: lists become comma-separated text.
     *
     * @param array<string, mixed> $details
     *
     * @return array<string, mixed>
     */
    private static function flat(array $details): array
    {
        return \array_map(static fn(mixed $v): mixed => \is_array($v) ? \implode(',', \array_map(static fn(mixed $x): string => \is_array($x) ? \implode(',', $x) : (string) $x, $v)) : $v, $details);
    }
}
