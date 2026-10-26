<?php

declare(strict_types=1);

namespace App\Engine\System\Security;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\System\Audit\AuditOutcome;
use App\Engine\System\Audit\SystemAudit;

/**
 * Whether an identity may perform a system operation, through the ordinary
 * authorizer.
 *
 *     $system->authorize($identity, SystemCapability::ServiceRestart, 'nginx.service');
 *     $services->restart('nginx');
 *
 * **Two separate questions, both asked.** This one is "may this person": roles
 * grant the capability, and a module's `authorization.decision` listener may
 * narrow it by target, since it receives a SystemOperation. The other is "will
 * the application do this at all", which is the policy each manager was built
 * with -- ServicePolicy, FilesystemPolicy, the owners PermissionManager may
 * assign. An operation happens only when both say yes, and neither can widen
 * the other.
 *
 * **The managers know nobody.** ServiceManager, SystemFilesystem and the rest
 * take no identity, and never will: engine/System does not know who is asking.
 * The application service, console command or MCP tool that does know calls
 * authorize() first, then the manager. That split is what lets the same
 * manager serve a CLI run by root and an MCP tool run for a user.
 *
 * Every capability checked must have been declared (see SystemCapability::
 * declare()), or the authorizer throws: a silent "no" would be debugged as
 * a policy problem by somebody who is not the person who forgot to declare it.
 */
final class SystemAuthorizer
{
    /**
     * @param ?SystemAudit $audit when given, authorize() records system.authorization.granted and
     *                            .denied, with the identity; allows() is a question and records nothing
     */
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly ?SystemAudit $audit = null,
    ) {}

    public function allows(Identity $identity, SystemCapability $capability, ?string $target = null): bool
    {
        return $this->authorizer->allows($identity, $capability->value, new SystemOperation($capability, $target));
    }

    /** @throws SystemAuthorizationException */
    public function authorize(Identity $identity, SystemCapability $capability, ?string $target = null): void
    {
        $operation = new SystemOperation($capability, $target);
        $allowed = $this->authorizer->allows($identity, $capability->value, $operation);

        // The principal is recorded here, where it is known. The managers that
        // act next never know who asked; the log's request id ties the two.
        $this->audit?->record(
            $allowed ? 'system.authorization.granted' : 'system.authorization.denied',
            $allowed ? AuditOutcome::Succeeded : AuditOutcome::Refused,
            $target,
            ['capability' => $capability->value, 'principal' => $identity->isGuest() ? null : $identity->id, 'guest' => $identity->isGuest()],
        );

        if (!$allowed) {
            throw SystemAuthorizationException::refused($operation, $identity->isGuest());
        }
    }
}
