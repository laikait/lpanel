<?php

declare(strict_types=1);

namespace App\Modules\Websites\Sites;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Modules\Audit\AuditLog;
use App\Modules\Hosting\Accounts\Account;
use App\Modules\Hosting\Accounts\AccountStore;
use App\Modules\Operations\Catalogue\Catalogue;
use App\Modules\Operations\OperationException;
use App\Modules\Operations\OperationRequests;
use App\Modules\Operations\Store\Operation;
use App\Modules\Operations\Store\OperationStore;
use App\Modules\Servers\Store\Server;
use App\Modules\Servers\Store\ServerStore;

/**
 * Creating, changing, suspending and deleting websites (ADR-0014), checking
 * their Nginx files, and settling each when its operation finishes.
 *
 * Administrators (websites.manage) do everything. A customer with
 * websites.configure may change the managed settings of its own websites:
 * index files, PHP on or off, redirects and headers, but not its domains or
 * folder, which an administrator chose. Every refusal is audited.
 */
final class WebsiteActions
{
    public function __construct(
        private readonly WebsiteStore $websites,
        private readonly AccountStore $accounts,
        private readonly OperationRequests $requests,
        private readonly OperationStore $operations,
        private readonly Catalogue $catalogue,
        private readonly ServerStore $servers,
        private readonly Authorizer $authorizer,
        private readonly AuditLog $audit,
    ) {}

    /**
     * A new website for $account.
     *
     * @param array<string, mixed> $raw settings as a form or a JSON body has them
     *
     * @throws OperationException
     */
    public function create(Identity $by, Account $account, array $raw): Website
    {
        $this->mayManage($by, 'create', null);
        $server = $this->serverOf($account);
        self::accountReady($account);
        $settings = self::settings($raw);

        $website = $this->websites->transaction(function () use ($by, $server, $account, $settings): Website {
            $website = $this->websites->create($server->id, $account->id, $account->ownerUid, $settings, self::isConsole($by) ? null : $by->id)
                ?? throw self::taken($this->websites->taken($server->id, $settings['domains'], null));
            $this->start($by, $server, $account, $website, 'create', $settings);

            return $website;
        });

        return $this->websites->byUid($website->uid) ?? $website;
    }

    /**
     * New settings for $website. $restore is an administrator's "restore
     * LPanel's version": it replaces a file someone edited by hand.
     *
     * @param array<string, mixed> $raw
     *
     * @throws OperationException
     */
    public function update(Identity $by, Website $website, array $raw, bool $restore = false): Website
    {
        $manager = $this->manages($by);

        if ($restore && !$manager) {
            $this->deny($by, 'restore', $website, 'websites.manage');
        }

        if (!$manager && !($this->authorizer->allows($by, 'websites.configure') && $website->ownerUid === $by->id)) {
            $this->deny($by, 'update', $website, 'websites.configure');
        }

        if (!$website->allows('update')) {
            throw self::busy($website, 'update');
        }

        if ($website->isEdited() && !$restore) {
            throw new OperationException('config_edited', $manager
                ? 'Its Nginx file was edited outside LPanel. Restore LPanel\'s version first; the edit is then replaced.'
                : 'An administrator is looking at this website\'s configuration. Settings can be changed again once they are done.', 409);
        }

        $settings = self::settings($raw);

        if (!$manager && ($settings['domains'] !== $website->domains() || $settings['document_root'] !== ($website->settings['document_root'] ?? null))) {
            $this->deny($by, 'update', $website, 'domains');
        }

        $account = $this->accountOf($website);
        self::accountReady($account);
        $server = $this->serverOf($account);
        $taken = $this->websites->taken($server->id, $settings['domains'], $website->id);

        if ($taken !== []) {
            throw self::taken($taken);
        }

        $this->websites->transaction(function () use ($by, $server, $account, $website, $settings, $restore): void {
            $this->start($by, $server, $account, $website, 'update', $settings + ['replace_edits' => $restore], $settings);
        });

        return $this->websites->byUid($website->uid) ?? $website;
    }

    /**
     * suspend, unsuspend, delete, or create again after it failed. Deleting
     * asks for the primary domain, typed.
     *
     * @throws OperationException
     */
    public function act(Identity $by, Website $website, string $action, ?string $confirm = null): Website
    {
        $this->mayManage($by, $action, $website);

        if (!isset(Website::ACTIONS[$action]) || $action === 'update') {
            throw new OperationException('unknown_action', 'Websites can be suspended, unsuspended, deleted, or created again.', 422);
        }

        if (!$website->allows($action)) {
            throw self::busy($website, $action);
        }

        // The agent would refuse it too (config_edited): suspending rewrites the file.
        if (\in_array($action, ['suspend', 'unsuspend'], true) && $website->isEdited()) {
            throw new OperationException('config_edited', 'Its Nginx file was edited outside LPanel. Restore LPanel\'s version first.', 409);
        }

        if ($action === 'delete' && $confirm !== $website->domain) {
            throw new OperationException('confirmation_required', \sprintf('Type the website\'s domain, %s, to delete it.', $website->domain), 422);
        }

        $account = $this->accountOf($website);

        if ($action !== 'delete') {
            self::accountReady($account);
        }

        $server = $this->serverOf($account);
        $payload = $action === 'create' ? $website->settings : [];

        $this->websites->transaction(function () use ($by, $server, $account, $website, $action, $payload): void {
            $this->start($by, $server, $account, $website, $action, $payload);
        });

        return $this->websites->byUid($website->uid) ?? $website;
    }

