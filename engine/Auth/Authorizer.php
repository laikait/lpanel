<?php

declare(strict_types=1);

namespace App\Engine\Auth;

use App\Engine\Filter\FilterEngine;
use App\Engine\Http\HttpException;

/**
 * Whether an identity may do a named thing.
 *
 * **The answer is set membership, and then it can only get smaller.** That one
 * sentence is the whole authorization model, and it is what the specification
 * means by not copying Gates and Policies.
 *
 * Laravel's Gate is a registry of closures keyed by ability strings: the
 * closure IS the decision, it can say yes to anything, and finding out what the
 * application can express means reading every one of them. Policies add
 * discovery by class-name convention on top. Both are how "who can do what"
 * stops being answerable without running the code.
 *
 * Here:
 *
 * 1. Roles grant capabilities. That is data, it is declared, and `auth:access`
 *    prints all of it.
 * 2. A check asks whether the identity's flattened grants cover the capability.
 *    No closure runs.
 * 3. A module may then **narrow** the decision through the
 *    `authorization.decision` filter -- and may not widen it.
 *
 * **Step 3 only subtracts, and that is the interesting rule.** The question a
 * set cannot answer is "may this user edit THIS invoice", because ownership is
 * a fact about data rather than about the user. So the module that owns the
 * data adds a listener that turns the answer off when the row is not theirs.
 * Granting stays declarative and greppable; refusing stays contextual. A filter
 * that could also grant would be a Gate with extra steps -- the same "some
 * closure somewhere says yes" that makes an access model unauditable -- and the
 * asymmetry is what keeps the declarations honest.
 *
 * Anything other than an explicit `true` from the chain is a refusal, so a
 * listener that returns nothing fails closed.
 */
final class Authorizer
{
    public const DECISION_FILTER = 'authorization.decision';

    /** @var array<string, list<string>> flattened grants, keyed by the role list */
    private array $grants = [];

    public function __construct(
        private readonly AccessRegistry $access,
        private readonly ?FilterEngine $filters = null,
    ) {}

    /**
     * Whether this identity may do this, optionally to this thing.
     *
     * @param mixed $subject whatever the decision is about -- a model, an id,
     *                       an array. The framework passes it to the filter
     *                       chain and has no opinion about what it is, because
     *                       only the module that added the listener knows
     *
     * @throws AuthException when nothing declared the capability. A silent
     *                       "no" would look like a routing bug and be debugged
     *                       as one, by somebody who is not the person who
     *                       forgot the declaration
     */
    public function allows(Identity $identity, string $capability, mixed $subject = null): bool
    {
        $required = Capability::of($capability);

        if (!$this->access->hasPermission($capability)) {
            throw AuthException::undeclaredCapability($capability, 'a check at runtime');
        }

        if (!$required->coveredByAny($this->grantsFor($identity))) {
            // Not filtered. A filter cannot grant, so there is nothing for it
            // to say here, and running it would invite somebody to try.
            return false;
        }

        if ($this->filters === null) {
            return true;
        }

        return $this->filters->apply(self::DECISION_FILTER, true, $required, $identity, $subject) === true;
    }

    public function denies(Identity $identity, string $capability, mixed $subject = null): bool
    {
        return !$this->allows($identity, $capability, $subject);
    }

    /**
     * Allow, or refuse the request outright.
     *
     * 401 for a guest and 403 for somebody known, because the two mean
     * different things to a client: one is worth retrying with credentials and
     * the other never is.
     */
    public function authorize(Identity $identity, string $capability, mixed $subject = null): void
    {
        if ($this->allows($identity, $capability, $subject)) {
            return;
        }

        throw $identity->isGuest()
            ? HttpException::unauthorized()
            : HttpException::forbidden(\sprintf('This account does not have "%s".', $capability));
    }

    /**
     * Everything this identity is granted, flattened.
     *
     * Useful to hand a UI so it can hide what it would only refuse, and
     * memoised per role set because a page rendering a menu asks a great many
     * times for the same answer.
     *
     * @return list<string>
     */
    public function grantsFor(Identity $identity): array
    {
        $key = \implode("\0", $identity->roles);

        return $this->grants[$key] ??= $this->access->grantsFor($identity->roles);
    }

    /**
     * Every declared capability this identity holds, expanded from wildcards.
     *
     * grantsFor() answers with what the roles say, which may be `invoice.*`.
     * This answers with the capabilities that actually exist underneath it --
     * the list a permissions screen wants to tick.
     *
     * @return list<string>
     */
    public function capabilitiesFor(Identity $identity): array
    {
        $grants = $this->grantsFor($identity);
        $held = [];

        foreach ($this->access->permissions() as $capability => $_) {
            if (Capability::of($capability)->coveredByAny($grants)) {
                $held[] = $capability;
            }
        }

        return $held;
    }
}
