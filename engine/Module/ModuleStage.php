<?php

declare(strict_types=1);

namespace App\Engine\Module;

/**
 * Where a module is in its lifecycle.
 *
 * The stage is not decoration: ModuleContext checks it on every call, so an
 * illegal operation fails at the line that attempted it, naming the module,
 * rather than surfacing as a confusing error somewhere downstream.
 */
enum ModuleStage: string
{
    case Discovered = 'discovered';
    case Loading = 'loading';
    // Between Load and Register: every declaration is known and none has taken
    // effect, which is the only moment dependencies can be checked as a whole.
    case Resolving = 'resolving';
    case Registering = 'registering';
    case Booting = 'booting';
    case Ready = 'ready';
}
