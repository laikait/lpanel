<?php

declare(strict_types=1);

namespace App\Engine\Auth;

/**
 * One thing somebody may be allowed to do, named.
 *
 * `invoice.void`, `customer.create`, `report.finance.read`. Dotted, lowercase,
 * and read left to right from the broadest term to the narrowest -- which is
 * what makes `invoice.*` mean something useful rather than being a wildcard
 * over an arbitrary naming scheme.
 *
 * **A capability is a noun, not a question.** The whole authorization model
 * follows from that. A role grants a set of these; a check asks whether the
 * set contains one. There is no closure to evaluate, nothing to register
 * against a magic string, and no class discovered by a naming convention --
 * which is what the specification means by not copying Gates and Policies.
 *
 * The consequence, stated plainly: a set cannot answer "may this user edit THIS
 * invoice". That question is about data, not about the user, and it is answered
 * where the data is -- see Authorizer, which lets a module narrow a decision
 * through a filter and deliberately does not let one widen it.
 *
 * **Wildcards are for granting, never for asking.** `invoice.*` is a sensible
 * thing for a role to hold and a meaningless thing to check, because the
 * question "is the user allowed to do anything at all under invoice" has no
 * answer an application can act on.
 */
final class Capability
{
    /** What a check may ask for: dotted segments, no wildcard. */
    public const PATTERN = '/^[a-z][a-z0-9]*([._-][a-z0-9]+)*$/';

    /** What a role may hold: the above, or a prefix ending in .*, or * alone. */
    public const GRANT_PATTERN = '/^(\*|[a-z][a-z0-9]*([._-][a-z0-9]+)*(\.\*)?)$/';

    public const ALL = '*';

    public const SEPARATOR = '.';

    private function __construct(public readonly string $name) {}

    /**
     * A capability to check for.
     *
     * @throws AuthException when the name is not one that can be asked about
     */
    public static function of(string $name): self
    {
        if (\preg_match(self::PATTERN, $name) !== 1) {
            throw AuthException::unaskableCapability($name);
        }

        return new self($name);
    }

    public static function isAskable(string $name): bool
    {
        return \preg_match(self::PATTERN, $name) === 1;
    }

    public static function isGrantable(string $name): bool
    {
        return \preg_match(self::GRANT_PATTERN, $name) === 1;
    }

    /**
     * Whether a grant covers this capability.
     *
     * Three cases, and no others. `*` covers everything; an exact name covers
     * itself; `prefix.*` covers anything beneath that prefix. Note the dot in
     * the third: `invoice.*` covers `invoice.void` and does NOT cover
     * `invoices.void`, because prefix matching without the separator is how a
     * new capability quietly falls inside somebody's old role.
     */
    public function coveredBy(string $grant): bool
    {
        if ($grant === self::ALL) {
            return true;
        }

        if ($grant === $this->name) {
            return true;
        }

        if (!\str_ends_with($grant, self::SEPARATOR . self::ALL)) {
            return false;
        }

        return \str_starts_with($this->name, \substr($grant, 0, -1));
    }

    /**
     * Whether any of these grants covers it.
     *
     * @param iterable<string> $grants
     */
    public function coveredByAny(iterable $grants): bool
    {
        foreach ($grants as $grant) {
            if ($this->coveredBy($grant)) {
                return true;
            }
        }

        return false;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
