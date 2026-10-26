<?php

declare(strict_types=1);

namespace App\Engine\Auth;

/**
 * A capability somebody has declared, with a sentence saying what it means.
 *
 * The difference between this and Capability is the difference between a name
 * and a definition. A capability is any dotted string that parses; a permission
 * is one that a module said it would honour.
 *
 * **Declaring is what makes the model legible.** In an ERP, "which permissions
 * exist" is a question somebody has to answer -- for an audit, for a role
 * screen, for the person deciding what a new starter gets -- and the usual
 * answer is to grep the codebase for capability strings and hope. Here the
 * module that enforces a capability declares it, `auth:access` prints the list,
 * and a route asking for one that nobody declared **fails at boot** rather than
 * at the first request from the one user whose role would have allowed it.
 *
 * The cost is one line per capability, in the module that owns it, next to the
 * routes and commands it already declares.
 */
final class Permission
{
    public function __construct(
        public readonly string $capability,
        public readonly string $description = '',
        public readonly string $module = '',
    ) {
        if (!Capability::isAskable($capability)) {
            throw AuthException::unaskableCapability($capability);
        }
    }
}
