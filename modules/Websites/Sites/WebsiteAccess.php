<?php

declare(strict_types=1);

namespace App\Modules\Websites\Sites;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Modules\Audit\AuditLog;

/**
 * Who may see which website (THREAT_MODEL.md test 1), as AccountAccess does
 * for accounts: administrators (websites.read) see every one, a customer only
 * its own and not once deleted. Any other id is answered as one that does not
 * exist, 404, and the attempt is audited.
 */
final class WebsiteAccess
{
    public function __construct(
        private readonly WebsiteStore $websites,
        private readonly Authorizer $authorizer,
        private readonly AuditLog $audit,
    ) {}

    public function seesAll(Identity $identity): bool
    {
        return $this->authorizer->allows($identity, 'websites.read');
    }

    /** The owner to scope a list to: null for administrators, the customer's own id otherwise. */
    public function ownerScope(Identity $identity): ?string
    {
        return $this->seesAll($identity) ? null : $identity->id;
    }

    /**
     * The website $uid, if $identity may see it; otherwise an audited 404.
     *
     * @throws HttpException
     */
    public function find(Identity $identity, string $uid, string $action = 'view'): Website
    {
        $website = $this->websites->byUid($uid);

        if ($website !== null && ($this->seesAll($identity) || ($website->ownerUid === $identity->id && $website->status !== Website::DELETED))) {
            return $website;
        }

        $this->audit->record('website.access', 'denied', $identity, 'website', \substr($uid, 0, 26), ['action' => $action, 'reason' => $website === null ? 'unknown' : 'not_owner']);

        throw HttpException::notFound();
    }
}
