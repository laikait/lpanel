<?php

declare(strict_types=1);

namespace App\Engine\Auth;

/**
 * Who the current request is, as far as the framework is concerned.
 *
 * **Deliberately not a User model.** The framework needs four things -- an id,
 * something to call the subject in a log line, the roles it holds, and whatever
 * the application wants to carry alongside -- and an ERP's User has forty
 * fields, a repository behind it and a relation to every other table. Coupling
 * the request lifecycle to that would mean every authenticated request loads a
 * row it mostly does not use, and that the framework has an opinion about what
 * a user is.
 *
 * So: the application's UserProvider turns its own notion of a user into one of
 * these, and the handler that actually needs the model loads it from the id.
 *
 * **Immutable, and a guest is an Identity too.** There is no null case to
 * forget: an unauthenticated request has an Identity whose id is empty and
 * whose roles are the guest ones. Code that asks "can this request do X" gets
 * the same answer shape either way, and the alternative -- a nullable current
 * user -- is the most reliable source of "call to a member function on null"
 * in any framework that has one.
 */
final class Identity
{
    public const GUEST_ID = '';

    /**
     * @param list<string>         $roles
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name = '',
        public readonly array $roles = [],
        public readonly array $attributes = [],
    ) {}

    /**
     * Nobody, which is still somebody the framework can reason about.
     *
     * @param list<string> $roles the roles an unauthenticated request holds,
     *                            which is usually none and is occasionally
     *                            "public" in an application that grants
     *                            capabilities to anonymous visitors
     */
    public static function guest(array $roles = []): self
    {
        return new self(self::GUEST_ID, 'guest', $roles);
    }

    public function isGuest(): bool
    {
        return $this->id === self::GUEST_ID;
    }

    /**
     * Whether this is a particular account.
     *
     * hash_equals rather than ===, because an id is compared against one that
     * arrived from outside often enough that the habit is worth keeping.
     */
    public function is(string $id): bool
    {
        return $id !== self::GUEST_ID && \hash_equals($this->id, $id);
    }

    public function hasRole(string $role): bool
    {
        return \in_array($role, $this->roles, true);
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** @param list<string> $roles */
    public function withRoles(array $roles): self
    {
        return new self($this->id, $this->name, $roles, $this->attributes);
    }

    /**
     * What goes in a log line, and nothing else.
     *
     * Not the attributes: an application is free to put an email address or a
     * tenant id in there, and a subject line that quietly grows personal data
     * is how a log file becomes something that has to be handled carefully.
     *
     * @return array{id: string, name: string, roles: list<string>}
     */
    public function forLog(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'roles' => $this->roles];
    }
}
