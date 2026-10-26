<?php

declare(strict_types=1);

namespace App\Engine\Module;

use App\Engine\Error\FrameworkException;

final class ModuleException extends FrameworkException
{
    public static function entryMustReturnClosure(string $id, string $path, string $actual): self
    {
        return new self(\sprintf(
            'Module "%s" is invalid: %s returned %s. A module.php must return a closure taking a ModuleContext:'
            . "\n\n    return static function (ModuleContext \$module): void { ... };\n",
            $id,
            $path,
            $actual,
        ));
    }

    public static function duplicateId(string $id, string $existingPath, string $newPath): self
    {
        return new self(\sprintf(
            'Two modules claim the id "%s": %s and %s. A module\'s id is its directory name, so two '
            . 'directories of the same name in different modules.paths roots cannot both be modules.',
            $id,
            $existingPath,
            $newPath,
        ));
    }

    public static function invalidModuleName(string $directory, string $path): self
    {
        return new self(\sprintf(
            'The module at %s is in a directory called "%s", which cannot be a module name. A module is '
            . 'named after its directory, and the name is also its PHP namespace segment and its '
            . 'configuration namespace, so it starts with a capital letter and holds only letters, '
            . 'digits and underscores: Billing, Stripe_Gateway, Crm2.',
            $path,
            $directory,
        ));
    }

    /**
     * The message names the module, the method and the stage, because "you
     * cannot do that here" is useless without all three.
     */
    public static function wrongStage(string $id, string $method, ModuleStage $stage, string $allowed): self
    {
        return new self(\sprintf(
            'Module "%s" called %s() during the %s stage, which is not allowed. %s',
            $id,
            $method,
            $stage->value,
            $allowed,
        ));
    }

    public static function assetFilterNeverRuns(string $id): self
    {
        return new self(\sprintf(
            'Module "%s" attached a filter to asset.response, which no module listener ever receives. '
            . 'Asset requests are answered before modules load, so that /assets/... initialises nothing, '
            . 'and in production the web server usually answers them without PHP at all. Headers every '
            . 'asset needs belong in the web server configuration; access control belongs on a route '
            . 'that serves the file itself.',
            $id,
        ));
    }

    // ---- versions and declarations ----------------------------------------

    public static function invalidVersion(string $version, string $module = ''): self
    {
        return new self(\sprintf(
            '%s version "%s" cannot be compared. Write MAJOR.MINOR.PATCH, e.g. "1.4.0" -- no "v" '
            . 'prefix, no pre-release suffix, no leading zeros. Other modules check their dependencies '
            . 'against this, so a version that cannot be compared is a check that cannot be made.',
            $module === '' ? 'The' : \sprintf('Module "%s" declares', $module),
            $version,
        ));
    }

    public static function invalidConstraint(string $constraint, string $reason, string $module = ''): self
    {
        return new self(\sprintf(
            '%sthe version constraint "%s" cannot be read: %s. Accepted forms are *, 1.2.3, ^1.2, ~1.2, '
            . '>=1.2 <2.0 and alternatives joined with ||.',
            $module === '' ? '' : \sprintf('In module "%s", ', $module),
            $constraint,
            $reason,
        ));
    }

    public static function invalidDependencyId(string $module, string $id): self
    {
        return new self(\sprintf(
            'Module "%s" depends on "%s", which is not a module id. A module\'s id is its directory '
            . 'name under modules/: "Shared", "Billing", "Stripe".',
            $module,
            $id,
        ));
    }

    public static function duplicateDependency(string $module, string $id): self
    {
        return new self(\sprintf(
            'Module "%s" declares its dependency on "%s" twice. Say it once, with one constraint -- two '
            . 'declarations would have to be merged, and "which one wins" is not a question a module '
            . 'declaration should leave open.',
            $module,
            $id,
        ));
    }

    // ---- disabling --------------------------------------------------------

    /** @param list<string> $installed */
    public static function unknownDisabledModule(string $id, array $installed): self
    {
        return new self(\sprintf(
            'modules.disabled names "%s", which is not an installed module. Installed: %s. A typo here would '
            . 'otherwise leave the module running while the configuration says it is off.',
            $id,
            $installed === [] ? 'none' : \implode(', ', $installed),
        ));
    }

    public static function sharedCannotBeDisabled(): self
    {
        return new self(
            'The shared module cannot be disabled. Every other module may rely on it without declaring '
            . 'so -- it always registers first, which is what makes it shared -- so switching it off would '
            . 'break modules that never said they needed it.',
        );
    }

    // ---- resolution -------------------------------------------------------

    public static function missingDependency(string $module, Dependency $dependency, ?string $suggestion = null): self
    {
        return new self(\sprintf(
            'Module "%s" requires "%s", which is not installed.%s Install it, or declare the dependency '
            . 'with optionally() if the module can work without it.',
            $module,
            $dependency->describe(),
            $suggestion === null ? '' : \sprintf(' Did you mean "%s"?', $suggestion),
        ));
    }

    public static function disabledDependency(string $module, Dependency $dependency): self
    {
        return new self(\sprintf(
            'Module "%s" requires "%s", which is installed but disabled in modules.disabled. Enable it, or '
            . 'disable "%s" as well.',
            $module,
            $dependency->id,
            $module,
        ));
    }

    public static function versionConflict(string $module, Dependency $dependency, string $installed, bool $declared): self
    {
        return new self(\sprintf(
            'Module "%s" requires "%s %s", but the installed "%s" is %s. Either this module needs updating '
            . 'to work with that version, or the constraint is wrong.',
            $module,
            $dependency->id,
            $dependency->constraint,
            $dependency->id,
            $declared ? $installed : 'declares no version at all (it counts as 0.0.0) -- add ->version() to its module.php',
        ));
    }

    public static function dependencyAgainstKind(string $module, ModuleKind $kind, Dependency $dependency): self
    {
        return new self(\sprintf(
            'Module "%s" depends on "%s", but Shared loads before every other module and may depend on '
            . 'none of them. That order is what lets every module rely on Shared without declaring it. '
            . 'If Shared needs something, it belongs in Shared.',
            $module,
            $dependency->id,
        ));
    }

    /** @param list<string> $chain */
    public static function circularDependency(array $chain): self
    {
        return new self(\sprintf(
            'Modules depend on each other in a circle: %s. There is no order in which each registers after '
            . 'what it needs, and optional dependencies count, because they order registration too. Usually '
            . 'one side is only reacting to the other -- listening for a hook, say -- and that side can drop '
            . 'its declaration: a listener on a hook nothing fires is simply never called.',
            \implode(' -> ', $chain),
        ));
    }

    public static function pathEscapesRoot(string $path, string $root): self
    {
        return new self(\sprintf(
            'Refusing to load "%s": it resolves outside the configured module root "%s".',
            $path,
            $root,
        ));
    }
}
