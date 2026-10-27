<?php

declare(strict_types=1);

namespace App\Modules\Hosting\Accounts;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Modules\Audit\AuditLog;

/**
 * Who may see which account (THREAT_MODEL.md test 1). Administrators
 * (accounts.read) see every one; a customer sees only its own, and not once
 * deleted. Every id that is not visible is answered exactly as one that does
 * not exist, 404, and the attempt is audited, so trying ids is both useless
 * and noticed.
 */
final class AccountAccess
{
    public function __construct(
        private readonly AccountStore $accounts,
        private readonly Authorizer $authorizer,
        private readonly AuditLog $audit,
    ) {}

    public function seesAll(Identity $identity): bool
    {
        return $this->authorizer->allows($identity, 'accounts.read');
    }

    /** The owner to scope a list to: null for administrators, the customer's own id otherwise. */
    public function ownerScope(Identity $identity): ?string
    {
        return $this->seesAll($identity) ? null : $identity->id;
    }

    /**
     * The account $uid, if $identity may see it; otherwise an audited 404.
     *
     * @throws HttpException
     */
    public function find(Identity $identity, string $uid, string $action = 'view'): Account
    {
        $account = $this->accounts->byUid($uid);

        if ($account !== null && ($this->seesAll($identity) || ($account->ownerUid === $identity->id && $account->status !== Account::DELETED))) {
            return $account;
        }

        $this->audit->record('account.access', 'denied', $identity, 'account', \substr($uid, 0, 26), ['action' => $action, 'reason' => $account === null ? 'unknown' : 'not_owner']);

        throw HttpException::notFound();
    }
}
