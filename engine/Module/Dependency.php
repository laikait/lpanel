<?php

declare(strict_types=1);

namespace App\Engine\Module;

/**
 * One module saying it needs another.
 *
 * **By id.** A module's id is its directory name under modules/ -- `Billing`,
 * `Shared` -- so the name written here is the name on disk.
 *
 * **Optional is not the same as absent.** An optional dependency that is
 * installed and enabled is checked exactly like a required one -- its version
 * must fit, and the module registers after it. What changes is only what
 * happens when it is not there: nothing, rather than a refusal to boot. That is
 * what makes an optional integration safe to write: the ordering guarantee holds
 * whenever the other module exists.
 */
final class Dependency
{
    /**
     * A module name: see ModuleDefinition::NAME_PATTERN.
     *
     * The rule is not arbitrary: a module's directory name is a namespace
     * segment (the `Billing` in the module's namespace), so a name PHP could
     * not use as one is not a module that can exist.
     */
    public const ID_PATTERN = ModuleDefinition::NAME_PATTERN;

    public function __construct(
        public readonly string $id,
        public readonly VersionConstraint $constraint,
        public readonly bool $optional = false,
    ) {}

    public static function isValidId(string $id): bool
    {
        return \preg_match(self::ID_PATTERN, $id) === 1;
    }

    /** The kind of module this points at, read from the id itself. */
    public function kind(): ModuleKind
    {
        return ModuleKind::of($this->id);
    }

    /** `Billing ^1.0`, `Crm ^2.0 (optional)`, or just the id when any version will do. */
    public function describe(): string
    {
        return $this->id
            . ($this->constraint->isAny() ? '' : ' ' . $this->constraint)
            . ($this->optional ? ' (optional)' : '');
    }
}
