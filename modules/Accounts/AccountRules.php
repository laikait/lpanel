<?php

declare(strict_types=1);

namespace App\Modules\Accounts;

use App\Engine\Auth\Identity;
use App\Engine\Filter\FilterEngine;
use App\Engine\Security\Secret;
use App\Modules\Identity\Http\SecurityPage;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Packages\Packages;

/**
 * What a new account must look like, the same for the page, the API and the
 * CLI. Errors are lang keys by field.
 */
final class AccountRules
{
    /** The agent's rule (dist/lpa/internal/accounts): 2–16 lowercase letters and digits. */
    public const USERNAME = '/^[a-z][a-z0-9]{1,15}$/';

    /** A hostname of at least two labels; no trailing dot, no IP addresses. */
    public const DOMAIN = '/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/';

    /** Names a hosting account may not take, on top of every existing Linux user (the agent refuses those). */
    public const RESERVED = [
        'root', 'admin', 'administrator', 'lpanel', 'lpa', 'apache', 'nginx', 'httpd', 'mysql', 'mariadb', 'postgres',
        'mail', 'postmaster', 'webmaster', 'hostmaster', 'abuse', 'www', 'ftp', 'sshd', 'nobody', 'daemon', 'bin',
        'test', 'exim', 'dovecot', 'named', 'pdns', 'redis', 'clamav', 'rspamd', 'backup', 'support', 'system',
    ];

    public function __construct(
        private readonly PanelUsers $users,
        private readonly Accounts $accounts,
        private readonly Packages $packages,
        private readonly FilterEngine $filters,
    ) {}

    /**
     * @param array<string, mixed> $input username, domain, password, email, name, package, owner
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>} clean values (password as Secret) and errors
     */
    public function validateNew(array $input, Identity $actor): array
    {
        $errors = [];
        $username = \strtolower(\trim((string) ($input['username'] ?? '')));
        $domain = self::normaliseDomain((string) ($input['domain'] ?? ''));
        $email = \trim((string) ($input['email'] ?? ''));
        $name = \trim((string) ($input['name'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if (\preg_match(self::USERNAME, $username) !== 1) {
            $errors['username'] = 'Accounts.error_username';
        } elseif (\in_array($username, self::RESERVED, true) || $this->users->find($username) !== null || $this->accounts->usernameTaken($username)) {
            $errors['username'] = 'Accounts.error_username_taken';
        }

        if (\preg_match(self::DOMAIN, $domain) !== 1) {
            $errors['domain'] = 'Accounts.error_domain';
        } elseif ($this->accounts->domainTaken($domain)) {
            $errors['domain'] = 'Accounts.error_domain_taken';
        }

        if ($email !== '' && \filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Accounts.error_email';
        }

        if (\mb_strlen($name) > 120) {
            $errors['name'] = 'Accounts.error_name';
        }

        if (\mb_strlen($password) < SecurityPage::MIN_PASSWORD) {
            $errors['password'] = 'Accounts.error_password';
        }

        $ownerId = $this->owner($actor, $input['owner'] ?? null, $errors);
        $package = $this->package($actor, $ownerId, $input['package'] ?? null);

        if ($package === null) {
            $errors['package'] = 'Accounts.error_package';
        } elseif (($limit = $this->overLimit($ownerId, $package)) !== null) {
            $errors['package'] = $limit;
        }

        return [[
            'username' => $username,
            'domain' => $domain,
            'email' => $email,
            'name' => $name,
            'password' => new Secret($password),
            'owner_id' => $ownerId,
            'package_id' => $package === null ? null : (int) $package['id'],
        ], $errors];
    }

    /**
     * A package by id or name that this login may put an account of $ownerId on.
     *
     * @return array<string, mixed>|null
     */
    public function package(Identity $actor, ?int $ownerId, mixed $wanted): ?array
    {
        if (!\is_int($wanted) && !\is_string($wanted)) {
            return null;
        }

        foreach ($this->packages->usableFor($actor, $ownerId) as $row) {
            if ((string) $row['id'] === (string) $wanted || $row['name'] === $wanted) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Whether putting an account of $ownerId on $package would go past the
     * owner's limits (a reseller's allowance, answered by the Resellers
     * module through the accounts.limits filter). $account is the account
     * being moved, or null for a new one.
     *
     * @param array<string, mixed>      $package
     * @param array<string, mixed>|null $account
     *
     * @return string|null a lang key saying which limit, or null when it fits
     */
    public function overLimit(?int $ownerId, array $package, ?array $account = null): ?string
    {
        if ($ownerId === null) {
            return null;
        }

        $answer = $this->filters->apply('accounts.limits', null, $ownerId, $package, $account);

        return \is_string($answer) ? $answer : null;
    }

    public static function normaliseDomain(string $domain): string
    {
        return \rtrim(\strtolower(\trim($domain)), '.');
    }

    /**
     * Who owns the new account. A reseller always owns what they create; a
     * login with accounts.all names a reseller, or nobody for the server.
     *
     * @param array<string, string> $errors
     */
    private function owner(Identity $actor, mixed $wanted, array &$errors): ?int
    {
        if ($this->accounts->scopeOf($actor) !== null) {
            return (int) $actor->id;
        }

        if ($wanted === null || $wanted === '' || $wanted === 0 || $wanted === '0') {
            return null;
        }

        $row = \is_int($wanted) || (\is_string($wanted) && \ctype_digit($wanted))
            ? $this->users->row((string) $wanted)
            : (\is_string($wanted) ? $this->users->find($wanted) : null);

        if ($row === null || $row['kind'] !== Kind::Reseller->value) {
            $errors['owner'] = 'Accounts.error_owner';

            return null;
        }

        return (int) $row['id'];
    }
}
