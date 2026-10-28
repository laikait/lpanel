<?php

declare(strict_types=1);

namespace App\Modules\Websites\Sites;

/**
 * One website of a hosting account, as the panel wants it (ADR-0014):
 *
 *   provisioning -> active | failed
 *   active | suspended -> updating -> back where it was (new settings if served)
 *   active -> suspending -> suspended          (back to active if it fails)
 *   suspended -> resuming -> active            (back to suspended if it fails)
 *   active | suspended | failed -> deleting -> deleted   (back if it fails)
 *   active | suspended -> securing -> back where it was    (a certificate, ADR-0015)
 *   active | suspended -> updating -> back where it was    (its certificate removed)
 *
 * A status ending in -ing has an operation under way, and nothing else may
 * be asked of the website until it settles. config_state is separate: what
 * the last look at its Nginx file found.
 */
final class Website
{
    public const PROVISIONING = 'provisioning';

    public const ACTIVE = 'active';

    public const UPDATING = 'updating';

    public const SUSPENDING = 'suspending';

    public const SUSPENDED = 'suspended';

    public const RESUMING = 'resuming';

    public const DELETING = 'deleting';

    /** A certificate is being issued or renewed (ADR-0015). */
    public const SECURING = 'securing';

    public const DELETED = 'deleted';

    public const FAILED = 'failed';

    public const BUSY = [self::PROVISIONING, self::UPDATING, self::SUSPENDING, self::RESUMING, self::DELETING, self::SECURING];

    public const STATUSES = [self::PROVISIONING, self::ACTIVE, self::UPDATING, self::SUSPENDING, self::SUSPENDED, self::RESUMING, self::DELETING, self::DELETED, self::FAILED, self::SECURING];

    /** The Nginx file is LPanel's. */
    public const CONFIG_OK = 'ok';

    /** Someone edited it outside LPanel; LPanel leaves it until an administrator restores it. */
    public const CONFIG_EDITED = 'edited';

    /** It is gone from the server. */
    public const CONFIG_MISSING = 'missing';

    /**
     * Each action: its operation, the status while it runs, the statuses it may start from.
     *
     * @var array<string, array{op: string, during: string, from: list<string>}>
     */
    public const ACTIONS = [
        'create' => ['op' => 'website.create', 'during' => self::PROVISIONING, 'from' => [self::FAILED]],
        'update' => ['op' => 'website.update', 'during' => self::UPDATING, 'from' => [self::ACTIVE, self::SUSPENDED]],
        'suspend' => ['op' => 'website.suspend', 'during' => self::SUSPENDING, 'from' => [self::ACTIVE]],
        'unsuspend' => ['op' => 'website.unsuspend', 'during' => self::RESUMING, 'from' => [self::SUSPENDED]],
        'delete' => ['op' => 'website.delete', 'during' => self::DELETING, 'from' => [self::ACTIVE, self::SUSPENDED, self::FAILED]],
        'certify' => ['op' => 'website.certificate.issue', 'during' => self::SECURING, 'from' => [self::ACTIVE, self::SUSPENDED]],
        'uncertify' => ['op' => 'website.certificate.remove', 'during' => self::UPDATING, 'from' => [self::ACTIVE, self::SUSPENDED]],
    ];

    /**
     * @param array<string, mixed>      $settings        domains, document_root, php, index, redirects, headers, https_redirect
     * @param array<string, mixed>|null $pendingSettings
     */
    public function __construct(
        public readonly int $id,
        public readonly string $uid,
        public readonly int $serverId,
        public readonly int $accountId,
        public readonly string $ownerUid,
        public readonly string $domain,
        public readonly array $settings,
        public readonly ?array $pendingSettings,
        public readonly string $status,
        public readonly ?string $previousStatus,
        public readonly string $lastAction,
        public readonly ?string $operationUid,
        public readonly ?string $lastErrorCode,
        public readonly ?string $lastError,
        public readonly ?string $revision,
        public readonly string $configState,
        public readonly ?string $configCheckedAt,
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

    public function isEdited(): bool
    {
        return $this->configState !== self::CONFIG_OK;
    }

    /** @return list<string> */
    public function domains(): array
    {
        return \array_values(\array_map('strval', (array) ($this->settings['domains'] ?? [])));
    }

    public function documentRoot(string $systemUser): string
    {
        return '/home/' . $systemUser . '/web/' . (string) ($this->settings['document_root'] ?? '');
    }

    /** @return array<string, mixed> what the API shows */
    public function toArray(): array
    {
        return [
            'id' => $this->uid,
            'domain' => $this->domain,
            'status' => $this->status,
            'settings' => $this->settings,
            'pending_settings' => $this->pendingSettings,
            'last_action' => $this->lastAction,
            'operation_id' => $this->operationUid,
            'last_error' => $this->lastErrorCode === null ? null : ['code' => $this->lastErrorCode, 'message' => $this->lastError],
            'revision' => $this->revision,
            'config' => ['state' => $this->configState, 'checked_at' => $this->configCheckedAt],
            'suspended_at' => $this->suspendedAt,
            'deleted_at' => $this->deletedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
