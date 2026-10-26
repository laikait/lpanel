<?php

declare(strict_types=1);

namespace App\Engine\Auth;

/**
 * A named set of capabilities, and optionally other roles.
 *
 * The whole of the grant side of the model. An identity holds role names; a
 * role holds capability names; a check is set membership. Nothing here
 * evaluates anything.
 *
 * **Inheritance is included and its cost is acknowledged.** An ERP without it
 * ends up with "accountant" and "senior accountant" listing forty capabilities
 * twice, and the day somebody adds the forty-first they add it to one of them.
 * What it buys in duplication it costs in indirection -- which is why
 * AccessRegistry resolves a role to a flat set once, refuses a cycle by name,
 * and `auth:access` prints the flattened result rather than the declaration.
 */
final class Role
{
    /** The same shape as a capability: lowercase, dotted or dashed, no wildcard. */
    public const PATTERN = '/^[a-z][a-z0-9]*([._-][a-z0-9]+)*$/';

    /**
     * @param list<string> $capabilities
     * @param list<string> $inherits
     */
    public function __construct(
        public readonly string $name,
        public readonly array $capabilities = [],
        public readonly array $inherits = [],
        public readonly string $description = '',
        public readonly string $module = '',
    ) {
        if (\preg_match(self::PATTERN, $name) !== 1) {
            throw AuthException::unusableRoleName($name);
        }

        foreach ($capabilities as $capability) {
            if (!Capability::isGrantable($capability)) {
                throw AuthException::ungrantableCapability($capability, $name);
            }
        }
    }
}
