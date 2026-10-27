<?php

declare(strict_types=1);

namespace App\Modules\Hosting\Accounts;

/**
 * One hosting account, as the panel wants it (ADR-0013):
 *
 *   provisioning -> active | failed
 *   active -> suspending -> suspended          (back to active if it fails)
 *   suspended -> resuming -> active            (back to suspended if it fails)
 *   active | suspended | failed -> deleting -> deleted   (back if it fails)
 *
 * A status ending in -ing has an operation under way (operation_uid), and
 * nothing else may be asked of the account until it settles.
 */
final class Account
{
    public const PROVISIONING = 'provisioning';

    public const ACTIVE = 'active';

    public const SUSPENDING = 'suspending';

    public const SUSPENDED = 'suspended';

    public const RESUMING = 'resuming';

    public const DELETING = 'deleting';

    public const DELETED = 'deleted';

    public const FAILED = 'failed';

    /** An operation is under way. */
    public const BUSY = [self::PROVISIONING, self::SUSPENDING, self::RESUMING, self::DELETING];

    public const STATUSES = [self::PROVISIONING, self::ACTIVE, self::SUSPENDING, self::SUSPENDED, self::RESUMING, self::DELETING, self::DELETED, self::FAILED];

    /**
     * Each action: the operation it asks for, the status while it runs, and the statuses it may start from.
     *
     * @var array<string, array{op: string, during: string, from: list<string>}>
     */
    public const ACTIONS = [
        'create' => ['op' => 'account.create', 'during' => self::PROVISIONING, 'from' => [self::FAILED]],
        'suspend' => ['op' => 'account.suspend', 'during' => self::SUSPENDING, 'from' => [self::ACTIVE]],
        'unsuspend' => ['op' => 'account.unsuspend', 'during' => self::RESUMING, 'from' => [self::SUSPENDED]],
        'delete' => ['op' => 'account.delete', 'during' => self::DELETING, 'from' => [self::ACTIVE, self::SUSPENDED, self::FAILED]],
    ];

    /** @param array<string, mixed> $limits */
    public function __construct(
        public readonly int $id,
        public readonly string $uid,
        public readonly int $serverId,
        public readonly string $ownerUid,
        public readonly string $label,
        public readonly string $systemUser,
        public readonly ?int $systemUid,
        public readonly ?int $systemGid,
        public readonly ?string $phpVersion,
        public readonly string $status,
        public readonly ?string $previousStatus,
        public readonly string $lastAction,
        public readonly ?string $operationUid,
        public readonly ?string $lastErrorCode,
        public readonly ?string $lastError,
        public readonly array $limits,
        public readonly ?string $createdBy,
        public readonly ?string $suspendedAt,
        public readonly ?string $deletedAt,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
    ) {}

    public function isBusy(): bool
    {
        return \in_array($this->status, self::BUSY, true);
    }

    /** Whether $action may be asked for now. "create" again is the retry of a failed one. */
    public function allows(string $action): bool
    {
        return isset(self::ACTIONS[$action])
            && \in_array($this->status, self::ACTIONS[$action]['from'], true)
            && ($action !== 'create' || $this->lastAction === 'create');
    }

    public function home(): string
    {
        return '/home/' . $this->systemUser;
    }

    /** @return array<string, mixed> what the API shows */
    public function toArray(): array
    {
        return [
            'id' => $this->uid,
            'label' => $this->label,
            'status' => $this->status,
            'system_user' => $this->systemUser,
            'uid' => $this->systemUid,
            'gid' => $this->systemGid,
            'home' => $this->home(),
            'php_version' => $this->phpVersion,
            'last_action' => $this->lastAction,
            'operation_id' => $this->operationUid,
            'last_error' => $this->lastErrorCode === null ? null : ['code' => $this->lastErrorCode, 'message' => $this->lastError],
            'suspended_at' => $this->suspendedAt,
            'deleted_at' => $this->deletedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
