<?php

declare(strict_types=1);

namespace App\Engine\Module;

/**
 * The two kinds of module, and the order they load in.
 *
 * There is one shared module -- modules/Shared -- and every other directory
 * under modules/ is an ordinary module, named whatever its author likes. The
 * rank is what makes Shared genuinely shared: it always registers before
 * anything that might depend on it, and it may depend on nothing.
 */
enum ModuleKind: string
{
    case Shared = 'shared';
    case Module = 'module';

    public function rank(): int
    {
        return match ($this) {
            self::Shared => 0,
            self::Module => 1,
        };
    }

    /** The kind a module of this id is. */
    public static function of(string $id): self
    {
        return $id === ModuleDefinition::SHARED ? self::Shared : self::Module;
    }
}
