<?php

declare(strict_types=1);

namespace App\Engine\System\Audit;

/**
 * How an audited operation ended.
 *
 * Refused is kept apart from Failed because they are read by different people.
 * A failure is an operator's problem -- a disk was full, a service would not
 * start. A refusal is a security event: something asked for what a policy or
 * a role does not allow, and a run of them is worth somebody's attention.
 */
enum AuditOutcome: string
{
    /** It began; a later record says how it ended. */
    case Started = 'started';

    case Succeeded = 'succeeded';

    case Failed = 'failed';

    /** A policy, an allowlist or an authorization said no. Nothing ran. */
    case Refused = 'refused';
}
