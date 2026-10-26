<?php

declare(strict_types=1);

namespace App\Engine\MCP;

use App\Engine\Auth\Authorizer;

/**
 * Whether the caller in a context may use a capability at all.
 *
 * **Anonymous callers are refused** unless the application says otherwise.
 * An MCP client is a program acting for somebody, and "somebody" is the
 * question authorization answers; a server that let anyone list and call its
 * tools would be the generic interface the plan forbids.
 *
 * **A capability with a permission** needs the identity to hold it, through
 * the ordinary Authorizer: roles grant it, and a module's
 * `authorization.decision` listener -- which receives the Capability as the
 * subject, so it can tell `invoice.void` the tool from anything else -- may
 * narrow it and never widen it. A capability without one needs only an
 * authenticated caller; anything that changes data or reads what not every
 * user may see should name one.
 *
 * **What is refused does not exist, to the caller.** Listings leave it out,
 * and calling it answers exactly as an unknown name does. A client cannot map
 * what it is not allowed to use.
 */
final class McpAuthorizer
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly bool $allowGuests = false,
    ) {}

    public function allows(Capability $capability, McpContext $context): bool
    {
        if ($context->identity->isGuest() && !$this->allowGuests) {
            return false;
        }

        if ($capability->permission === null) {
            return true;
        }

        return $this->authorizer->allows($context->identity, $capability->permission, $capability);
    }

    /**
     * @param list<Capability> $capabilities
     *
     * @return list<Capability> the ones this caller may see, in the same order
     */
    public function visible(array $capabilities, McpContext $context): array
    {
        return \array_values(\array_filter($capabilities, fn(Capability $capability): bool => $this->allows($capability, $context)));
    }
}
