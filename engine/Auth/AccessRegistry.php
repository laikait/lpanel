<?php

declare(strict_types=1);

namespace App\Engine\Auth;

/**
 * Every capability and role the application declares, in one place.
 *
 * Filled at boot by modules, the same way routes and commands are, and frozen
 * from then on. Two jobs:
 *
 * 1. Answer "which capabilities does this set of roles grant", flattened and
 *    memoised, so a check is a set lookup rather than a graph walk.
 * 2. Refuse a declaration that cannot be right -- a duplicate, an inherited
 *    role nobody defined, a cycle -- at boot, where whoever wrote it is still
 *    looking.
 *
 * **Flattening happens once, on first use, not at declaration time.** A role
 * may inherit one that a later module declares, so nothing can be resolved
 * until every module has spoken. The same reason the schedule registry is a
 * list and checks itself afterwards.
 */
final class AccessRegistry
{
    /** @var array<string, Permission> */
    private array $permissions = [];

    /** @var array<string, Role> */
    private array $roles = [];

    /** @var array<string, list<string>> flattened grants, by role name */
    private array $resolved = [];

    public function declarePermission(Permission $permission): void
    {
        $existing = $this->permissions[$permission->capability] ?? null;

        if ($existing !== null) {
            throw AuthException::duplicatePermission(
                $permission->capability,
                $permission->module,
                $existing->module,
            );
        }

        $this->permissions[$permission->capability] = $permission;
    }

    public function declareRole(Role $role): void
    {
        $existing = $this->roles[$role->name] ?? null;

        if ($existing !== null) {
            throw AuthException::duplicateRole($role->name, $role->module, $existing->module);
        }

        $this->roles[$role->name] = $role;
        $this->resolved = [];
    }

    public function hasPermission(string $capability): bool
    {
        return isset($this->permissions[$capability]);
    }

    public function hasRole(string $name): bool
    {
        return isset($this->roles[$name]);
    }

    public function role(string $name): ?Role
    {
        return $this->roles[$name] ?? null;
    }

    /** @return array<string, Permission> keyed by capability, sorted */
    public function permissions(): array
    {
        $permissions = $this->permissions;
        \ksort($permissions);

        return $permissions;
    }

    /** @return array<string, Role> keyed by name, sorted */
    public function roles(): array
    {
        $roles = $this->roles;
        \ksort($roles);

        return $roles;
    }

    /**
     * Everything these roles grant, inheritance included.
     *
     * An unknown role contributes nothing rather than throwing. That is the
     * safe direction and the realistic one: role names usually come from
     * storage, so a row naming a role that a since-removed module used to
     * declare must mean "grants nothing", not "the site is down".
     *
     * @param list<string> $roles
     *
     * @return list<string>
     */
    public function grantsFor(array $roles): array
    {
        $grants = [];

        foreach ($roles as $role) {
            foreach ($this->flatten($role) as $grant) {
                $grants[$grant] = true;
            }
        }

        return \array_keys($grants);
    }

    /**
     * Check every declaration now that all of them have been made.
     *
     * Called once, after the last module has registered. Everything it can
     * catch is something that would otherwise be discovered by a user being
     * wrongly refused -- or, worse, by nobody.
     */
    public function assertConsistent(): void
    {
        foreach ($this->roles as $role) {
            foreach ($role->inherits as $parent) {
                if (!isset($this->roles[$parent])) {
                    throw AuthException::unknownRole($parent, $role->name);
                }
            }

            // Forces the walk, which is what detects a cycle.
            $this->flatten($role->name);
        }
    }

    /**
     * @param list<string> $seen the chain so far, for the error message
     *
     * @return list<string>
     */
    private function flatten(string $name, array $seen = []): array
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        if (\in_array($name, $seen, true)) {
            throw AuthException::circularRole([...$seen, $name]);
        }

        $role = $this->roles[$name] ?? null;

        if ($role === null) {
            return [];
        }

        $grants = [];

        foreach ($role->capabilities as $capability) {
            $grants[$capability] = true;
        }

        foreach ($role->inherits as $parent) {
            foreach ($this->flatten($parent, [...$seen, $name]) as $grant) {
                $grants[$grant] = true;
            }
        }

        $flattened = \array_keys($grants);

        // Memoised only once the whole subtree resolved without a cycle.
        $this->resolved[$name] = $flattened;

        return $flattened;
    }
}