    /**
     * Ask $server's agent to compare every website's Nginx file with what
     * LPanel wrote. One already queued or running is returned instead.
     *
     * @throws OperationException
     */
    public function check(Identity $by, Server $server): Operation
    {
        $this->mayManage($by, 'check', null);
        $definition = $this->catalogue->latest('websites.check') ?? throw new \LogicException('No websites.check in the catalogue.');
        $live = $this->operations->liveFor($server, $definition);

        if ($live !== null) {
            return $live;
        }

        [$op] = $this->requests->request($by, $server, 'websites.check', null, new \stdClass());

        return $op;
    }

    /**
     * An operation finished (operations.finished, in its own transaction):
     * a website's moves it on or back; a check records what it found.
     */
    public function settle(Operation $op): void
    {
        if ($op->op === 'websites.check') {
            $this->settleCheck($op);

            return;
        }

        if ($op->targetType !== 'website') {
            return;
        }

        $website = $this->websites->byUid($op->targetUid);

        if ($website === null || $website->operationUid !== $op->uid || !$website->isBusy()) {
            return;
        }

        $action = $website->lastAction;
        $account = $this->accounts->byId($website->accountId);
        $result = $op->resultArray() ?? [];
        // A result about another website or user is not this one's (THREAT_MODEL T5).
        $forged = $op->status === Operation::SUCCEEDED && (($result['website_id'] ?? null) !== $website->uid || ($result['system_user'] ?? null) !== $account?->systemUser);

        if ($op->status === Operation::SUCCEEDED && !$forged) {
            $to = match ($action) {
                'create', 'unsuspend' => Website::ACTIVE,
                'suspend' => Website::SUSPENDED,
                'delete' => Website::DELETED,
                default => $website->previousStatus ?? Website::ACTIVE,
            };
            $settings = $action === 'update' ? ($website->pendingSettings ?? $website->settings) : $website->settings;
            $changes = $action === 'delete' ? [] : [
                'revision' => (string) ($result['revision'] ?? ''),
                'config_state' => Website::CONFIG_OK,
                'config_checked_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                'settings' => \json_encode($settings, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
                'domain' => (string) ($settings['domains'][0] ?? $website->domain),
            ];
            /** @var list<string> $domains */
            $domains = $action === 'delete' ? null : \array_values((array) ($settings['domains'] ?? []));

            if ($this->websites->settle($website, $op->uid, $to, $changes, $domains)) {
                $this->audit->record('website.' . $action, 'succeeded', null, 'website', $website->uid, ['operation' => $op->uid]);
            }

            return;
        }

        $back = $action === 'create' ? Website::FAILED : ($website->previousStatus ?? Website::FAILED);
        $message = $forged ? 'The agent reported on another website. Nothing was recorded; check the server, then try again.' : match ($op->status) {
            Operation::NEEDS_ATTENTION => 'The server may have changed part-way: ' . ($op->message ?? 'the agent stopped reporting.') . ' Look at the server before trying again.',
            Operation::EXPIRED => 'The server\'s agent did not pick it up in time. Is the server online?',
            Operation::CANCELLED => $op->errorCode === 'agent_revoked' ? 'The server\'s agent was revoked before it finished.' : 'Cancelled.',
            default => $op->message ?? 'The operation failed.',
        };
        $code = $forged ? 'invalid_result' : ($op->errorCode ?? $op->status);

        if ($op->errorCode === 'config_edited') {
            $this->websites->markEdited($website);
            $this->audit->record('website.drift', 'failed', null, 'website', $website->uid, ['state' => Website::CONFIG_EDITED, 'found_by' => 'website.' . $action]);
        }

        // What stays reserved: the domains it is served with (none, if it never was).
        /** @var list<string> $domains */
        $domains = \array_values((array) ($website->settings['domains'] ?? []));

        if ($this->websites->settle($website, $op->uid, $back, ['last_error_code' => $code, 'last_error' => \mb_substr($message, 0, 500)], $domains)) {
            $this->audit->record('website.' . $action, 'failed', null, 'website', $website->uid, ['operation' => $op->uid, 'error' => $code]);
        }
    }

