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
        private readonly CertificateStore $certificates,
    ) {}

    /** Audit names of the actions that are not website.<action>. */
    private const AUDIT = ['certify' => 'website.certificate.issue', 'uncertify' => 'website.certificate.remove'];

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
     * Get the website a certificate now, or renew it ("issue"), or serve it
     * over HTTP only again ("remove"), which also stops LPanel asking on its
     * own (ADR-0015). The owner may do either, like its settings.
     *
     * @throws OperationException
     */
    public function certificate(Identity $by, Website $website, string $what): Website
    {
        $action = ['issue' => 'certify', 'remove' => 'uncertify'][$what] ?? throw new OperationException('unknown_action', 'A certificate can be issued or removed.', 422);

        if (!$this->manages($by) && !($this->authorizer->allows($by, 'websites.configure') && $website->ownerUid === $by->id)) {
            $this->deny($by, $action, $website, 'websites.configure');
        }

        if (!$website->allows($action)) {
            throw self::busy($website, $action);
        }

        if ($website->isEdited()) {
            throw new OperationException('config_edited', $this->manages($by)
                ? 'Its Nginx file was edited outside LPanel. Restore LPanel\'s version first.'
                : 'An administrator is looking at this website\'s configuration. Try again once they are done.', 409);
        }

        $account = $this->accountOf($website);
        self::accountReady($account);
        $server = $this->serverOf($account);

        $this->websites->transaction(function () use ($by, $server, $account, $website, $action): void {
            if ($action === 'certify') {
                // Asked for by a person: LPanel tries on its own again, from the start.
                $this->certificates->wanted($website->id, null);
            }
            $this->start($by, $server, $account, $website, $action, []);
        });

        return $this->websites->byUid($website->uid) ?? $website;
    }

    /**
     * Ask for the certificates that are due (certificates:renew, every few
     * minutes): first ones, renewals, and reissues for new domains, at most
     * $limit of them. Websites busy or edited by hand wait for the next run.
     *
     * @return array{started: list<string>, failed: array<string, string>} website ids
     */
    public function renewDue(Identity $by, int $limit = 20): array
    {
        $now = CertificateStore::now();
        $started = [];
        $failed = [];

        // Websites served before LPanel had certificates get their first now.
        $served = \array_map(static fn(Website $w): int => $w->id, $this->websites->page(null, 'active', limit: 500));
        foreach ($this->certificates->unknown($served) as $id) {
            $this->certificates->wanted($id, $now);
        }

        foreach ($this->certificates->due($now, $limit) as $cert) {
            $website = $this->websites->byId($cert->websiteId);

            if ($website === null || $website->status === Website::DELETED) {
                $this->certificates->cleared($cert->websiteId, false);

                continue;
            }

            // A first certificate only for a website people can reach; a renewal while suspended too.
            if ($website->isBusy() || $website->isEdited() || !$website->allows('certify') || (!$cert->exists() && $website->status !== Website::ACTIVE)) {
                continue;
            }

            try {
                $account = $this->accountOf($website);
                self::accountReady($account);
                $server = $this->serverOf($account);
                $this->websites->transaction(function () use ($by, $server, $account, $website): void {
                    $this->start($by, $server, $account, $website, 'certify', []);
                });
                $started[] = $website->uid;
            } catch (OperationException $e) {
                // Not now (the agent is offline, too old, or the account is busy): the usual wait.
                $this->certificates->failed($website->id, $e->errorCode, $e->getMessage());
                $failed[$website->uid] = $e->getMessage();
            }
        }

        return ['started' => $started, 'failed' => $failed];
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
                $this->settleCertificate($website, $action, $result, $domains ?? []);
                $this->audit->record(self::AUDIT[$action] ?? 'website.' . $action, 'succeeded', null, 'website', $website->uid, ['operation' => $op->uid]);
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
        // A certificate the authority did not issue is the certificate's
        // story, not the website's, which is served as before.
        $quiet = $action === 'certify' && !$forged && \in_array($code, ['certificate_failed', 'precondition_failed'], true);
        $errors = $quiet ? [] : ['last_error_code' => $code, 'last_error' => \mb_substr($message, 0, 500)];

        if ($this->websites->settle($website, $op->uid, $back, $errors, $domains)) {
            if ($action === 'certify') {
                $this->certificates->failed($website->id, $code, $message);
            }
            $this->audit->record(self::AUDIT[$action] ?? 'website.' . $action, 'failed', null, 'website', $website->uid, ['operation' => $op->uid, 'error' => $code]);
        }
    }

    /**
     * What a website's operation says about its certificate. Every result
     * carries the one the server serves it with; issuing and removing
     * change it, a new website and a new domain make LPanel ask for one.
     *
     * @param array<string, mixed> $result
     * @param list<string>         $domains the website's, now
     */
    private function settleCertificate(Website $website, string $action, array $result, array $domains): void
    {
        $served = \is_array($result['certificate'] ?? null) ? $result['certificate'] : null;
        $known = $this->certificates->forWebsite($website->id);

        match (true) {
            $action === 'delete' => $this->certificates->cleared($website->id, false),
            $action === 'uncertify' => $this->certificates->cleared($website->id, false),
            $action === 'certify' && $served !== null => $this->certificates->installed($website->id, $served),
            $served !== null && ($served['serial'] ?? null) !== $known->serial => $this->certificates->installed($website->id, $served),
            $served === null && $known->exists() => $this->certificates->cleared($website->id, $known->auto),
            default => null,
        };

        $known = $this->certificates->forWebsite($website->id);

        if ($action === 'create' && !$known->stored) {
            $this->certificates->wanted($website->id);
        } elseif ($known->auto && $known->exists() && !$known->covers($domains)) {
            $this->certificates->wanted($website->id);
        }
    }

    private function settleCheck(Operation $op): void
    {
        if ($op->status !== Operation::SUCCEEDED) {
            return;
        }

        $result = $op->resultArray() ?? [];
        $ids = static fn(mixed $list): array => \array_values(\array_filter(\is_array($list) ? $list : [], 'is_string'));

        // A certificate file missing or changed on the server: a new one
        // replaces it (under a new name, so Nginx loads again), soon.
        foreach ($ids($result['certificates'] ?? null) as $uid) {
            $website = $this->websites->byUid($uid);

            if ($website !== null && $website->serverId === $op->serverId && $website->status !== Website::DELETED) {
                $this->certificates->wanted($website->id);
                $this->audit->record('website.certificate.drift', 'failed', null, 'website', $website->uid, ['found_by' => 'websites.check', 'operation' => $op->uid]);
            }
        }

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

        $this->audit->record(self::AUDIT[$action] ?? 'website.' . ($action === 'update' && ($payload['replace_edits'] ?? false) === true ? 'restore' : $action), 'allowed', $by, 'website', $website->uid, [
            'server' => $server->uid,
            'domain' => $website->domain,
            'account' => $account->uid,
            'operation' => $op->uid,
        ]);
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array{domains: list<string>, document_root: string, php: bool, index: list<string>, redirects: list<array{from: string, to: string, code: int}>, headers: list<array{name: string, value: string}>, https_redirect: bool}
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
            : \sprintf('A website that is %s cannot be %s.', $website->status, ['create' => 'created again', 'update' => 'changed', 'suspend' => 'suspended', 'unsuspend' => 'unsuspended', 'delete' => 'deleted', 'certify' => 'given a certificate', 'uncertify' => 'served without its certificate'][$action] ?? $action), 409);
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
        $this->audit->record(self::AUDIT[$action] ?? 'website.' . $action, 'denied', $by, 'website', $website?->uid, ['reason' => $reason]);

        throw new OperationException('forbidden', $reason === 'domains'
            ? 'Domains and the website\'s folder are set by an administrator.'
            : 'Only administrators do this to websites.', 403);
    }

    private static function isConsole(Identity $identity): bool
    {
        return $identity->attribute('system') === true;
    }
}
