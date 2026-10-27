<?php

declare(strict_types=1);

namespace App\Modules\Identity\Users;

/** One row of users, with its roles. Secrets stay encrypted or hashed in here. */
final class User
{
    public const KIND_SYSTEM = 'system';

    public const KIND_PANEL = 'panel';

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    /**
     * @param list<string> $roles          as stored; see effectiveRoles()
     * @param list<string> $recoveryHashes
     */
    public function __construct(
        public readonly int $id,
        public readonly string $uid,
        public readonly string $login,
        public readonly string $name,
        public readonly ?string $email,
        public readonly string $kind,
        public readonly string $status,
        public readonly ?string $passwordHash,
        public readonly array $roles,
        public readonly ?string $totpSecretEncrypted,
        public readonly ?int $totpLastStep,
        public readonly array $recoveryHashes,
        public readonly ?string $lastLoginAt,
        public readonly int $sessionEpoch = 1,
        public readonly ?string $createdAt = null,
    ) {}

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function hasTwoFactor(): bool
    {
        return $this->totpSecretEncrypted !== null;
    }

    public function isRoot(): bool
    {
        return $this->kind === self::KIND_SYSTEM && $this->login === UserStore::ROOT_LOGIN;
    }

    /**
     * The roles this user acts with: root alone for root, and never root for
     * anyone else, whatever the table says.
     *
     * @return list<string>
     */
    public function effectiveRoles(): array
    {
        if ($this->isRoot()) {
            return [Roles::ROOT];
        }

        return \array_values(\array_filter($this->roles, static fn(string $role): bool => $role !== Roles::ROOT));
    }

    /** @return array<string, mixed> what the API shows: no hashes, no secrets */
    public function toArray(): array
    {
        return [
            'id' => $this->uid,
            'login' => $this->login,
            'name' => $this->name,
            'email' => $this->email,
            'kind' => $this->kind,
            'status' => $this->status,
            'roles' => $this->effectiveRoles(),
            'two_factor' => $this->hasTwoFactor(),
            'last_login_at' => $this->lastLoginAt,
            'created_at' => $this->createdAt,
        ];
    }
}