    private function settleCheck(Operation $op): void
    {
        if ($op->status !== Operation::SUCCEEDED) {
            return;
        }

        $result = $op->resultArray() ?? [];
        $ids = static fn(mixed $list): array => \array_values(\array_filter(\is_array($list) ? $list : [], 'is_string'));

        foreach ($this->websites->recordCheck($op->serverId, $ids($result['edited'] ?? null), $ids($result['missing'] ?? null)) as $website) {
            $state = \in_array($website->uid, $ids($result['missing'] ?? null), true) ? Website::CONFIG_MISSING : Website::CONFIG_EDITED;
            $this->audit->record('website.drift', 'failed', null, 'website', $website->uid, [
                'state' => $state,
                'found_by' => 'websites.check',
                'operation' => $op->uid,
                'reloaded' => ($result['reloaded'] ?? false) === true,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload settings (create, update) or nothing
     * @param array<string, mixed>|null $pending the settings of an update
     *
     * @throws OperationException
     */
    private function start(Identity $by, Server $server, Account $account, Website $website, string $action, array $payload, ?array $pending = null): void
    {
        $body = ['account_id' => $account->uid, 'system_user' => $account->systemUser] + $payload;
        // As json_decode(..., false) has it: objects \stdClass, lists arrays.
        $json = \json_decode(\json_encode($body, \JSON_THROW_ON_ERROR), false, 16, \JSON_THROW_ON_ERROR);

        [$op] = $this->requests->request($by, $server, Website::ACTIONS[$action]['op'], null, $json, ['type' => 'website', 'uid' => $website->uid]);

        if (!$this->websites->begin($website, $action, $op->uid, $pending)) {
            throw new OperationException('website_busy', 'The website changed meanwhile, or a domain was just given to another website. Look again, then try.', 409);
        }

        $this->audit->record('website.' . ($action === 'update' && ($payload['replace_edits'] ?? false) === true ? 'restore' : $action), 'allowed', $by, 'website', $website->uid, [
            'server' => $server->uid,
            'domain' => $website->domain,
            'account' => $account->uid,
            'operation' => $op->uid,
        ]);
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array{domains: list<string>, document_root: string, php: bool, index: list<string>, redirects: list<array{from: string, to: string, code: int}>, headers: list<array{name: string, value: string}>}
     *
     * @throws OperationException
     */
    private static function settings(array $raw): array
    {
        $checked = WebsiteSettings::normalize($raw);

        if ($checked['errors'] !== []) {
            throw new OperationException('settings_invalid', (string) \reset($checked['errors']), 422);
        }

        return $checked['settings'];
    }

    /** @param list<string> $domains */
    private static function taken(array $domains): OperationException
    {
        return new OperationException('domain_taken', \sprintf('%s already belongs to another website on this server.', \implode(', ', $domains) ?: 'A domain'), 409);
    }

    private static function busy(Website $website, string $action): OperationException
    {
        return new OperationException('website_busy', $website->isBusy()
            ? 'Something is already under way for this website. Wait for it to finish.'
            : \sprintf('A website that is %s cannot be %s.', $website->status, ['create' => 'created again', 'update' => 'changed', 'suspend' => 'suspended', 'unsuspend' => 'unsuspended', 'delete' => 'deleted'][$action] ?? $action), 409);
    }

    /** @throws OperationException */
    private static function accountReady(Account $account): void
    {
        if (!\in_array($account->status, [Account::ACTIVE, Account::SUSPENDED], true)) {
            throw new OperationException('account_not_ready', \sprintf('Its hosting account is %s. Websites change once the account is active or suspended.', $account->status), 409);
        }
    }

    private function accountOf(Website $website): Account
    {
        return $this->accounts->byId($website->accountId) ?? throw new \LogicException('A website without its account.');
    }

    private function serverOf(Account $account): Server
    {
        return $this->servers->byId($account->serverId) ?? throw new \LogicException('An account without its server.');
    }

    private function manages(Identity $by): bool
    {
        return self::isConsole($by) || $this->authorizer->allows($by, 'websites.manage');
    }

    /** @throws OperationException */
    private function mayManage(Identity $by, string $action, ?Website $website): void
    {
        if (!$this->manages($by)) {
            $this->deny($by, $action, $website, 'websites.manage');
        }
    }

    /** @throws OperationException */
    private function deny(Identity $by, string $action, ?Website $website, string $reason): never
    {
        $this->audit->record('website.' . $action, 'denied', $by, 'website', $website?->uid, ['reason' => $reason]);

        throw new OperationException('forbidden', $reason === 'domains'
            ? 'Domains and the website\'s folder are set by an administrator.'
            : 'Only administrators do this to websites.', 403);
    }

    private static function isConsole(Identity $identity): bool
    {
        return $identity->attribute('system') === true;
    }
}
