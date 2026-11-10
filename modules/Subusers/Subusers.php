<?php

declare(strict_types=1);

namespace App\Modules\Subusers;

use App\Engine\Auth\Capability;
use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Security\Secret;
use App\Modules\Accounts\Accounts;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Packages\Packages;

/**
 * Sub-users: extra logins an account owner makes for the people who help
 * with the site (a developer, a bookkeeper), each granted some of the
 * account's features.
 *
 * Features are capabilities, `feature.<name>` (Packages::FEATURES). An owner
 * holds those their package includes; a sub-user holds those their owner
 * granted, and only while the package includes them. Feature screens (from
 * M2 on) protect their routes with `can: feature.<name>`.
 */
final class Subusers
{
    /** The part after "owner.": a letter or digit, then letters, digits, dash or underscore. */
    public const SUFFIX = '/^[a-z0-9][a-z0-9_-]{0,14}$/';

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly PanelUsers $users,
        private readonly Accounts $accounts,
        private readonly Packages $packages,
    ) {}

    /**
     * The hosting account an owner or sub-user login belongs to.
     *
     * @return array<string, mixed>|null
     */
    public function accountOf(Identity $identity): ?array
    {
        $loginId = match (Kind::tryFrom((string) $identity->attribute('kind'))) {
            Kind::User => $identity->id,
            Kind::Subuser => $identity->attribute('parent_id'),
            default => null,
        };

        return \is_string($loginId) && $loginId !== '' ? $this->accounts->byLogin($loginId) : null;
    }

    /**
     * The features an account's package includes.
     *
     * @param array<string, mixed> $account
     *
     * @return list<string>
     */
    public function packageFeatures(array $account): array
    {
        $package = $this->packages->byId((int) $account['package_id']);

        return \is_array($package['features'] ?? null) ? \array_values(\array_filter($package['features'], 'is_string')) : [];
    }

    /** @return list<array<string, mixed>> an owner's sub-users, with their grants */
    public function of(string $ownerId): array
    {
        return \array_map(fn(array $row): array => $row + ['grants' => $this->grants((int) $row['id'])], $this->users->children($ownerId));
    }

    /** @return array<string, mixed>|null one of an owner's sub-users */
    public function find(string $ownerId, string $id): ?array
    {
        $row = $this->users->row($id);

        return $row !== null && $row['kind'] === Kind::Subuser->value && (string) $row['parent_id'] === $ownerId
            ? $row + ['grants' => $this->grants((int) $row['id'])]
            : null;
    }

    /** @return list<string> */
    public function grants(int $userId): array
    {
        $row = $this->table()->where('user_id', $userId)->first();
        $grants = $row === null ? [] : \json_decode((string) $row['grants'], true);

        return \is_array($grants) ? \array_values(\array_intersect(Packages::FEATURES, $grants)) : [];
    }

    /**
     * Check a new sub-user.
     *
     * @param array<string, mixed> $input  suffix, name, email, password, grants
     * @param array<string, mixed> $owner  the owner's panel_users row
     * @param list<string>         $offered the features the owner may grant
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public function validateNew(array $input, array $owner, array $offered): array
    {
        $errors = [];
        $suffix = \strtolower(\trim((string) ($input['suffix'] ?? '')));
        $username = $owner['username'] . '.' . $suffix;
        $email = \trim((string) ($input['email'] ?? ''));

        if (\preg_match(self::SUFFIX, $suffix) !== 1 || \preg_match(PanelUsers::STAFF_USERNAME, $username) !== 1) {
            $errors['suffix'] = 'Subusers.error_suffix';
        } elseif ($this->users->find($username) !== null) {
            $errors['suffix'] = 'Subusers.error_taken';
        }

        if ($email !== '' && \filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Subusers.error_email';
        }

        if (\mb_strlen((string) ($input['password'] ?? '')) < 12) {
            $errors['password'] = 'Subusers.error_password';
        }

        return [[
            'username' => $username,
            'name' => \mb_substr(\trim((string) ($input['name'] ?? '')), 0, 120),
            'email' => $email !== '' ? $email : null,
            'password' => new Secret((string) ($input['password'] ?? '')),
            'grants' => self::onlyOffered($input['grants'] ?? [], $offered),
        ], $errors];
    }

    /** @param array<string, mixed> $clean from validateNew() */
    public function create(string $ownerId, array $clean): string
    {
        $id = $this->users->create(Kind::Subuser, $clean['username'], $clean['name'], $clean['email'], $clean['password'], (int) $ownerId);
        $this->setGrants((int) $id, $clean['grants']);

        return $id;
    }

    /** @param list<string> $grants */
    public function setGrants(int $userId, array $grants): void
    {
        $values = ['grants' => \json_encode(\array_values($grants), \JSON_THROW_ON_ERROR)];
        $now = \gmdate('Y-m-d H:i:s');

        if ($this->table()->where('user_id', $userId)->first() === null) {
            $this->table()->insert($values + ['user_id' => $userId, 'created_at' => $now]);
        } else {
            $this->table()->where('user_id', $userId)->update($values + ['updated_at' => $now]);
        }
    }

    /**
     * Only the features offered, in their usual order.
     *
     * @param list<string> $offered
     *
     * @return list<string>
     */
    public static function onlyOffered(mixed $wanted, array $offered): array
    {
        $wanted = \is_array($wanted) ? \array_filter($wanted, 'is_string') : [];

        return \array_values(\array_intersect(Packages::FEATURES, $offered, $wanted));
    }

    // ---- Listeners ----------------------------------------------------------

    /** authorization.decision: feature.* only within the package, and a sub-user's grants. */
    public function enforceFeatures(bool $allowed, Capability $capability, Identity $identity): bool
    {
        if (!$allowed || !\str_starts_with($capability->name, 'feature.')) {
            return $allowed;
        }

        $kind = Kind::tryFrom((string) $identity->attribute('kind'));

        // Staff hold feature.* through "*" but have no account to use them on.
        if ($kind !== Kind::User && $kind !== Kind::Subuser) {
            return false;
        }

        $feature = \substr($capability->name, 8);
        $account = $this->accountOf($identity);

        if ($account === null || !\in_array($feature, $this->packageFeatures($account), true)) {
            return false;
        }

        return $kind === Kind::User || \in_array($feature, $this->grants((int) $identity->id), true);
    }

    /**
     * identity.deleted: forget the grants of removed logins.
     *
     * @param list<int> $ids
     */
    public function forget(array $ids): void
    {
        if ($ids !== []) {
            $this->table()->whereIn('user_id', $ids)->delete();
        }
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('subuser_grants');
    }
}
